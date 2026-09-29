<?php

declare(strict_types=1);

namespace OCI\Agency\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use OCI\Agency\Service\AgencyScope;

/**
 * One query, every signal, every site in the agency's book.
 *
 * Notes on the parts that are easy to get subtly wrong:
 *
 * - **Pre-consent cookies come from `oci_cookie_observations.pre_consent_count`,
 *   not from counting beacons.** The existing `getCustomerHealthData()` uses
 *   `COUNT(oci_beacons) > 0` — *any beacon at all* — which paints a healthy
 *   site red for having a tracker configured rather than for firing one before
 *   consent. Replacing it will turn most currently-red clients green, which is
 *   a measurement change users must be told about, not a silent improvement.
 *
 * - **`necessary` cookies are excluded, and that exclusion is the difference
 *   between this feature working and being a laughing stock.** Strictly
 *   necessary cookies are exempt from consent, and the three largest by a
 *   distance are Conzent's OWN: `conzent_id`, `lastRenewedDate` and
 *   `conzentConsentPrefs`, present on every protected site. They are set before
 *   consent by design — a CMP cannot remember that somebody said "no" without
 *   storing that they said it. Measured on real data they were **58% of all
 *   pre-consent volume**, which meant every Conzent-protected site reported
 *   itself as failing, permanently, because of the CMP protecting it.
 *
 * - **Unclassified cookies (`category_slug IS NULL`) still count.** That is the
 *   deliberate other half: exemption is a claim about a cookie's purpose, and a
 *   cookie nobody has classified has no established purpose to exempt it. It
 *   should be looked at, not waved through.
 *
 * - **Pageviews are the honesty valve.** Zero pre-consent observations on a
 *   site with zero traffic means nobody visited, not that nobody was tracked.
 *   `pageviews_30d` is selected so the scorer can tell those apart; without it
 *   the dashboard confidently certifies dead sites as compliant.
 *
 * - **The 7-day window ends yesterday.** Observations arrive through the day,
 *   so including today would compare a part-day against full ones and make
 *   every site look like it improved each morning.
 *
 * - **`iab_support` lives inside a JSON blob**, so it is extracted with
 *   JSON_VALUE rather than being filterable as a column. Worth a derived column
 *   if this ever needs sorting at scale.
 *
 * - Each subquery aggregates independently before joining. Joining scans,
 *   observations and pageviews directly to sites would multiply rows together
 *   and inflate every SUM — the classic fan-out that makes a dashboard quietly
 *   report numbers several times too large. **Banners included**: a site can
 *   carry one banner per framework, and three production sites do. Joining
 *   `oci_site_banners` directly produced two rows for each of them, and the
 *   caller's `$byId[site_id] = $row` kept whichever came last — so
 *   `iab_support` for getconzent.com itself was read from an arbitrary one of
 *   its two banners. The subquery collapses to one row per site and takes the
 *   flag from the GDPR banner, which is the one IAB TCF applies to.
 */
final class SiteHealthRepository implements SiteHealthRepositoryInterface
{
    private const PRE_CONSENT_WINDOW_DAYS = 7;
    private const PAGEVIEW_WINDOW_DAYS = 30;

    public function __construct(private readonly Connection $db)
    {
    }

    public function signalsForAgency(AgencyScope $scope): array
    {
        $sql = <<<'SQL'
            SELECT
                s.id                                AS site_id,
                s.user_id,
                s.domain,
                s.site_name,
                s.status,
                s.suspended_reason,
                s.gcm_enabled,
                s.tag_fire_enabled,
                s.advertiser_consent_mode,
                s.last_banner_load_at,
                u.email                             AS client_email,
                sb.site_id IS NOT NULL              AS has_banner,
                COALESCE(sb.iab_support, 'false')   AS iab_support,
                cp.n > 0                            AS has_cookie_policy,
                pp.n > 0                            AS has_privacy_policy,
                sc.last_completed_at                AS last_scan_at,
                sc.last_status                      AS last_scan_status,
                sc.failed_count                     AS failed_scans,
                COALESCE(obs.pre_consent, 0)        AS preconsent_hits_7d,
                COALESCE(obs.cookie_names, 0)       AS preconsent_cookies_7d,
                obs.last_observed                   AS preconsent_last_observed,
                COALESCE(pv.views, 0)               AS pageviews_30d
            FROM oci_sites AS s
            INNER JOIN oci_agency_customers AS ac
                ON ac.customer_user_id = s.user_id
               AND ac.agency_id = :agencyId
               AND ac.date_to IS NULL
            INNER JOIN oci_users AS u ON u.id = s.user_id
            LEFT JOIN (
                SELECT
                    b.site_id,
                    SUBSTRING_INDEX(
                        GROUP_CONCAT(
                            COALESCE(JSON_VALUE(b.general_setting, '$.iab_support'), 'false')
                            ORDER BY (bt.cookie_laws = 'gdpr' OR bt.cookie_laws LIKE '%"gdpr":1%') DESC, b.id ASC
                        ),
                        ',', 1
                    ) AS iab_support
                FROM oci_site_banners AS b
                LEFT JOIN oci_banner_templates AS bt ON bt.id = b.banner_template_id
                GROUP BY b.site_id
            ) AS sb ON sb.site_id = s.id
            LEFT JOIN (
                SELECT site_id, COUNT(*) AS n FROM oci_cookie_policies GROUP BY site_id
            ) AS cp ON cp.site_id = s.id
            LEFT JOIN (
                SELECT site_id, COUNT(*) AS n FROM oci_privacy_policies GROUP BY site_id
            ) AS pp ON pp.site_id = s.id
            LEFT JOIN (
                SELECT
                    site_id,
                    MAX(CASE WHEN scan_status = 'completed' THEN completed_at END) AS last_completed_at,
                    SUBSTRING_INDEX(GROUP_CONCAT(scan_status ORDER BY COALESCE(completed_at, created_at) DESC), ',', 1) AS last_status,
                    SUM(scan_status = 'failed') AS failed_count
                FROM oci_scans
                GROUP BY site_id
            ) AS sc ON sc.site_id = s.id
            LEFT JOIN (
                SELECT
                    site_id,
                    SUM(pre_consent_count) AS pre_consent,
                    COUNT(DISTINCT CASE WHEN pre_consent_count > 0 THEN cookie_name END) AS cookie_names,
                    MAX(observation_date) AS last_observed
                FROM oci_cookie_observations
                WHERE observation_date BETWEEN :windowStart AND :windowEnd
                  AND COALESCE(category_slug, '') <> 'necessary'
                GROUP BY site_id
            ) AS obs ON obs.site_id = s.id
            LEFT JOIN (
                SELECT site_id, SUM(pageview_count) AS views
                FROM oci_site_pageviews
                WHERE period_date >= :pageviewStart
                GROUP BY site_id
            ) AS pv ON pv.site_id = s.id
            WHERE s.deleted_at IS NULL
            ORDER BY u.email ASC, s.domain ASC
            SQL;

        // Yesterday, not today: observations land through the day, so a
        // part-day would make every site look like it improved each morning.
        $windowEnd = new \DateTimeImmutable('yesterday');
        $windowStart = $windowEnd->modify('-' . (self::PRE_CONSENT_WINDOW_DAYS - 1) . ' days');
        $pageviewStart = $windowEnd->modify('-' . (self::PAGEVIEW_WINDOW_DAYS - 1) . ' days');

        $rows = $this->db->fetchAllAssociative($sql, [
            'agencyId' => $scope->agencyId(),
            'windowStart' => $windowStart->format('Y-m-d'),
            'windowEnd' => $windowEnd->format('Y-m-d'),
            'pageviewStart' => $pageviewStart->format('Y-m-d'),
        ], ['agencyId' => ParameterType::INTEGER]);

        $byId = [];

        foreach ($rows as $row) {
            $row['preconsent_window_start'] = $windowStart->format('Y-m-d');
            $row['preconsent_window_end'] = $windowEnd->format('Y-m-d');
            $byId[(int) $row['site_id']] = $row;
        }

        return $byId;
    }
}
