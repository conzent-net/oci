<?php

declare(strict_types=1);

namespace OCI\Site\Repository;

use Doctrine\DBAL\Connection;

final class PageviewRepository implements PageviewRepositoryInterface
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    public function getByDays(int $siteId, int $days): array
    {
        $sql = <<<'SQL'
            SELECT period_date, pageview_count
            FROM oci_site_pageviews
            WHERE site_id = :siteId
              AND period_date > DATE_SUB(CURDATE(), INTERVAL :days DAY)
            ORDER BY period_date ASC
        SQL;

        /** @var array<int, array{period_date: string, pageview_count: int}> */
        return $this->db->fetchAllAssociative($sql, [
            'siteId' => $siteId,
            'days' => $days,
        ]);
    }

    public function getAll(int $siteId): array
    {
        $sql = <<<'SQL'
            SELECT period_date, pageview_count
            FROM oci_site_pageviews
            WHERE site_id = :siteId
            ORDER BY period_date ASC
        SQL;

        /** @var array<int, array{period_date: string, pageview_count: int}> */
        return $this->db->fetchAllAssociative($sql, ['siteId' => $siteId]);
    }

    public function getByDateRange(int $siteId, string $startDate, string $endDate): array
    {
        $sql = <<<'SQL'
            SELECT period_date, pageview_count
            FROM oci_site_pageviews
            WHERE site_id = :siteId
              AND period_date BETWEEN :startDate AND :endDate
            ORDER BY period_date ASC
        SQL;

        /** @var array<int, array{period_date: string, pageview_count: int}> */
        return $this->db->fetchAllAssociative($sql, [
            'siteId' => $siteId,
            'startDate' => $startDate,
            'endDate' => $endDate,
        ]);
    }
}
