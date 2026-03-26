<?php

declare(strict_types=1);

namespace OCI\Site\Repository;

interface PageviewRepositoryInterface
{
    /**
     * Get pageview data grouped by date for the last N days.
     *
     * @return array<int, array{period_date: string, pageview_count: int}>
     */
    public function getByDays(int $siteId, int $days): array;

    /**
     * Get pageview data for all time.
     *
     * @return array<int, array{period_date: string, pageview_count: int}>
     */
    public function getAll(int $siteId): array;

    /**
     * Get pageview data by custom date range.
     *
     * @return array<int, array{period_date: string, pageview_count: int}>
     */
    public function getByDateRange(int $siteId, string $startDate, string $endDate): array;

    /**
     * Get total pageviews for the current calendar month across all sites owned by a user.
     */
    public function getMonthlyTotalForUser(int $userId): int;
}
