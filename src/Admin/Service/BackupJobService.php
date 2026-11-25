<?php

declare(strict_types=1);

namespace OCI\Admin\Service;

use OCI\Admin\Repository\BackupRepositoryInterface;
use Predis\Client as RedisClient;
use Psr\Log\LoggerInterface;

/**
 * Queue wrapper around BackupService, modelled on AccountExportJobService.
 *
 * A backup on a real install takes longer than any sane web request, so the
 * admin page enqueues and polls rather than blocking. Unlike the account
 * export, there is no separate status blob: oci_backups already records every
 * attempt, so the row IS the status and there is only one place to look.
 */
final class BackupJobService
{
    private const QUEUE_KEY = 'oci:backup:queue';

    public function __construct(
        private readonly RedisClient $redis,
        private readonly BackupRepositoryInterface $backups,
        private readonly BackupService $backupService,
        private readonly BackupDestinationService $destinations,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param 'scheduled'|'manual'|'pre_restore' $trigger
     *
     * @return bool false when one is already in flight
     */
    public function request(string $trigger = 'manual', bool $includeConfig = false): bool
    {
        if ($this->isRunning()) {
            return false;
        }

        $this->redis->lpush(self::QUEUE_KEY, [json_encode([
            'trigger' => $trigger,
            'include_config' => $includeConfig,
        ], JSON_THROW_ON_ERROR)]);

        return true;
    }

    /** Called once per iteration of bin/oci queue:work. */
    public function processNext(): bool
    {
        $raw = $this->redis->rpop(self::QUEUE_KEY);
        if (!\is_string($raw) || $raw === '') {
            return false;
        }

        try {
            /** @var array{trigger?: string, include_config?: bool} $job */
            $job = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->logger->warning('Discarded malformed backup job', ['payload' => mb_substr($raw, 0, 200)]);

            return true;
        }

        $trigger = $job['trigger'] ?? 'manual';
        if (!\in_array($trigger, ['scheduled', 'manual', 'pre_restore'], true)) {
            $trigger = 'manual';
        }

        try {
            $result = $this->backupService->create($trigger, (bool) ($job['include_config'] ?? false));
            // Shipping offsite is a separate concern and must not be able to
            // turn a good local backup into a failed one — the destination
            // service records its own per-target rows either way.
            $this->destinations->distribute($result['id'], $result['path']);
        } catch (\Throwable $e) {
            // BackupService already marked the row failed and logged it; the
            // worker loop must keep running.
            $this->logger->error('Backup job failed: ' . $e->getMessage());
        }

        return true;
    }

    /**
     * A run row still open. Bounded by the same staleness sweep the repository
     * applies, so a killed worker cannot wedge this permanently at "running".
     */
    public function isRunning(): bool
    {
        $this->backups->markStaleAsFailed();
        $latest = $this->backups->recent(1);

        return $latest !== [] && ($latest[0]['status'] ?? '') === 'running';
    }

    public function queueDepth(): int
    {
        return (int) $this->redis->llen(self::QUEUE_KEY);
    }
}
