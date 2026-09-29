<?php

declare(strict_types=1);

namespace OCI\Admin\Service;

/**
 * A cross-process "stop touching the database" flag, held as a file.
 *
 * Deliberately a file under var/ rather than Redis or a config row. Redis gets
 * flushed as part of a restore, and the database is the very thing being
 * replaced — a lock living in either would be unreadable at exactly the moment
 * it matters. The same reasoning is why BackupFreshnessService reads a file
 * stamp.
 *
 * Read on every request in Application::handle() BEFORE routing, so the check
 * must stay filesystem-only: no container lookups, no session, no DB.
 */
final class MaintenanceLockService
{
    private const FILENAME = '/var/maintenance.lock';

    public function __construct(private readonly string $basePath)
    {
    }

    public function path(): string
    {
        return $this->basePath . self::FILENAME;
    }

    public function isLocked(): bool
    {
        return is_file($this->path());
    }

    /** @return array{reason: string, since: string, job: string}|null */
    public function read(): ?array
    {
        if (!is_file($this->path())) {
            return null;
        }

        $raw = (string) @file_get_contents($this->path());

        try {
            /** @var array{reason?: string, since?: string, job?: string} $data */
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // A lock we cannot parse is still a lock. Failing open here would
            // let requests through mid-restore, which is the one outcome this
            // class exists to prevent.
            return ['reason' => 'Maintenance in progress', 'since' => '', 'job' => ''];
        }

        return [
            'reason' => (string) ($data['reason'] ?? 'Maintenance in progress'),
            'since' => (string) ($data['since'] ?? ''),
            'job' => (string) ($data['job'] ?? ''),
        ];
    }

    public function acquire(string $reason, string $job = ''): void
    {
        $dir = \dirname($this->path());
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create var/ to hold the maintenance lock.');
        }

        $payload = json_encode([
            'reason' => $reason,
            'since' => gmdate('c'),
            'job' => $job,
        ], JSON_THROW_ON_ERROR);

        if (@file_put_contents($this->path(), $payload) === false) {
            throw new \RuntimeException('Could not write the maintenance lock — refusing to start.');
        }
    }

    public function release(): void
    {
        @unlink($this->path());
    }

    /** Age in seconds, or null when unlocked or the stamp is unreadable. */
    public function heldForSeconds(): ?int
    {
        $lock = $this->read();
        if ($lock === null || $lock['since'] === '') {
            return null;
        }

        $t = strtotime($lock['since']);

        return $t === false ? null : max(0, time() - $t);
    }
}
