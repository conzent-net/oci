<?php

declare(strict_types=1);

namespace OCI\Cookie\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class CookieRepository implements CookieRepositoryInterface
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    public function findBySite(int $siteId, int $page = 1, int $perPage = 50, ?string $category = null, ?string $search = null): array
    {
        $qb = $this->db->createQueryBuilder()
            ->select('sc.*', 'cc.slug AS category_slug', 'cct.name AS category_name')
            ->from('oci_site_cookies', 'sc')
            ->leftJoin('sc', 'oci_cookie_categories', 'cc', 'sc.category_id = cc.id')
            ->leftJoin('cc', 'oci_cookie_category_translations', 'cct', 'cc.id = cct.category_id AND cct.language_id = 1')
            ->where('sc.site_id = :siteId')
            ->setParameter('siteId', $siteId, ParameterType::INTEGER);

        if ($category !== null && $category !== '') {
            if ($category === 'unclassified') {
                $qb->andWhere('sc.category_id IS NULL');
            } else {
                $qb->andWhere('cc.slug = :catSlug')
                    ->setParameter('catSlug', $category);
            }
        }

        if ($search !== null && $search !== '') {
            $qb->andWhere('(sc.cookie_name LIKE :search OR sc.cookie_domain LIKE :search)')
                ->setParameter('search', '%' . $search . '%');
        }

        // Count total
        $countQb = clone $qb;
        $countQb->select('COUNT(*)');
        $total = (int) $countQb->executeQuery()->fetchOne();

        // Paginate
        $offset = ($page - 1) * $perPage;
        $qb->orderBy('sc.cookie_name', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage);

        return [
            'items' => $qb->executeQuery()->fetchAllAssociative(),
            'total' => $total,
        ];
    }

    public function findById(int $id): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT sc.*, cc.slug AS category_slug, cct.name AS category_name
             FROM oci_site_cookies sc
             LEFT JOIN oci_cookie_categories cc ON sc.category_id = cc.id
             LEFT JOIN oci_cookie_category_translations cct ON cc.id = cct.category_id AND cct.language_id = 1
             WHERE sc.id = :id',
            ['id' => $id],
        );

        return $row ?: null;
    }

    public function create(int $siteId, array $data): int
    {
        $this->db->insert('oci_site_cookies', [
            'site_id' => $siteId,
            'cookie_name' => $data['cookie_name'],
            'cookie_domain' => $data['cookie_domain'] ?? null,
            'category_id' => $data['category_id'] ?? null,
            'default_duration' => $data['default_duration'] ?? null,
            'script_url_pattern' => $data['script_url_pattern'] ?? null,
            'from_scan' => (int) ($data['from_scan'] ?? 0),
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $fields = [];
        foreach (['cookie_name', 'cookie_domain', 'category_id', 'default_duration', 'script_url_pattern'] as $col) {
            if (array_key_exists($col, $data)) {
                $fields[$col] = $data[$col];
            }
        }

        if ($fields !== []) {
            $this->db->update('oci_site_cookies', $fields, ['id' => $id]);
        }
    }

    public function delete(int $id): void
    {
        $this->db->delete('oci_site_cookies', ['id' => $id]);
    }

    public function countByCategory(int $siteId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT COALESCE(cc.slug, \'unclassified\') AS category_slug, COUNT(*) AS cnt
             FROM oci_site_cookies sc
             LEFT JOIN oci_cookie_categories cc ON sc.category_id = cc.id
             WHERE sc.site_id = :siteId
             GROUP BY category_slug
             ORDER BY cnt DESC',
            ['siteId' => $siteId],
        );

        $result = [];
        foreach ($rows as $row) {
            $result[$row['category_slug']] = (int) $row['cnt'];
        }

        return $result;
    }

    public function importFromScan(int $siteId, int $scanId): int
    {
        // Get scan cookies that don't already exist as site cookies
        $scanCookies = $this->db->fetchAllAssociative(
            'SELECT sc.cookie_name, sc.cookie_domain, sc.category_slug, sc.expiry_duration
             FROM oci_scan_cookies sc
             WHERE sc.scan_id = :scanId
               AND NOT EXISTS (
                   SELECT 1 FROM oci_site_cookies si
                   WHERE si.site_id = :siteId
                     AND si.cookie_name = sc.cookie_name
               )',
            ['scanId' => $scanId, 'siteId' => $siteId],
        );

        $imported = 0;
        foreach ($scanCookies as $cookie) {
            // Try to match category by slug
            $categoryId = null;
            if (!empty($cookie['category_slug'])) {
                $categoryId = $this->db->fetchOne(
                    'SELECT id FROM oci_cookie_categories WHERE slug = :slug',
                    ['slug' => $cookie['category_slug']],
                ) ?: null;
                if ($categoryId !== null) {
                    $categoryId = (int) $categoryId;
                }
            }

            // Try global match for more info
            $global = $this->matchGlobal($cookie['cookie_name'], $cookie['cookie_domain']);

            $this->db->insert('oci_site_cookies', [
                'site_id' => $siteId,
                'cookie_name' => $cookie['cookie_name'],
                'cookie_domain' => $cookie['cookie_domain'],
                'category_id' => $categoryId ?? ($global ? ($global['category_id'] ?? null) : null),
                'default_duration' => $cookie['expiry_duration'] ?? ($global ? ($global['expiry_duration'] ?? null) : null),
                'from_scan' => 1,
            ]);
            $imported++;
        }

        return $imported;
    }

    public function matchGlobal(string $cookieName, ?string $domain = null): ?array
    {
        // Exact name match first
        $row = $this->db->fetchAssociative(
            'SELECT * FROM oci_cookies_global WHERE cookie_name = :name LIMIT 1',
            ['name' => $cookieName],
        );

        if ($row) {
            return $row;
        }

        // Wildcard match
        $row = $this->db->fetchAssociative(
            'SELECT * FROM oci_cookies_global WHERE wildcard_match = 1 AND :name LIKE REPLACE(cookie_name, \'*\', \'%\') LIMIT 1',
            ['name' => $cookieName],
        );

        return $row ?: null;
    }

    public function getAllForSite(int $siteId): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT sc.*, cc.slug AS category_slug, cct.name AS category_name
             FROM oci_site_cookies sc
             LEFT JOIN oci_cookie_categories cc ON sc.category_id = cc.id
             LEFT JOIN oci_cookie_category_translations cct ON cc.id = cct.category_id AND cct.language_id = 1
             WHERE sc.site_id = :siteId
             ORDER BY cc.sort_order ASC, sc.cookie_name ASC',
            ['siteId' => $siteId],
        );
    }

    public function getLatestScanCookies(int $siteId): array
    {
        // Find latest completed scan that actually found cookies
        $scanId = $this->db->fetchOne(
            'SELECT s.id FROM oci_scans s
             INNER JOIN oci_scan_cookies sc ON sc.scan_id = s.id
             WHERE s.site_id = :siteId AND s.scan_status = :status
             GROUP BY s.id
             ORDER BY s.completed_at DESC LIMIT 1',
            ['siteId' => $siteId, 'status' => 'completed'],
        );

        if ($scanId === false || $scanId === null) {
            return ['scan_id' => null, 'cookies' => []];
        }

        $scanId = (int) $scanId;

        $cookies = $this->db->fetchAllAssociative(
            'SELECT sc.id, sc.scan_id, sc.cookie_name, sc.cookie_domain,
                    sc.category_slug, sc.expiry_duration AS default_duration,
                    sc.http_only, sc.secure, sc.same_site, sc.found_on_url,
                    cct.name AS category_name
             FROM oci_scan_cookies sc
             LEFT JOIN oci_cookie_categories cc ON sc.category_slug = cc.slug
             LEFT JOIN oci_cookie_category_translations cct ON cc.id = cct.category_id AND cct.language_id = 1
             WHERE sc.scan_id = :scanId
             ORDER BY sc.category_slug ASC, sc.cookie_name ASC',
            ['scanId' => $scanId],
        );

        // Enrich unclassified cookies from the global cookie database
        $cookies = $this->enrichFromGlobalDatabase($cookies);

        return ['scan_id' => $scanId, 'cookies' => $cookies];
    }

    public function getObservedCookies(int $siteId): array
    {
        $cookies = $this->db->fetchAllAssociative(
            "SELECT
                co.cookie_name,
                co.cookie_domain,
                co.category_slug,
                SUM(co.pre_consent_count) AS pre_consent_count,
                SUM(co.post_consent_count) AS post_consent_count,
                SUM(co.total_count) AS total_count,
                MIN(co.first_seen_at) AS first_seen_at,
                MAX(co.last_seen_at) AS last_seen_at,
                cct.name AS category_name
             FROM oci_cookie_observations co
             LEFT JOIN oci_cookie_categories cc ON co.category_slug = cc.slug
             LEFT JOIN oci_cookie_category_translations cct ON cc.id = cct.category_id AND cct.language_id = 1
             WHERE co.site_id = :siteId
             GROUP BY co.cookie_name, co.cookie_domain, co.category_slug, cct.name
             ORDER BY co.category_slug ASC, co.cookie_name ASC",
            ['siteId' => $siteId],
        );

        return $this->enrichFromGlobalDatabase($cookies);
    }

    /**
     * Enrich scan cookies with classification from oci_cookies_global.
     *
     * For cookies without a category_slug, look up the global database
     * (exact match first, then wildcard) to assign category and extra info.
     *
     * @param list<array<string, mixed>> $cookies
     * @return list<array<string, mixed>>
     */
    private function enrichFromGlobalDatabase(array $cookies): array
    {
        if ($cookies === []) {
            return $cookies;
        }

        // Collect all unique cookie names for global lookup
        $allNames = [];
        foreach ($cookies as $i => $cookie) {
            $allNames[$i] = $cookie['cookie_name'];
        }

        // Batch exact-match lookup
        $names = array_unique(array_values($allNames));
        $placeholders = implode(',', array_fill(0, count($names), '?'));

        $exactMatches = $this->db->fetchAllAssociative(
            "SELECT cg.cookie_name, cg.category_id, cg.platform, cg.description,
                    cg.expiry_duration, cc.slug AS category_slug, cct.name AS category_name
             FROM oci_cookies_global cg
             LEFT JOIN oci_cookie_categories cc ON cg.category_id = cc.id
             LEFT JOIN oci_cookie_category_translations cct ON cc.id = cct.category_id AND cct.language_id = 1
             WHERE cg.wildcard_match = 0 AND cg.cookie_name IN ($placeholders)",
            array_values($names),
        );

        // Index by cookie_name
        $exactByName = [];
        foreach ($exactMatches as $match) {
            $exactByName[$match['cookie_name']] = $match;
        }

        // Fetch wildcard entries for remaining unmatched cookies
        $wildcardEntries = $this->db->fetchAllAssociative(
            'SELECT cg.cookie_name AS pattern, cg.category_id, cg.platform, cg.description,
                    cg.expiry_duration, cc.slug AS category_slug, cct.name AS category_name
             FROM oci_cookies_global cg
             LEFT JOIN oci_cookie_categories cc ON cg.category_id = cc.id
             LEFT JOIN oci_cookie_category_translations cct ON cc.id = cct.category_id AND cct.language_id = 1
             WHERE cg.wildcard_match = 1',
        );

        // Apply matches to cookies
        foreach ($allNames as $i => $cookieName) {
            $match = null;

            // Try exact match
            if (isset($exactByName[$cookieName])) {
                $match = $exactByName[$cookieName];
            } else {
                // Try wildcard match (pattern uses * as wildcard, e.g. "_ga_*")
                foreach ($wildcardEntries as $wc) {
                    $regex = '/^' . str_replace('\*', '.*', preg_quote($wc['pattern'], '/')) . '$/i';
                    if (preg_match($regex, $cookieName)) {
                        $match = $wc;
                        break;
                    }
                }
            }

            if ($match !== null) {
                // Only override category if the cookie doesn't already have one
                if (empty($cookies[$i]['category_slug'])) {
                    $cookies[$i]['category_slug'] = $match['category_slug'];
                    $cookies[$i]['category_name'] = $match['category_name'];
                }
                // Always enrich with global metadata
                $cookies[$i]['global_platform'] = $match['platform'];
                $cookies[$i]['global_description'] = $match['description'];
                if (empty($cookies[$i]['default_duration']) && !empty($match['expiry_duration'])) {
                    $cookies[$i]['default_duration'] = $match['expiry_duration'];
                }
            }
        }

        return $cookies;
    }
}
