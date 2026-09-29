<?php

declare(strict_types=1);

namespace OCI\Admin\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * The readings behind the public status page, and the incidents that explain
 * them.
 *
 * The scheduler takes one reading every five minutes. Each is counted into a
 * per-day, per-component row, so ninety days of history stays small and the
 * page can show a bar per day without asking for anything at request time.
 *
 * A planned software update is an incident of kind `update`. It is deliberately
 * NOT downtime: rolling out a new version takes the service away for a couple
 * of minutes by design, which is not the same as the service failing. Updates
 * never colour a day red and never count against uptime; they are listed so a
 * customer can see what the interruption was.
 */
final class StatusHistoryService
{
    /** Minutes one reading stands for — the scheduler cycle. */
    public const SAMPLE_MINUTES = 5;

    public const KIND_UPDATE = 'update';

    public const KIND_DEGRADED = 'degraded';

    public const KIND_OUTAGE = 'outage';

    private const HISTORY_DAYS = 90;

    public function __construct(
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Count one reading for every component and for the service as a whole.
     *
     * @param list<array<string, mixed>> $components
     */
    public function record(array $components, string $overall, ?string $day = null): void
    {
        $day ??= date('Y-m-d');

        $rows = [['component' => 'overall', 'status' => $overall]];
        foreach ($components as $component) {
            $rows[] = ['component' => (string) $component['key'], 'status' => (string) $component['status']];
        }

        foreach ($rows as $row) {
            $column = match ($row['status']) {
                StatusReportService::OUTAGE => 'outage',
                StatusReportService::DEGRADED => 'degraded',
                default => 'operational',
            };

            try {
                $this->db->executeStatement(
                    "INSERT INTO oci_status_days (day, component, samples, {$column})
                     VALUES (:day, :component, 1, 1)
                     ON DUPLICATE KEY UPDATE samples = samples + 1, {$column} = {$column} + 1",
                    ['day' => $day, 'component' => $row['component']],
                );
            } catch (\Throwable $e) {
                $this->logger->warning('Status reading not recorded: ' . $e->getMessage());

                return;
            }
        }
    }

    /**
     * Ninety days of readings for the whole service, oldest first.
     *
     * A day nobody measured is reported as `no_data` rather than green: the
     * page says when continuous monitoring began instead of implying uptime
     * it never saw.
     *
     * @return list<array<string, mixed>>
     */
    public function history(?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $rows = [];

        try {
            $rows = $this->db->fetchAllAssociative(
                'SELECT day, samples, operational, degraded, outage
                   FROM oci_status_days
                  WHERE component = :component AND day > DATE_SUB(:today, INTERVAL ' . self::HISTORY_DAYS . ' DAY)
                  ORDER BY day ASC',
                ['component' => 'overall', 'today' => $today],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Status history unavailable: ' . $e->getMessage());
        }

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[(string) $row['day']] = $row;
        }

        $updatesByDay = $this->updateMinutesByDay($today);

        $history = [];
        for ($i = self::HISTORY_DAYS - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime($today . ' -' . $i . ' days'));
            $row = $byDay[$date] ?? null;

            if ($row === null) {
                $history[] = [
                    'date' => $date,
                    'status' => 'no_data',
                    'outage_minutes' => 0,
                    'degraded_minutes' => 0,
                    'update_minutes' => $updatesByDay[$date] ?? 0,
                    'measured_minutes' => 0,
                ];
                continue;
            }

            $outage = (int) $row['outage'] * self::SAMPLE_MINUTES;
            $degraded = (int) $row['degraded'] * self::SAMPLE_MINUTES;

            $history[] = [
                'date' => $date,
                'status' => self::dayStatus($outage, $degraded),
                'outage_minutes' => $outage,
                'degraded_minutes' => $degraded,
                'update_minutes' => $updatesByDay[$date] ?? 0,
                'measured_minutes' => (int) $row['samples'] * self::SAMPLE_MINUTES,
            ];
        }

        return $history;
    }

    /**
     * What colour a measured day gets.
     *
     * Only a failed reading counts. A planned update is an interruption we
     * chose while shipping a version, so it never reddens a day: it is listed
     * as an incident of its own kind and carries its own minutes.
     */
    public static function dayStatus(int $outageMinutes, int $degradedMinutes): string
    {
        if ($outageMinutes > 0) {
            return 'outage';
        }

        return $degradedMinutes > 0 ? 'degraded' : 'operational';
    }

    /**
     * Published incidents from the same ninety days, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function incidents(?string $today = null): array
    {
        $today ??= date('Y-m-d');

        try {
            $rows = $this->db->fetchAllAssociative(
                'SELECT started_at, ended_at, kind, component, summary, detail
                   FROM oci_status_incidents
                  WHERE is_published = 1 AND started_at > DATE_SUB(:today, INTERVAL ' . self::HISTORY_DAYS . ' DAY)
                  ORDER BY started_at DESC',
                ['today' => $today],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Status incidents unavailable: ' . $e->getMessage());

            return [];
        }

        return array_map(static function (array $row): array {
            $minutes = null;
            if (!empty($row['ended_at'])) {
                $minutes = (int) round((strtotime((string) $row['ended_at']) - strtotime((string) $row['started_at'])) / 60);
            }

            return [
                'started_at' => date('c', (int) strtotime((string) $row['started_at'])),
                'ended_at' => empty($row['ended_at']) ? null : date('c', (int) strtotime((string) $row['ended_at'])),
                'minutes' => $minutes,
                'kind' => (string) $row['kind'],
                'component' => $row['component'] === null ? null : (string) $row['component'],
                'summary' => (string) $row['summary'],
                'detail' => $row['detail'] === null ? null : (string) $row['detail'],
            ];
        }, $rows);
    }

    /**
     * Uptime over the measured part of the window.
     *
     * Planned updates are excluded, the way every status page states uptime:
     * the figure answers "did the service fail", not "was it ever briefly
     * away while shipping a version". Days nobody measured are left out
     * rather than assumed green, and the feed says which date measuring
     * began so the number can be read honestly.
     *
     * @param list<array<string, mixed>> $history
     *
     * @return array<string, mixed>
     */
    public function uptime(array $history): array
    {
        $measured = 0;
        $lost = 0;
        $since = null;

        foreach ($history as $day) {
            if ((int) $day['measured_minutes'] === 0) {
                continue;
            }
            $since ??= (string) $day['date'];
            $measured += (int) $day['measured_minutes'];
            $lost += (int) $day['outage_minutes'];
        }

        return [
            'days' => self::HISTORY_DAYS,
            'percent' => $measured > 0 ? round((($measured - $lost) / $measured) * 100, 3) : null,
            'measured_minutes' => $measured,
            'outage_minutes' => $lost,
            'measuring_since' => $since,
            'excludes_planned_updates' => true,
        ];
    }

    /**
     * Record a planned update, or any other incident, once.
     *
     * `source` + `external_ref` make a backfill safe to re-run: the same
     * deployment never becomes two entries.
     */
    public function recordIncident(
        string $startedAt,
        ?string $endedAt,
        string $kind,
        string $summary,
        ?string $detail = null,
        ?string $component = null,
        string $source = 'manual',
        ?string $externalRef = null,
    ): bool {
        try {
            $affected = $this->db->executeStatement(
                'INSERT INTO oci_status_incidents (started_at, ended_at, kind, component, summary, detail, source, external_ref)
                 VALUES (:started, :ended, :kind, :component, :summary, :detail, :source, :ref)
                 ON DUPLICATE KEY UPDATE ended_at = VALUES(ended_at), summary = VALUES(summary), detail = VALUES(detail)',
                [
                    'started' => $startedAt,
                    'ended' => $endedAt,
                    'kind' => $kind,
                    'component' => $component,
                    'summary' => $summary,
                    'detail' => $detail,
                    'source' => $source,
                    'ref' => $externalRef,
                ],
            );

            return $affected > 0;
        } catch (\Throwable $e) {
            $this->logger->warning('Status incident not recorded: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Minutes of planned update per day, so the page can mark the day
     * without counting it as downtime.
     *
     * @return array<string, int>
     */
    private function updateMinutesByDay(string $today): array
    {
        try {
            $rows = $this->db->fetchAllAssociative(
                'SELECT DATE(started_at) AS day,
                        SUM(GREATEST(TIMESTAMPDIFF(MINUTE, started_at, COALESCE(ended_at, started_at)), 0)) AS minutes
                   FROM oci_status_incidents
                  WHERE is_published = 1 AND kind = :kind AND started_at > DATE_SUB(:today, INTERVAL ' . self::HISTORY_DAYS . ' DAY)
                  GROUP BY DATE(started_at)',
                ['kind' => self::KIND_UPDATE, 'today' => $today],
            );
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['day']] = (int) $row['minutes'];
        }

        return $out;
    }
}
