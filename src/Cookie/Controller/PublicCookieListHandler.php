<?php

declare(strict_types=1);

namespace OCI\Cookie\Controller;

use Doctrine\DBAL\Connection;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/cookies?website_id=KEY — Public cookie list for consent banner.
 *
 * Returns cookies from the latest completed scan, grouped by category.
 * Falls back to oci_site_cookies if no scan exists.
 * Called from the consent banner preference center modal at runtime.
 */
final class PublicCookieListHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $websiteKey = trim($params['website_id'] ?? '');

        if ($websiteKey === '') {
            return ApiResponse::json(['error' => 'Missing website_id parameter'], 400)
                ->withHeader('Access-Control-Allow-Origin', '*');
        }

        $site = $this->db->fetchAssociative(
            'SELECT id FROM oci_sites WHERE website_key = :key AND status = :status AND deleted_at IS NULL',
            ['key' => $websiteKey, 'status' => 'active'],
        );

        if ($site === false) {
            return ApiResponse::json(['error' => 'Site not found'], 404)
                ->withHeader('Access-Control-Allow-Origin', '*');
        }

        $siteId = (int) $site['id'];

        $scanCookies = $this->loadFromLatestScan($siteId);
        $observedCookies = $this->loadFromObservations($siteId);
        $cookies = $this->mergeCookieSources($scanCookies, $observedCookies);

        if (empty($cookies)) {
            $cookies = $this->loadFromSiteCookies($siteId);
        }

        return ApiResponse::json(['cookies' => empty($cookies) ? new \stdClass() : $cookies])
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }

    private function loadFromLatestScan(int $siteId): array
    {
        $scanId = $this->db->fetchOne(
            'SELECT s.id FROM oci_scans s
             INNER JOIN oci_scan_cookies sc ON sc.scan_id = s.id
             WHERE s.site_id = :siteId AND s.scan_status = :status
             GROUP BY s.id
             ORDER BY s.completed_at DESC LIMIT 1',
            ['siteId' => $siteId, 'status' => 'completed'],
        );

        if ($scanId === false || $scanId === null) {
            return [];
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT sc.cookie_name, sc.cookie_domain, sc.category_slug,
                    sc.expiry_duration
             FROM oci_scan_cookies sc
             WHERE sc.scan_id = :scanId
             ORDER BY sc.category_slug ASC, sc.cookie_name ASC',
            ['scanId' => (int) $scanId],
        );

        $rows = $this->enrichFromGlobalDatabase($rows);

        return $this->groupByCategory($rows, 'category_slug', 'expiry_duration');
    }

    private function loadFromObservations(int $siteId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT co.cookie_name, co.cookie_domain, co.category_slug,
                    NULL AS expiry_duration
             FROM oci_cookie_observations co
             WHERE co.site_id = :siteId
             GROUP BY co.cookie_name, co.cookie_domain, co.category_slug
             ORDER BY co.category_slug ASC, co.cookie_name ASC',
            ['siteId' => $siteId],
        );

        $rows = $this->enrichFromGlobalDatabase($rows);

        return $this->groupByCategory($rows, 'category_slug', 'expiry_duration');
    }

    private function mergeCookieSources(array $scanCookies, array $observedCookies): array
    {
        $merged = $scanCookies;
        $seen = [];

        foreach ($merged as $slug => $items) {
            foreach ($items as $item) {
                $seen[$item['name']] = true;
            }
        }

        foreach ($observedCookies as $slug => $items) {
            foreach ($items as $item) {
                if (isset($seen[$item['name']])) {
                    continue;
                }
                $merged[$slug][] = $item;
                $seen[$item['name']] = true;
            }
        }

        return $merged;
    }

    private function loadFromSiteCookies(int $siteId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT sc.cookie_name, sc.cookie_domain, sc.default_duration,
                    cc.slug AS category_slug
             FROM oci_site_cookies sc
             LEFT JOIN oci_cookie_categories cc ON sc.category_id = cc.id
             WHERE sc.site_id = :siteId
             ORDER BY cc.sort_order ASC, sc.cookie_name ASC',
            ['siteId' => $siteId],
        );

        return $this->groupByCategory($rows, 'category_slug', 'default_duration');
    }

    private function groupByCategory(array $rows, string $slugField, string $durationField): array
    {
        $seen = [];
        $cookies = [];

        foreach ($rows as $row) {
            $name = $row['cookie_name'];
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;

            $slug = $row[$slugField] ?? 'unclassified';
            if ($slug === '' || $slug === null) {
                $slug = 'unclassified';
            }

            $cookies[$slug][] = [
                'name' => $name,
                'category' => $slug,
                'domain' => $row['cookie_domain'] ?? '',
                'description' => ['en' => $row['global_description'] ?? ''],
                'duration' => ['en' => $row[$durationField] ?? ''],
            ];
        }

        return $cookies;
    }

    private function enrichFromGlobalDatabase(array $cookies): array
    {
        if (empty($cookies)) {
            return $cookies;
        }

        $names = array_unique(array_column($cookies, 'cookie_name'));

        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $exactMatches = $this->db->fetchAllAssociative(
            "SELECT cookie_name, category_id, description, expiry_duration, platform,
                    cc.slug AS category_slug
             FROM oci_cookies_global cg
             LEFT JOIN oci_cookie_categories cc ON cg.category_id = cc.id
             WHERE cg.wildcard_match = 0 AND cg.cookie_name IN ({$placeholders})",
            array_values($names),
        );

        $exactMap = [];
        foreach ($exactMatches as $match) {
            $exactMap[strtolower($match['cookie_name'])] = $match;
        }

        $wildcardPatterns = $this->db->fetchAllAssociative(
            "SELECT cookie_name, category_id, description, expiry_duration, platform,
                    cc.slug AS category_slug
             FROM oci_cookies_global cg
             LEFT JOIN oci_cookie_categories cc ON cg.category_id = cc.id
             WHERE cg.wildcard_match = 1",
        );

        foreach ($cookies as &$cookie) {
            $nameLower = strtolower($cookie['cookie_name']);

            if (isset($exactMap[$nameLower])) {
                $match = $exactMap[$nameLower];
                if (empty($cookie['category_slug'])) {
                    $cookie['category_slug'] = $match['category_slug'] ?? '';
                }
                $cookie['global_description'] = $match['description'] ?? '';
                if (empty($cookie['expiry_duration'])) {
                    $cookie['expiry_duration'] = $match['expiry_duration'] ?? '';
                }
                continue;
            }

            foreach ($wildcardPatterns as $pattern) {
                $regex = '/^' . str_replace(['*', '?'], ['.*', '.'], preg_quote($pattern['cookie_name'], '/')) . '$/i';
                $regex = str_replace('\\*', '.*', str_replace('\\?', '.', '/^' . preg_quote($pattern['cookie_name'], '/') . '$/i'));
                if (preg_match($regex, $cookie['cookie_name'])) {
                    if (empty($cookie['category_slug'])) {
                        $cookie['category_slug'] = $pattern['category_slug'] ?? '';
                    }
                    $cookie['global_description'] = $pattern['description'] ?? '';
                    if (empty($cookie['expiry_duration'])) {
                        $cookie['expiry_duration'] = $pattern['expiry_duration'] ?? '';
                    }
                    break;
                }
            }
        }
        unset($cookie);

        return $cookies;
    }
}
