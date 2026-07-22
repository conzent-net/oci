<?php

declare(strict_types=1);

namespace OCI\Report\Repository;

interface ReportRepositoryInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $reportId): ?array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findBySite(int $siteId, int $limit = 20, int $offset = 0): array;

    public function countBySite(int $siteId): int;

    /**
     * @param array<string, mixed> $data
     */
    public function createReport(array $data): int;

    /**
     * @param array<string, mixed> $data
     */
    public function updateReport(int $reportId, array $data): void;

    public function deleteReport(int $reportId): void;

    // ── Schedules ──────────────────────────────────────

    /**
     * @return array<string, mixed>|null
     */
    public function getSchedule(int $siteId, string $reportType): ?array;

    /**
     * @param array<string, mixed> $data
     */
    public function upsertSchedule(array $data): int;

    /**
     * Get schedules that are due for execution.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDueSchedules(int $limit = 50): array;

    public function updateScheduleAfterRun(int $scheduleId, string $nextRunAt): void;
}
