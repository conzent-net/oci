<?php

declare(strict_types=1);

namespace OCI\Admin\Service;

use Doctrine\DBAL\Connection;
use OCI\Admin\Repository\BackupRepositoryInterface;

/**
 * Assembles everything the Backup & Restore page shows.
 *
 * The banner this feeds is the entire point of the feature: it has to be
 * possible to look at one screen and know whether backups are configured,
 * whether they ran, and whether the result left this machine. Every state
 * below is therefore explicit — "never configured" is a distinct, loud state
 * rather than an empty table that reads as calm.
 */
final class BackupOverviewService
{
    private const SCHEDULE_KEY = 'backup_schedule_hour';
    private const RETENTION_KEY = 'backup_retention_count';
    private const INCLUDE_CONFIG_KEY = 'backup_include_config';

    private const DEFAULT_RETENTION = 7;

    public function __construct(
        private readonly Connection $db,
        private readonly BackupRepositoryInterface $backups,
        private readonly BackupFreshnessService $freshness,
        private readonly BackupDestinationService $destinations,
        private readonly BackupJobService $jobs,
    ) {
    }

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $last = $this->freshness->lastSuccessAt();
        $hour = $this->scheduleHour();
        $maxAgeHours = max(1, (int) ($_ENV['BACKUP_MAX_AGE_HOURS'] ?? 26));
        $inventory = $this->destinations->inventory();

        $ageHours = $last === null ? null : (time() - $last->getTimestamp()) / 3600;

        return [
            'schedule_configured' => $hour !== null,
            'schedule_hour' => $hour,
            'next_run' => $hour === null ? null : $this->nextRun($hour),
            'retention' => $this->retention(),
            'include_config' => $this->includeConfig(),
            'last_success_at' => $last,
            'last_success_age_hours' => $ageHours,
            'max_age_hours' => $maxAgeHours,
            'health' => $this->health($hour !== null, $ageHours, $maxAgeHours),
            'running' => $this->jobs->isRunning(),
            'destinations' => $this->destinationStates(),
            'storage_checked_at' => $inventory['checked_at'],
            'storage_unreachable' => $inventory['unreachable'],
        ];
    }

    /**
     * green  — a backup ran recently
     * amber  — configured and running late, but not yet past the threshold
     * red    — past the threshold, or expected and never seen
     * unset  — nothing configured and nothing ever run; say so plainly
     */
    private function health(bool $scheduled, ?float $ageHours, int $maxAgeHours): string
    {
        if ($ageHours === null) {
            return $scheduled ? 'red' : 'unset';
        }

        if ($ageHours > $maxAgeHours) {
            return 'red';
        }

        return $ageHours > ($maxAgeHours * 0.75) ? 'amber' : 'green';
    }

    /** @return list<array<string, mixed>> */
    public function history(int $limit = 25, int $offset = 0): array
    {
        $rows = $this->backups->recent($limit, $offset);

        // One listing per destination for the whole page, not per row.
        $inventory = $this->destinations->inventory();
        $held = $inventory['items'];
        $unreachable = $inventory['unreachable'];

        foreach ($rows as $i => $row) {
            $rows[$i]['destinations'] = $this->backups->destinationsFor((int) $row['id']);

            // Local and offsite are reported SEPARATELY and both are always
            // computed. They are independent facts — an archive can sit in both
            // places, in one, or in neither — and an earlier version collapsed
            // them into a single either/or that could show "restore from
            // offsite" without ever saying whether the local file was still
            // there. You cannot verify a copy the screen refuses to mention.
            $rows[$i]['stored_local'] = $row['status'] === 'success'
                && ($row['path'] ?? '') !== ''
                && is_file((string) $row['path']);

            $holders = $held[(string) $row['filename']]['destinations'] ?? [];
            $offsite = array_values(array_filter($holders, static fn (string $d): bool => $d !== 'local'));

            $rows[$i]['stored_offsite'] = $offsite;

            // Configured but unlistable. NOT absence — the copy may well be
            // there; we simply could not look.
            $rows[$i]['offsite_unknown'] = array_keys($unreachable);

            // Uploaded once, gone now. Only claimable about a destination we
            // actually reached, or a host being down would read as data loss.
            $missing = [];
            foreach ($rows[$i]['destinations'] as $d) {
                $name = (string) ($d['destination'] ?? 'local');

                if (($d['status'] ?? '') === 'uploaded'
                    && $name !== 'local'
                    && !\in_array($name, $offsite, true)
                    && !isset($unreachable[$name])
                ) {
                    $missing[] = $name;
                }
            }
            $rows[$i]['offsite_missing'] = $missing;

            // Anywhere at all is enough to offer both actions: download
            // retrieves from offsite first when it has to.
            $rows[$i]['downloadable'] = $rows[$i]['stored_local'] || $offsite !== [];
        }

        return $rows;
    }

    public function total(): int
    {
        return $this->backups->countAll();
    }

    /**
     * Re-read every configured destination now, bypassing the cache.
     *
     * The Stored column is only as trustworthy as the operator's ability to
     * force a fresh check, so this exists to be called from a button.
     */
    public function refreshStorage(): void
    {
        $this->destinations->inventory(true);
    }

    /** @return list<array<string, mixed>> */
    private function destinationStates(): array
    {
        $out = [];

        foreach ($this->destinations->all() as $name => $destination) {
            $out[] = [
                'name' => $name,
                'configured' => $destination->isConfigured(),
                // Deliberately NOT a live connection test. Nothing else in
                // this codebase makes a remote call while rendering a GET, and
                // a page that hangs on a dead SFTP host helps nobody. The
                // Test connection button does that on demand.
                'last_result' => $this->lastResultFor($name),
            ];
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function lastResultFor(string $destination): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT d.status, d.error, d.uploaded_at, d.bytes_sent, b.filename
               FROM oci_backup_destinations d
               JOIN oci_backups b ON b.id = d.backup_id
              WHERE d.destination = :d
              ORDER BY d.id DESC
              LIMIT 1',
            ['d' => $destination],
        );

        return $row === false ? null : $row;
    }

    public function scheduleHour(): ?int
    {
        $v = $this->getConfig(self::SCHEDULE_KEY);

        return $v === '' ? null : max(0, min(23, (int) $v));
    }

    public function retention(): int
    {
        $v = $this->getConfig(self::RETENTION_KEY);

        return $v === '' ? self::DEFAULT_RETENTION : max(1, (int) $v);
    }

    public function includeConfig(): bool
    {
        return $this->getConfig(self::INCLUDE_CONFIG_KEY) === '1';
    }

    public function saveSettings(?int $hour, int $retention, bool $includeConfig): void
    {
        $this->setConfig(self::SCHEDULE_KEY, $hour === null ? '' : (string) max(0, min(23, $hour)));
        $this->setConfig(self::RETENTION_KEY, (string) max(1, $retention));
        $this->setConfig(self::INCLUDE_CONFIG_KEY, $includeConfig ? '1' : '0');
    }

    private function nextRun(int $hour): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable();
        $today = $now->setTime($hour, 0);

        return $today > $now ? $today : $today->modify('+1 day');
    }

    private function getConfig(string $key): string
    {
        $value = $this->db->fetchOne(
            "SELECT config_value FROM oci_configuration WHERE scope = 'system' AND config_key = :key",
            ['key' => $key],
        );

        return \is_string($value) ? $value : '';
    }

    private function setConfig(string $key, string $value): void
    {
        $updated = $this->db->executeStatement(
            "UPDATE oci_configuration SET config_value = :val WHERE scope = 'system' AND config_key = :key",
            ['val' => $value, 'key' => $key],
        );

        if ($updated === 0) {
            $this->db->executeStatement(
                "INSERT INTO oci_configuration (scope, scope_id, config_key, config_value) VALUES ('system', NULL, :key, :val)",
                ['key' => $key, 'val' => $value],
            );
        }
    }
}
