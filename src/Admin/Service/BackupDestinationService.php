<?php

declare(strict_types=1);

namespace OCI\Admin\Service;

use OCI\Admin\Repository\BackupRepositoryInterface;
use OCI\Admin\Service\BackupDestination\DestinationInterface;
use Psr\Log\LoggerInterface;

/**
 * Ships a finished archive to every configured destination and records what
 * actually arrived where.
 *
 * The per-destination rows exist because "the backup succeeded" and "the
 * backup is somewhere that survives this machine dying" are different claims,
 * and conflating them is how an install ends up believing it is protected by
 * a copy sitting on the disk it is meant to protect against.
 */
final class BackupDestinationService
{
    /**
     * A FACTORY, not a built list.
     *
     * Destinations carry their configuration, and that configuration is edited
     * at runtime through the admin page. Building them once when the container
     * boots means a long-running worker holds whatever was configured at
     * startup for its entire life — so saving an SFTP destination and then
     * watching backups not arrive, with no error anywhere, because the worker
     * that ships them was constructed before the setting existed.
     *
     * Resolved on every call instead. These are a handful of small value
     * objects; the cost is nothing next to being silently wrong.
     *
     * @var callable(): iterable<DestinationInterface>
     */
    private $factory;

    /** Long enough that a page refresh is free, short enough to stay true. */
    private const INVENTORY_KEY = 'oci:backup:inventory';
    private const INVENTORY_TTL = 300;

    /**
     * @param callable(): iterable<DestinationInterface> $factory
     */
    public function __construct(
        callable $factory,
        private readonly BackupRepositoryInterface $backups,
        private readonly LoggerInterface $logger,
        private readonly ?\Predis\Client $redis = null,
    ) {
        $this->factory = $factory;
    }

    /** @return array<string, DestinationInterface> */
    public function all(): array
    {
        $out = [];

        foreach (($this->factory)() as $d) {
            $out[$d->name()] = $d;
        }

        return $out;
    }

    public function get(string $name): ?DestinationInterface
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * @return array<string, bool> destination name => uploaded
     */
    public function distribute(int $backupId, string $localPath): array
    {
        $results = [];
        $remoteName = basename($localPath);

        foreach ($this->all() as $name => $destination) {
            if (!$destination->isConfigured()) {
                continue;
            }

            $this->backups->recordDestination($backupId, $name, 'pending', null, null, null, null);

            $started = microtime(true);

            try {
                $r = $destination->put($localPath, $remoteName);
            } catch (\Throwable $e) {
                // A destination is not allowed to break the backup. Record and
                // carry on to the next one.
                $r = ['ok' => false, 'remote_path' => null, 'bytes' => null, 'error' => $e->getMessage()];
            }

            $ms = (int) round((microtime(true) - $started) * 1000);

            $this->backups->recordDestination(
                $backupId,
                $name,
                $r['ok'] ? 'uploaded' : 'failed',
                $r['remote_path'] ?? null,
                $r['bytes'] ?? null,
                $ms,
                $r['error'] ?? null,
            );

            $results[$name] = $r['ok'];

            if (!$r['ok']) {
                $this->logger->error('Backup destination failed', [
                    'backup_id' => $backupId,
                    'destination' => $name,
                    'error' => $r['error'] ?? 'unknown',
                ]);
            }
        }

        $this->forgetInventory();

        return $results;
    }

    /**
     * Everything every configured destination actually holds right now, keyed
     * by archive name.
     *
     * Read from the storage itself rather than from oci_backups, because the
     * case that matters most is the one where oci_backups no longer exists —
     * and because an `uploaded` row only records that a transfer once
     * succeeded, not that the file survived whatever pruned it since.
     *
     * `unreachable` is the load-bearing half. A destination that could not be
     * listed contributes NO absence: its archives are unknown, never gone. The
     * page has to be able to say "I could not check" instead of quietly
     * reporting somebody's only offsite copy as lost because a host was down.
     *
     * Cached, because this makes real network round-trips and the page that
     * needs it is a GET. Nothing else in this codebase calls out to a remote
     * host while rendering a page, and a backup screen that hangs on a dead
     * SFTP server helps nobody — so the first render pays for it, the next few
     * minutes do not, and Refresh forces a real check on demand.
     *
     * @return array{
     *     items: array<string, array{name: string, bytes: ?int, modified: ?string, destinations: list<string>}>,
     *     unreachable: array<string, string>,
     *     checked_at: int
     * }
     */
    public function inventory(bool $fresh = false): array
    {
        if (!$fresh) {
            $cached = $this->cachedInventory();

            if ($cached !== null) {
                return $cached;
            }
        }

        $items = [];
        $unreachable = [];

        foreach ($this->all() as $name => $destination) {
            if (!$destination->isConfigured()) {
                continue;
            }

            try {
                $listing = $destination->list();
            } catch (\Throwable $e) {
                $unreachable[$name] = $e->getMessage();

                $this->logger->warning('Could not list a backup destination', [
                    'destination' => $name,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($listing as $item) {
                $key = (string) $item['name'];

                if (!isset($items[$key])) {
                    $items[$key] = $item + ['destinations' => []];
                }

                $items[$key]['destinations'][] = $name;
            }
        }

        krsort($items);

        $result = ['items' => $items, 'unreachable' => $unreachable, 'checked_at' => time()];

        $this->cacheInventory($result);

        return $result;
    }

    /** @return array{items: array<string, mixed>, unreachable: array<string, string>, checked_at: int}|null */
    private function cachedInventory(): ?array
    {
        if ($this->redis === null) {
            return null;
        }

        try {
            $raw = $this->redis->get(self::INVENTORY_KEY);
        } catch (\Throwable) {
            // Redis being down must not take the backup page with it.
            return null;
        }

        if (!\is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return \is_array($decoded) && isset($decoded['items'], $decoded['checked_at']) ? $decoded : null;
    }

    /** @param array<string, mixed> $result */
    private function cacheInventory(array $result): void
    {
        if ($this->redis === null) {
            return;
        }

        try {
            $this->redis->setex(self::INVENTORY_KEY, self::INVENTORY_TTL, (string) json_encode($result));
        } catch (\Throwable) {
            // Best effort — a cache miss is only slower, never wrong.
        }
    }

    /**
     * Drop the cache after anything that changes what storage holds.
     *
     * Without this, taking a backup and immediately opening the page would show
     * the new archive as absent from every destination it was just shipped to,
     * for up to the TTL — the feature reporting its own success as a failure.
     */
    private function forgetInventory(): void
    {
        if ($this->redis === null) {
            return;
        }

        try {
            $this->redis->del([self::INVENTORY_KEY]);
        } catch (\Throwable) {
            // The TTL will catch up on its own.
        }
    }

    /**
     * Bring one archive back to a local path, trying each destination.
     *
     * Local first — no point pulling 30 MB over the network when the file is
     * already on this disk — then the offsite copies, which are the ones that
     * survive the event you are recovering from.
     *
     * Downloads land on a sibling temp file and are renamed into place only
     * once a destination reports success. The caller decides whether to
     * retrieve by asking is_file($localPath), so a half-written archive
     * sitting at that path would make the next attempt skip the download and
     * feed a truncated tar to the restore. A partial file must therefore never
     * be able to occupy the target name — not after a failed transfer, and not
     * after the process is killed mid-download either.
     *
     * @return array{ok: bool, from: ?string, bytes: ?int, error: ?string}
     */
    public function retrieve(string $archiveName, string $localPath): array
    {
        $errors = [];

        foreach ($this->all() as $name => $destination) {
            if (!$destination->isConfigured()) {
                continue;
            }

            $part = $localPath . '.part-' . bin2hex(random_bytes(4));

            try {
                $r = $destination->fetch($archiveName, $part);
            } catch (\Throwable $e) {
                $r = ['ok' => false, 'bytes' => null, 'error' => $e->getMessage()];
            }

            if ($r['ok'] && @rename($part, $localPath)) {
                $this->logger->info('Archive retrieved for restore', [
                    'archive' => $archiveName,
                    'from' => $name,
                    'bytes' => $r['bytes'],
                ]);

                return ['ok' => true, 'from' => $name, 'bytes' => $r['bytes'], 'error' => null];
            }

            if ($r['ok']) {
                $r['error'] = 'Retrieved from ' . $name . ' but could not be moved into place.';
            }

            @unlink($part);

            $errors[] = $name . ': ' . ($r['error'] ?? 'unknown');
        }

        return [
            'ok' => false,
            'from' => null,
            'bytes' => null,
            'error' => $errors === []
                ? 'No destination is configured to retrieve from.'
                : implode('; ', $errors),
        ];
    }

    /** @return array<string, int> destination name => copies removed */
    public function prune(int $keep): array
    {
        $removed = [];

        foreach ($this->all() as $name => $destination) {
            if (!$destination->isConfigured()) {
                continue;
            }

            try {
                $removed[$name] = $destination->prune($keep);
            } catch (\Throwable $e) {
                $this->logger->warning('Backup retention prune failed', [
                    'destination' => $name,
                    'error' => $e->getMessage(),
                ]);
                $removed[$name] = 0;
            }
        }

        $this->forgetInventory();

        return $removed;
    }
}
