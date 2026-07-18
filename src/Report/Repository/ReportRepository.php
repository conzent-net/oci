<?php

declare(strict_types=1);

namespace OCI\Report\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class ReportRepository implements ReportRepositoryInterface
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    public function findById(int $reportId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM oci_reports WHERE id = :id',
            ['id' => $reportId],
            ['id' => ParameterType::INTEGER],
        );

        return $row ?: null;
    }

    public function findBySite(int $siteId, int $limit = 20, int $offset = 0): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT * FROM oci_reports WHERE site_id = :siteId ORDER BY created_at DESC LIMIT :limit OFFSET :offset',
            ['siteId' => $siteId, 'limit' => $limit, 'offset' => $offset],
            ['siteId' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );
    }

    public function countBySite(int $siteId): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM oci_reports WHERE site_id = :siteId',
            ['siteId' => $siteId],
            ['siteId' => ParameterType::INTEGER],
        );
    }

    public function createReport(array $data): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        $this->db->insert('oci_reports', $data);

        return (int) $this->db->lastInsertId();
    }

    public function updateReport(int $reportId, array $data): void
    {
        $data['updated_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->db->update('oci_reports', $data, ['id' => $reportId]);
    }

    public function deleteReport(int $reportId): void
    {
        $this->db->delete('oci_reports', ['id' => $reportId]);
    }

    // ── Schedules ──────────────────────────────────────

    public function getSchedule(int $siteId, string $reportType): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM oci_report_schedules WHERE site_id = :siteId AND report_type = :reportType',
            ['siteId' => $siteId, 'reportType' => $reportType],
            ['siteId' => ParameterType::INTEGER],
        );

        return $row ?: null;
    }

    public function upsertSchedule(array $data): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $existing = $this->getSchedule((int) $data['site_id'], (string) $data['report_type']);

        if ($existing !== null) {
            $data['updated_at'] = $now;
            $this->db->update('oci_report_schedules', $data, ['id' => (int) $existing['id']]);

            return (int) $existing['id'];
        }

        $data['created_at'] = $now;
        $data['updated_at'] = $now;
        $this->db->insert('oci_report_schedules', $data);

        return (int) $this->db->lastInsertId();
    }

    public function getDueSchedules(int $limit = 50): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT s.*, u.email AS user_email
             FROM oci_report_schedules s
             JOIN oci_users u ON u.id = s.user_id
             WHERE s.is_active = 1
               AND s.next_run_at <= NOW()
             ORDER BY s.next_run_at ASC
             LIMIT :limit',
            ['limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );
    }

    public function updateScheduleAfterRun(int $scheduleId, string $nextRunAt): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->db->update('oci_report_schedules', [
            'last_run_at' => $now,
            'next_run_at' => $nextRunAt,
            'updated_at' => $now,
        ], ['id' => $scheduleId]);
    }
}
