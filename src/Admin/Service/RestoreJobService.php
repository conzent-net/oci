<?php

declare(strict_types=1);

namespace OCI\Admin\Service;

use OCI\Admin\Repository\BackupRepositoryInterface;
use Predis\Client as RedisClient;
use Psr\Log\LoggerInterface;

/**
 * Runs a restore in the worker, not in a web request.
 *
 * A restore replaces the database the web process is reading, and a real import
 * outlives any PHP execution limit. It also cannot stop the app the way
 * scripts/restore.sh does, because it is running inside it — so instead the app
 * is put behind a maintenance lock for the duration.
 *
 * Progress lives in var/restore-status.json rather than the database, for the
 * obvious reason that the database is the thing being replaced. The polling
 * endpoint reads only that file.
 *
 * Sequence:
 *   1. pre-restore backup (local only — no network dependency at the most
 *      fragile moment), unless explicitly skipped
 *   2. maintenance lock
 *   3. stage + atomic swap, per database (see RestoreService)
 *   4. migrate, regenerate consent scripts for THIS install's APP_URL
 *   5. release the lock
 */
final class RestoreJobService
{
    private const QUEUE_KEY = 'oci:restore:queue';
    private const STATUS_FILE = '/var/restore-status.json';

    public function __construct(
        private readonly RedisClient $redis,
        private readonly RestoreService $restore,
        private readonly BackupService $backupService,
        private readonly BackupRepositoryInterface $backups,
        private readonly MaintenanceLockService $lock,
        private readonly LoggerInterface $logger,
        private readonly string $basePath,
    ) {
    }

    public function statusPath(): string
    {
        return $this->basePath . self::STATUS_FILE;
    }

    /** @return array<string, mixed>|null */
    public function status(): ?array
    {
        if (!is_file($this->statusPath())) {
            return null;
        }

        try {
            /** @var array<string, mixed> $s */
            $s = json_decode((string) file_get_contents($this->statusPath()), true, 16, JSON_THROW_ON_ERROR);

            return $s;
        } catch (\JsonException) {
            return null;
        }
    }

    public function isRunning(): bool
    {
        $s = $this->status();

        return $s !== null && \in_array($s['status'] ?? '', ['queued', 'running'], true);
    }

    public function queueDepth(): int
    {
        return (int) $this->redis->llen(self::QUEUE_KEY);
    }

    /**
     * @return array{ok: bool, error?: string, job?: string}
     */
    public function request(string $archivePath, int $userId, bool $preBackup = true): array
    {
        if ($this->isRunning()) {
            return ['ok' => false, 'error' => 'A restore is already in progress.'];
        }

        if (!is_file($archivePath)) {
            return ['ok' => false, 'error' => 'Archive not found.'];
        }

        $info = $this->restore->inspect($archivePath);
        if ($info['ok'] !== true) {
            return ['ok' => false, 'error' => (string) ($info['error'] ?? 'Archive could not be read.')];
        }

        $job = bin2hex(random_bytes(5));

        $this->write([
            'job' => $job,
            'status' => 'queued',
            'step' => 'queued',
            'percent' => 0,
            'archive' => basename($archivePath),
            'databases' => $info['databases'],
            'started_at' => gmdate('c'),
            'requested_by' => $userId,
            'error' => null,
        ]);

        $this->redis->lpush(self::QUEUE_KEY, [json_encode([
            'job' => $job,
            'archive' => $archivePath,
            'pre_backup' => $preBackup,
            'user_id' => $userId,
        ], JSON_THROW_ON_ERROR)]);

        return ['ok' => true, 'job' => $job];
    }

    /** Called once per iteration of bin/oci queue:work. */
    public function processNext(): bool
    {
        $raw = $this->redis->rpop(self::QUEUE_KEY);
        if (!\is_string($raw) || $raw === '') {
            return false;
        }

        try {
            /** @var array{job: string, archive: string, pre_backup: bool, user_id: int} $j */
            $j = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->logger->warning('Discarded malformed restore job');

            return true;
        }

        $job = (string) ($j['job'] ?? '');
        $archive = (string) ($j['archive'] ?? '');

        try {
            $this->step($job, 'starting', 1);

            if (($j['pre_backup'] ?? true) === true) {
                $this->step($job, 'taking a pre-restore backup', 2);
                // Local only: a network hop is the last thing wanted at the
                // most fragile moment of the whole operation.
                $this->backupService->create('pre_restore', false);
            }

            $this->lock->acquire('Restoring a backup', $job);
            $this->step($job, 'maintenance mode', 4);

            $result = $this->restore->restore(
                $archive,
                $job,
                fn (string $step, int $pct) => $this->step($job, $step, $pct),
            );

            $this->step($job, 'reconciling', 95);
            $this->reconcile();

            $this->finish($job, $result['databases'], $result['prerestore_suffix']);
            $this->logger->info('Restore complete', ['job' => $job, 'databases' => $result['databases']]);
        } catch (\Throwable $e) {
            $this->fail($job, $e->getMessage());
            $this->restore->cleanupScratch($job);
            $this->logger->error('Restore failed', ['job' => $job, 'error' => $e->getMessage()]);
        } finally {
            $this->lock->release();
        }

        return true;
    }

    /**
     * Called on worker boot. A status file still claiming to be running cannot
     * belong to this process, so it is orphaned — from an interrupted restore.
     *
     * Because RestoreService stages before it swaps, an interruption during the
     * import never touched live data, so this is a clean failure to report
     * rather than damage to repair.
     */
    public function recoverOrphaned(): void
    {
        $s = $this->status();
        if ($s === null) {
            return;
        }

        $status = (string) ($s['status'] ?? '');

        // 'running' always means an interrupted process, because only a worker
        // sets it and this is a fresh one. 'queued' is different: the job may
        // still be sitting in Redis waiting to be picked up, which is not a
        // fault. It is only orphaned if the queue no longer holds anything.
        if ($status === 'queued' && $this->queueDepth() > 0) {
            return;
        }

        if (!\in_array($status, ['queued', 'running'], true)) {
            return;
        }

        $job = (string) ($s['job'] ?? '');
        $this->restore->cleanupScratch($job);
        $this->lock->release();
        $this->fail($job, 'Interrupted — the process running this restore stopped before it finished. No live data was replaced.');
        $this->logger->warning('Recovered an orphaned restore', ['job' => $job]);
    }

    private function reconcile(): void
    {
        // An archive contains its own backup row, captured mid-run, so a
        // restore brings back a row frozen at 'running'. Left alone that row
        // makes BackupJobService believe a backup is permanently in flight and
        // silently refuses every new one. Nothing can legitimately be running
        // here: the pre-restore backup finished before the swap began.
        try {
            $swept = $this->backups->markStaleAsFailed(0);
            if ($swept > 0) {
                $this->logger->info('Cleared backup rows restored mid-run', ['rows' => $swept]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Could not sweep restored backup rows: ' . $e->getMessage());
        }

        $php = \PHP_BINARY;
        $bin = escapeshellarg($this->basePath . '/bin/oci');

        // Migrations first: the archive may predate this checkout.
        exec(sprintf('%s %s migrations:migrate 2>&1', escapeshellarg($php), $bin), $o1, $c1);
        if ($c1 !== 0) {
            $this->logger->warning('Post-restore migrations failed', ['output' => \array_slice($o1, -3)]);
        }

        // Generated bundles embed the APP_URL they were built with. After a
        // restore onto a different server that URL is wrong, which is the whole
        // reason this step is not optional.
        exec(sprintf('%s %s scripts:regenerate 2>&1', escapeshellarg($php), $bin), $o2, $c2);
        if ($c2 !== 0) {
            $this->logger->warning('Post-restore script regeneration failed', ['output' => \array_slice($o2, -3)]);
        }
    }

    private function step(string $job, string $step, int $percent): void
    {
        $s = $this->status() ?? ['job' => $job];
        $s['job'] = $job;
        $s['status'] = 'running';
        $s['step'] = $step;
        $s['percent'] = max(0, min(100, $percent));
        $this->write($s);
    }

    /** @param list<string> $databases */
    private function finish(string $job, array $databases, string $suffix): void
    {
        $s = $this->status() ?? ['job' => $job];
        $this->write($s, [
            'job' => $job,
            'status' => 'done',
            'step' => 'complete',
            'percent' => 100,
            'databases' => $databases,
            'prerestore_suffix' => $suffix,
            'finished_at' => gmdate('c'),
            'error' => null,
        ]);
    }

    private function fail(string $job, string $error): void
    {
        $s = $this->status() ?? ['job' => $job];
        $this->write($s, [
            'job' => $job,
            'status' => 'failed',
            'step' => 'failed',
            'finished_at' => gmdate('c'),
            'error' => mb_substr($error, 0, 2000),
        ]);
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overlay
     */
    private function write(array $base, array $overlay = []): void
    {
        $dir = \dirname($this->statusPath());
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            return;
        }

        $payload = json_encode($overlay + $base, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

        // Atomic replace so a poll never reads a half-written file.
        $tmp = $this->statusPath() . '.tmp';
        if (@file_put_contents($tmp, $payload) !== false) {
            @rename($tmp, $this->statusPath());
        }
    }
}
