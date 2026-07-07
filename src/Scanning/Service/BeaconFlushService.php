<?php

declare(strict_types=1);

namespace OCI\Scanning\Service;

use Doctrine\DBAL\Connection;
use OCI\Scanning\Repository\ScanRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Batch-processes cookie observation beacons from the Redis buffer.
 *
 * Called by the `ingest:flush` worker command. Pops items from the
 * beacon buffer, groups by site, deduplicates, classifies cookies
 * against the global database, and batch-upserts into oci_cookie_observations.
 *
 * For explicit scan beacons (with scan_id), also writes to oci_scan_cookies
 * with source='client' and the consent phase.
 */
final class BeaconFlushService
{
    /** @var array<string, array{category_slug: string|null, wildcard: bool}>|null */
    private ?array $globalCookieCache = null;

    private int $globalCookieCacheTime = 0;

    private const GLOBAL_CACHE_TTL = 300; // 5 minutes

    public function __construct(
        private readonly Connection $db,
        private readonly BeaconBufferService $buffer,
        private readonly ScanRepositoryInterface $scanRepo,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Pop a batch from the beacon buffer, process, and write to DB.
     *
     * @return int Number of items processed.
     */
    public function flush(int $batchSize = 200): int
    {
        $items = $this->buffer->popBatch(BeaconBufferService::BUFFER_BEACON, $batchSize);
        if ($items === []) {
            return 0;
        }

        $this->processBatch($items);

        return \count($items);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function processBatch(array $items): void
    {
        // Group by site_id
        $bySite = [];
        foreach ($items as $item) {
            $siteId = (int) ($item['site_id'] ?? 0);
            if ($siteId <= 0) {
                continue;
            }
            $bySite[$siteId][] = $item;
        }

        $today = date('Y-m-d');
        $globalDb = $this->getGlobalCookieDatabase();

        foreach ($bySite as $siteId => $siteItems) {
            $this->processSiteItems($siteId, $siteItems, $today, $globalDb);
        }
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, array{category_slug: string|null}> $globalDb
     */
    private function processSiteItems(int $siteId, array $items, string $today, array $globalDb): void
    {
        foreach ($items as $item) {
            $phase = (string) ($item['consent_phase'] ?? 'pre_consent');
            if ($phase !== 'pre_consent' && $phase !== 'post_consent') {
                $phase = 'pre_consent';
            }

            $scanId = (int) ($item['scan_id'] ?? 0);
            $scanUrl = (string) ($item['scan_url'] ?? $item['url'] ?? '');
            $cookies = $this->extractCookies($item);

            foreach ($cookies as $cookie) {
                $cookieName = (string) ($cookie['name'] ?? $cookie['cookie_name'] ?? '');
                if ($cookieName === '') {
                    continue;
                }

                $cookieDomain = (string) ($cookie['domain'] ?? $cookie['cookie_domain'] ?? '');
                $categorySlug = $this->classifyCookie($cookieName, $cookieDomain, $globalDb);

                // Upsert observation (aggregated counter)
                try {
                    $this->scanRepo->upsertCookieObservation($siteId, $today, $phase, [
                        'cookie_name' => $cookieName,
                        'cookie_domain' => $cookieDomain !== '' ? $cookieDomain : null,
                        'category_slug' => $categorySlug,
                    ]);
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to upsert cookie observation: ' . $e->getMessage(), [
                        'site_id' => $siteId,
                        'cookie_name' => $cookieName,
                    ]);
                }

                // For explicit scans, also write to oci_scan_cookies
                if ($scanId > 0) {
                    $this->addClientScanCookie($scanId, $cookieName, $cookieDomain, $categorySlug, $phase, $scanUrl);
                }
            }
        }
    }

    /**
     * Extract cookies from a beacon payload.
     *
     * Handles both formats:
     *   - scan_data: {data: {c: [{name, valueLen}, ...]}}
     *   - pageview:  {cookies: [{name, valueLen}, ...]}
     *
     * @return list<array<string, mixed>>
     */
    private function extractCookies(array $item): array
    {
        // scan_data format: data.c contains cookies
        if (isset($item['data']['c']) && \is_array($item['data']['c'])) {
            return $item['data']['c'];
        }

        // pageview format: cookies array
        if (isset($item['cookies']) && \is_array($item['cookies'])) {
            return $item['cookies'];
        }

        return [];
    }

    /**
     * Classify a cookie using the global database, then regex fallback.
     *
     * @param array<string, array{category_slug: string|null, wildcard: bool, pattern?: string}> $globalDb
     */
    private function classifyCookie(string $cookieName, string $cookieDomain, array $globalDb): ?string
    {
        // Exact match in global DB
        $key = strtolower($cookieName);
        if (isset($globalDb[$key]) && !$globalDb[$key]['wildcard']) {
            return $globalDb[$key]['category_slug'];
        }

        // Wildcard matches
        foreach ($globalDb as $entry) {
            if (!$entry['wildcard'] || !isset($entry['pattern'])) {
                continue;
            }
            $regex = '/^' . str_replace('\\*', '.*', preg_quote($entry['pattern'], '/')) . '$/i';
            if (preg_match($regex, $cookieName)) {
                return $entry['category_slug'];
            }
        }

        // Regex fallback (mirrors ScanService::categorizeCookie)
        return $this->categorizeCookieFallback($cookieName, $cookieDomain);
    }

    /**
     * Regex-based cookie classification fallback.
     *
     * Mirrors ScanService::categorizeCookie() patterns.
     */
    private function categorizeCookieFallback(string $cookieName, string $cookieDomain): ?string
    {
        $name = strtolower($cookieName);
        $domain = strtolower($cookieDomain);

        if (preg_match('/^(conzentconsent|conzentconsentprefs|conzent_id|euconsent|lastreneweddate|wp_consent_)/', $name)) {
            return 'necessary';
        }
        if (str_contains($domain, 'conzent.net') || str_contains($domain, 'conzent.com')) {
            return 'necessary';
        }
        if (preg_match('/^(csrf|xsrf|session|phpsessid|jsessionid|asp\.net_session|__host-|__secure-)/', $name)) {
            return 'necessary';
        }
        if (preg_match('/^(_ga|_gid|_gat|_gac_|__utm|_hjid|_hjSession|_clck|_clsk|mp_|amplitude)/', $name)) {
            return 'analytics';
        }
        if (preg_match('/^(_fbp|_fbc|_gcl_|_uet|_tt_|IDE|MUID|fr|_pinterest)/', $name)) {
            return 'marketing';
        }
        if (preg_match('/^(lang|locale|currency|timezone|consent|cookieconsent|cc_cookie)/', $name)) {
            return 'functional';
        }
        if (str_contains($domain, 'google') || str_contains($domain, 'youtube')) {
            return str_contains($name, 'consent') ? 'necessary' : 'analytics';
        }
        if (str_contains($domain, 'facebook') || str_contains($domain, 'meta')) {
            return 'marketing';
        }

        return null;
    }

    /**
     * Add a client-side scan cookie to oci_scan_cookies (deduplicated by name per scan).
     */
    private function addClientScanCookie(
        int $scanId,
        string $cookieName,
        string $cookieDomain,
        ?string $categorySlug,
        string $phase,
        string $foundOnUrl,
    ): void {
        try {
            // Check for existing entry to avoid duplicates
            $exists = $this->db->fetchOne(
                'SELECT id FROM oci_scan_cookies WHERE scan_id = :scanId AND cookie_name = :name LIMIT 1',
                ['scanId' => $scanId, 'name' => $cookieName],
            );

            if ($exists !== false) {
                return; // Already recorded for this scan
            }

            $this->scanRepo->addScanCookie($scanId, [
                'cookie_name' => $cookieName,
                'cookie_domain' => $cookieDomain !== '' ? $cookieDomain : null,
                'category_slug' => $categorySlug ?? 'unclassified',
                'found_on_url' => $foundOnUrl,
                'source' => 'client',
                'consent_phase' => $phase,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to add client scan cookie: ' . $e->getMessage(), [
                'scan_id' => $scanId,
                'cookie_name' => $cookieName,
            ]);
        }
    }

    /**
     * Load the global cookie database into memory for classification.
     *
     * Cached for 5 minutes to avoid repeated DB queries during batch processing.
     *
     * @return array<string, array{category_slug: string|null, wildcard: bool, pattern?: string}>
     */
    private function getGlobalCookieDatabase(): array
    {
        $now = time();

        if ($this->globalCookieCache !== null && ($now - $this->globalCookieCacheTime) < self::GLOBAL_CACHE_TTL) {
            return $this->globalCookieCache;
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT cg.cookie_name, cg.wildcard_match, cg.category_id,
                    cc.slug AS category_slug
             FROM oci_cookies_global cg
             LEFT JOIN oci_cookie_categories cc ON cg.category_id = cc.id',
        );

        $cache = [];
        foreach ($rows as $row) {
            $isWildcard = (int) $row['wildcard_match'] === 1;
            $name = $row['cookie_name'];

            if ($isWildcard) {
                // Store wildcards with a unique key to prevent overwrite
                $cache['wildcard:' . $name] = [
                    'category_slug' => $row['category_slug'],
                    'wildcard' => true,
                    'pattern' => $name,
                ];
            } else {
                $cache[strtolower($name)] = [
                    'category_slug' => $row['category_slug'],
                    'wildcard' => false,
                ];
            }
        }

        $this->globalCookieCache = $cache;
        $this->globalCookieCacheTime = $now;

        return $cache;
    }
}
