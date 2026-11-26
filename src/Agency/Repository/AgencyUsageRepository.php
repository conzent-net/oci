<?php

declare(strict_types=1);

namespace OCI\Agency\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use OCI\Agency\Service\AgencyScope;

/**
 * Usage figures, aggregated per client and per site.
 *
 * The same fan-out trap as the health query applies here and matters more,
 * because these are numbers somebody may forward to their client: joining
 * pageviews, consents and scans directly to sites multiplies the rows together
 * and inflates every total. Each is aggregated in its own subquery first.
 *
 * `date_to IS NULL` is deliberately **not** applied to the client join. A
 * relationship that ended inside the reporting period still generated usage
 * during it, and a report that silently dropped a departed client would be
 * wrong about the month it covers. That is the whole reason unlink sets a date
 * instead of deleting the row.
 *
 * A client can have several link rows — one per period they were managed —
 * so the periods overlapping the report are collapsed to one row per client
 * FIRST, in `CLIENTS_IN_PERIOD`. Joining sites to every link row would count
 * each site once per period and double the totals, which is the fan-out trap
 * again wearing a different hat.
 */
final class AgencyUsageRepository implements AgencyUsageRepositoryInterface
{
    /**
     * One row per client managed at any point in the period. `date_from` is
     * the earliest period's start; `date_to` is NULL if any period is still
     * open, otherwise the latest end.
     */
    private const CLIENTS_IN_PERIOD = <<<'SQL'
        SELECT
            customer_user_id,
            MIN(date_from) AS date_from,
            CASE WHEN SUM(date_to IS NULL) > 0 THEN NULL ELSE MAX(date_to) END AS date_to
        FROM oci_agency_customers
        WHERE agency_id = :agencyId
          AND (date_from IS NULL OR date_from <= :to)
          AND (date_to IS NULL OR date_to >= :from)
        GROUP BY customer_user_id
        SQL;

    public function __construct(private readonly Connection $db)
    {
    }

    public function byClient(AgencyScope $scope, string $from, string $to): array
    {
        $sql = <<<'SQL'
            SELECT
                u.id                                AS user_id,
                u.email,
                ac.date_from,
                ac.date_to,
                COUNT(DISTINCT s.id)                AS sites,
                COALESCE(SUM(pv.views), 0)          AS pageviews,
                COALESCE(SUM(cs.total_consents), 0) AS consents,
                COALESCE(SUM(cs.accepted), 0)       AS accepted,
                COALESCE(SUM(cs.rejected), 0)       AS rejected,
                COALESCE(SUM(sc.scans), 0)          AS scans,
                MAX(sc.last_scan)                   AS last_scan
            FROM (
                __CLIENTS__
            ) AS ac
            INNER JOIN oci_users AS u ON u.id = ac.customer_user_id
            LEFT JOIN oci_sites AS s ON s.user_id = u.id AND s.deleted_at IS NULL
            LEFT JOIN (
                SELECT site_id, SUM(pageview_count) AS views
                FROM oci_site_pageviews WHERE period_date BETWEEN :from AND :to GROUP BY site_id
            ) AS pv ON pv.site_id = s.id
            LEFT JOIN (
                SELECT site_id, SUM(total_consents) AS total_consents, SUM(accepted) AS accepted, SUM(rejected) AS rejected
                FROM oci_consent_daily_stats WHERE stat_date BETWEEN :from AND :to GROUP BY site_id
            ) AS cs ON cs.site_id = s.id
            LEFT JOIN (
                SELECT site_id, COUNT(*) AS scans, MAX(completed_at) AS last_scan
                FROM oci_scans
                WHERE scan_status = 'completed' AND completed_at BETWEEN :from AND DATE_ADD(:to, INTERVAL 1 DAY)
                GROUP BY site_id
            ) AS sc ON sc.site_id = s.id
            GROUP BY u.id, u.email, ac.date_from, ac.date_to
            ORDER BY pageviews DESC, u.email ASC
            SQL;

        return $this->db->fetchAllAssociative(
            str_replace('__CLIENTS__', self::CLIENTS_IN_PERIOD, $sql),
            ['agencyId' => $scope->agencyId(), 'from' => $from, 'to' => $to],
            ['agencyId' => ParameterType::INTEGER],
        );
    }

    public function bySite(AgencyScope $scope, string $from, string $to, ?int $clientUserId = null): array
    {
        $sql = <<<'SQL'
            SELECT
                s.id                                AS site_id,
                s.domain,
                s.status,
                u.id                                AS user_id,
                u.email,
                COALESCE(pv.views, 0)               AS pageviews,
                COALESCE(cs.total_consents, 0)      AS consents,
                COALESCE(cs.accepted, 0)            AS accepted,
                COALESCE(cs.rejected, 0)            AS rejected,
                COALESCE(sc.scans, 0)               AS scans,
                sc.last_scan
            FROM (
                __CLIENTS__
            ) AS ac
            INNER JOIN oci_users AS u ON u.id = ac.customer_user_id
            INNER JOIN oci_sites AS s ON s.user_id = u.id AND s.deleted_at IS NULL
            LEFT JOIN (
                SELECT site_id, SUM(pageview_count) AS views
                FROM oci_site_pageviews WHERE period_date BETWEEN :from AND :to GROUP BY site_id
            ) AS pv ON pv.site_id = s.id
            LEFT JOIN (
                SELECT site_id, SUM(total_consents) AS total_consents, SUM(accepted) AS accepted, SUM(rejected) AS rejected
                FROM oci_consent_daily_stats WHERE stat_date BETWEEN :from AND :to GROUP BY site_id
            ) AS cs ON cs.site_id = s.id
            LEFT JOIN (
                SELECT site_id, COUNT(*) AS scans, MAX(completed_at) AS last_scan
                FROM oci_scans
                WHERE scan_status = 'completed' AND completed_at BETWEEN :from AND DATE_ADD(:to, INTERVAL 1 DAY)
                GROUP BY site_id
            ) AS sc ON sc.site_id = s.id
            WHERE (:clientUserId = 0 OR u.id = :clientUserId)
            ORDER BY u.email ASC, pageviews DESC
            SQL;

        return $this->db->fetchAllAssociative(
            str_replace('__CLIENTS__', self::CLIENTS_IN_PERIOD, $sql),
            [
                'agencyId' => $scope->agencyId(),
                'from' => $from,
                'to' => $to,
                'clientUserId' => $clientUserId ?? 0,
            ],
            ['agencyId' => ParameterType::INTEGER, 'clientUserId' => ParameterType::INTEGER],
        );
    }
}
