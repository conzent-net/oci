<?php

declare(strict_types=1);

namespace OCI\Scanning\Service;

use OCI\Monetization\Service\PricingService;
use OCI\Scanning\Repository\ScanRepositoryInterface;
use OCI\Shared\Repository\PlanRepositoryInterface;
use OCI\Site\Repository\SiteRepositoryInterface;
use Predis\Client as RedisClient;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates cookie scanning: creates scans, dispatches to queue,
 * processes results from scanner servers, manages scheduled scans.
 */
final class ScanService
{
    private const QUEUE_KEY = 'oci:scan:queue';
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly ScanRepositoryInterface $scanRepo,
        private readonly SiteRepositoryInterface $siteRepo,
        private readonly PlanRepositoryInterface $planRepo,
        private readonly RedisClient $redis,
        private readonly LoggerInterface $logger,
        private readonly ?PricingService $pricingService = null,
    ) {}

    // ── Public API (called by handlers) ──────────────────

    /**
     * Initiate a new scan for a site.
     *
     * @param array<int, string> $includeUrls Specific URLs to scan (empty = use site_urls)
     * @param array<int, string> $excludeUrls URLs to exclude
     * @return array{scan_id: int, message: string}
     */
    public function initiateScan(
        int $siteId,
        int $userId,
        string $scanType = 'full',
        array $includeUrls = [],
        array $excludeUrls = [],
    ): array {
        // Verify site belongs to user
        if (!$this->siteRepo->belongsToUser($siteId, $userId)) {
            throw new \InvalidArgumentException('Site not found');
        }

        // Check for active scan
        if ($this->scanRepo->hasActiveScan($siteId)) {
            throw new \RuntimeException('A scan is already in progress for this site');
        }

        $site = $this->siteRepo->findById($siteId);
        if ($site === null) {
            throw new \InvalidArgumentException('Site not found');
        }

        // Build URL list
        $urls = $this->resolveUrls($siteId, $site, $includeUrls, $excludeUrls);
        if (empty($urls)) {
            throw new \RuntimeException('No URLs to scan. Add pages to your site first.');
        }

        // Enforce plan page limit
        $scanLimit = $this->resolveScanLimit($userId);
        if ($scanLimit > 0 && \count($urls) > $scanLimit) {
            $urls = \array_slice($urls, 0, $scanLimit);
        }

        // Find a scanner server
        $server = $this->scanRepo->getActiveScanServer();

        // Create scan record
        $scanId = $this->scanRepo->createScan([
            'site_id' => $siteId,
            'scan_type' => $scanType,
            'scan_status' => 'queued',
            'firstparty_url' => $this->buildSiteUrl($site['domain']),
            'include_urls' => !empty($includeUrls) ? json_encode($includeUrls) : null,
            'exclude_urls' => !empty($excludeUrls) ? json_encode($excludeUrls) : null,
            'total_pages' => \count($urls),
            'server_id' => $server !== null ? (int) $server['id'] : null,
            'scan_location' => $server !== null ? (int) $server['id'] : 0,
        ]);

        // Create per-URL tracking records
        $this->scanRepo->createScanUrls($scanId, $urls);

        // Push to Redis queue for worker to pick up
        $this->enqueue($scanId);

        $this->logger->info("Scan {$scanId} queued for site {$siteId} ({$site['domain']}), {$urls[0]}... ({$this->count($urls)} URLs)");

        return [
            'scan_id' => $scanId,
            'total_urls' => \count($urls),
            'message' => 'Scan queued successfully',
        ];
    }

    /**
     * Schedule a scan for later execution.
     */
    public function scheduleScan(
        int $siteId,
        int $userId,
        string $frequency,
        ?string $scheduleDate = null,
        ?string $scheduleTime = null,
    ): array {
        if (!$this->siteRepo->belongsToUser($siteId, $userId)) {
            throw new \InvalidArgumentException('Site not found');
        }

        if (!\in_array($frequency, ['once', 'monthly'], true)) {
            throw new \InvalidArgumentException('Frequency must be "once" or "monthly"');
        }

        $site = $this->siteRepo->findById($siteId);
        if ($site === null) {
            throw new \InvalidArgumentException('Site not found');
        }

        $scanId = $this->scanRepo->createScan([
            'site_id' => $siteId,
            'scan_type' => 'full',
            'scan_status' => 'scheduled',
            'is_scheduled' => 1,
            'frequency' => $frequency,
            'schedule_date' => $scheduleDate,
            'schedule_time' => $scheduleTime ?? '03:00:00',
            'firstparty_url' => $this->buildSiteUrl($site['domain']),
        ]);

        $this->logger->info("Scan {$scanId} scheduled ({$frequency}) for site {$siteId}");

        return [
            'scan_id' => $scanId,
            'message' => "Scan scheduled ({$frequency})",
        ];
    }

    /**
     * Cancel an active scan.
     */
    public function cancelScan(int $scanId, int $userId): void
    {
        $scan = $this->scanRepo->findById($scanId);
        if ($scan === null) {
            throw new \InvalidArgumentException('Scan not found');
        }

        // Verify ownership
        $site = $this->siteRepo->findById((int) $scan['site_id']);
        if ($site === null || !$this->siteRepo->belongsToUser((int) $scan['site_id'], $userId)) {
            throw new \InvalidArgumentException('Scan not found');
        }

        if (\in_array($scan['scan_status'], ['completed', 'failed', 'cancelled'], true)) {
            throw new \RuntimeException('Cannot cancel a scan that is already ' . $scan['scan_status']);
        }

        $this->scanRepo->updateScan($scanId, ['scan_status' => 'cancelled']);
        $this->logger->info("Scan {$scanId} cancelled by user {$userId}");
    }

    /**
     * Get scan details for the detail view.
     *
     * @return array<string, mixed>
     */
    public function getScanDetails(int $scanId): array
    {
        $scan = $this->scanRepo->findById($scanId);
        if ($scan === null) {
            throw new \InvalidArgumentException('Scan not found');
        }

        $cookies = $this->scanRepo->getScanCookies($scanId);
        $urls = $this->scanRepo->getScanUrls($scanId);
        $beacons = $this->scanRepo->getBeaconsByScan($scanId);

        // Group cookies by category
        $byCategory = [];
        foreach ($cookies as $cookie) {
            $cat = $cookie['category_slug'] ?: 'unclassified';
            $byCategory[$cat][] = $cookie;
        }

        return [
            'scan' => $scan,
            'cookies' => $cookies,
            'cookiesByCategory' => $byCategory,
            'urls' => $urls,
            'beacons' => $beacons,
            'stats' => [
                'total_cookies' => \count($cookies),
                'total_beacons' => \count($beacons),
                'total_urls' => \count($urls),
                'urls_completed' => \count(array_filter($urls, fn($u) => $u['status'] === 'completed')),
                'urls_failed' => \count(array_filter($urls, fn($u) => $u['status'] === 'failed')),
            ],
        ];
    }

    // ── Worker Methods (called by queue:work) ────────────

    /**
     * Process the next queued scan. Called by the worker loop.
     *
     * @return bool True if a scan was processed, false if queue empty
     */
    public function processNextScan(): bool
    {
        $payload = $this->dequeue();
        if ($payload === null) {
            return false;
        }

        $scanId = (int) $payload;
        $scan = $this->scanRepo->findById($scanId);

        if ($scan === null || $scan['scan_status'] !== 'queued') {
            return true; // Skip stale/cancelled scans, but return true to keep polling
        }

        $this->logger->info("Processing scan {$scanId}");

        try {
            $this->executeScan($scanId, $scan);
        } catch (\Throwable $e) {
            $this->logger->error("Scan {$scanId} failed: {$e->getMessage()}");
            $this->handleScanFailure($scanId, $scan, $e->getMessage());
        }

        return true;
    }

    /**
     * Execute a scan by dispatching URLs to the scanner server.
     *
     * @param array<string, mixed> $scan
     */
    private function executeScan(int $scanId, array $scan): void
    {
        // Mark as in_progress
        $this->scanRepo->updateScan($scanId, [
            'scan_status' => 'in_progress',
            'started_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'scan_attempts' => ((int) $scan['scan_attempts']) + 1,
        ]);

        // Get scanner server
        $server = null;
        if ($scan['server_id']) {
            // Use the assigned server
            $servers = $this->scanRepo->getAllScanServers();
            foreach ($servers as $s) {
                if ((int) $s['id'] === (int) $scan['server_id'] && (int) $s['is_active'] === 1) {
                    $server = $s;
                    break;
                }
            }
        }

        if ($server === null) {
            $server = $this->scanRepo->getActiveScanServer();
        }

        if ($server === null) {
            throw new \RuntimeException('No active scan server available');
        }

        // Get URLs to scan
        $urls = $this->scanRepo->getScanUrls($scanId, 'pending');
        if (empty($urls)) {
            $this->completeScan($scanId);
            return;
        }

        $urlStrings = array_map(fn($u) => $u['url'], $urls);

        // Build callback URL for the scanner to POST results back
        // Use internal URL when scanner is on the same Docker network
        $callbackBase = trim($_ENV['SCANNER_CALLBACK_URL'] ?? $_ENV['APP_URL'] ?? 'http://localhost:8100', '/');
        $callbackUrl = $callbackBase . '/api/v1/scan-webhook';

        // Send batch scan request to scanner server
        $response = $this->callScannerApi($server, '/scan/batch', [
            'scan_id' => $scanId,
            'urls' => $urlStrings,
            'callback_url' => $callbackUrl,
            'options' => [
                'waitForNetworkIdle' => true,
                'extraWait' => 3000,
            ],
        ]);

        if ($response === null || !($response['success'] ?? false)) {
            throw new \RuntimeException('Scanner API returned error: ' . json_encode($response));
        }

        // Mark URLs as scanning
        foreach ($urls as $url) {
            $this->scanRepo->updateScanUrl((int) $url['id'], ['status' => 'scanning']);
        }

        $this->logger->info("Scan {$scanId}: dispatched {$this->count($urlStrings)} URLs to scanner {$server['server_name']}");
    }

    /**
     * Process results received from scanner webhook callback.
     *
     * @param array<string, mixed> $payload
     */
    public function processWebhookResults(array $payload): void
    {
        $scanId = (int) ($payload['scan_id'] ?? 0);
        if ($scanId <= 0) {
            $this->logger->warning('Webhook received with invalid scan_id');
            return;
        }

        $scan = $this->scanRepo->findById($scanId);
        if ($scan === null) {
            $this->logger->warning("Webhook for unknown scan {$scanId}");
            return;
        }

        $siteId = (int) $scan['site_id'];
        $results = $payload['results'] ?? [];

        foreach ($results as $result) {
            $url = $result['url'] ?? '';
            $error = $result['error'] ?? null;

            // Find the matching scan_url record
            $scanUrls = $this->scanRepo->getScanUrls($scanId);
            $matchedUrlId = null;
            foreach ($scanUrls as $su) {
                if ($su['url'] === $url) {
                    $matchedUrlId = (int) $su['id'];
                    break;
                }
            }

            if ($error) {
                if ($matchedUrlId !== null) {
                    $this->scanRepo->updateScanUrl($matchedUrlId, [
                        'status' => 'failed',
                        'error_message' => substr($error, 0, 500),
                        'scanned_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                    ]);
                }
                continue;
            }

            // Store detected cookies
            $cookies = $result['cookies'] ?? [];
            $cookiesFound = 0;
            foreach ($cookies as $cookie) {
                $this->scanRepo->addScanCookie($scanId, [
                    'cookie_name' => $cookie['name'] ?? '',
                    'cookie_domain' => $cookie['domain'] ?? null,
                    'category_slug' => $this->categorizeCookie($cookie),
                    'expiry_duration' => $cookie['expiry_duration'] ?? null,
                    'http_only' => ($cookie['http_only'] ?? false) ? 1 : 0,
                    'secure' => ($cookie['secure'] ?? false) ? 1 : 0,
                    'same_site' => $cookie['same_site'] ?? null,
                    'found_on_url' => $url,
                ]);
                $cookiesFound++;
            }

            // Store beacons
            $beacons = $result['beacons'] ?? [];
            $beaconsFound = 0;
            foreach ($beacons as $beacon) {
                $beaconId = $this->scanRepo->upsertBeacon($siteId, [
                    'beacon_url' => $beacon['domain'] ?? $beacon['url'] ?? '',
                    'beacon_type' => $beacon['category'] ?? null,
                ]);
                $this->scanRepo->linkBeaconToScan($beaconId, $scanId);
                $beaconsFound++;
            }

            // Update URL status
            if ($matchedUrlId !== null) {
                $this->scanRepo->updateScanUrl($matchedUrlId, [
                    'status' => 'completed',
                    'cookies_found' => $cookiesFound,
                    'beacons_found' => $beaconsFound,
                    'scanned_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ]);
            }
        }

        // Mark callback received
        $this->scanRepo->updateScan($scanId, ['callback_received' => 1]);

        // Check if all URLs are done
        $pendingCount = $this->scanRepo->countScanUrlsByStatus($scanId, 'pending')
            + $this->scanRepo->countScanUrlsByStatus($scanId, 'scanning');

        if ($pendingCount === 0) {
            $this->completeScan($scanId);
        }
    }

    /**
     * Process client-side beacon scan data (from consent script).
     *
     * @param array<string, mixed> $data
     */
    public function processClientScanData(int $scanId, string $scanUrl, array $data): void
    {
        $scan = $this->scanRepo->findById($scanId);
        if ($scan === null) {
            return;
        }

        $siteId = (int) $scan['site_id'];
        $cookies = $data['c'] ?? $data['cookies'] ?? [];

        foreach ($cookies as $cookieName) {
            if (\is_string($cookieName) && $cookieName !== '') {
                $this->scanRepo->addScanCookie($scanId, [
                    'cookie_name' => $cookieName,
                    'found_on_url' => $scanUrl,
                ]);
            }
        }

        $beacons = $data['beacons'] ?? [];
        foreach ($beacons as $beacon) {
            $url = \is_string($beacon) ? $beacon : ($beacon['url'] ?? '');
            if ($url !== '') {
                $beaconId = $this->scanRepo->upsertBeacon($siteId, [
                    'beacon_url' => $url,
                    'beacon_type' => $beacon['category'] ?? null,
                ]);
                $this->scanRepo->linkBeaconToScan($beaconId, $scanId);
            }
        }
    }

    /**
     * Check for stale scans and handle them (retry or fail).
     */
    public function processStaleScans(): void
    {
        $stale = $this->scanRepo->getStaleScans('in_progress', 2);

        foreach ($stale as $scan) {
            $scanId = (int) $scan['id'];
            $attempts = (int) $scan['scan_attempts'];

            if ($attempts >= self::MAX_ATTEMPTS) {
                $this->scanRepo->updateScan($scanId, [
                    'scan_status' => 'failed',
                    'completed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ]);
                $this->logger->warning("Scan {$scanId} failed after {$attempts} attempts (stale)");
            } else {
                // Re-queue for retry
                $this->scanRepo->updateScan($scanId, ['scan_status' => 'queued']);
                $this->enqueue($scanId);
                $this->logger->info("Scan {$scanId} re-queued (attempt {$attempts}, was stale)");
            }
        }
    }

    /**
     * Process scheduled scans that are due.
     */
    public function processDueScheduledScans(): void
    {
        $due = $this->scanRepo->getDueScheduledScans();

        foreach ($due as $scan) {
            $scanId = (int) $scan['id'];
            $siteId = (int) $scan['site_id'];

            // Skip if site already has an active scan
            if ($this->scanRepo->hasActiveScan($siteId)) {
                continue;
            }

            // Build URL list
            $site = $this->siteRepo->findById($siteId);
            if ($site === null) {
                $this->scanRepo->updateScan($scanId, ['scan_status' => 'cancelled']);
                continue;
            }

            $urls = $this->resolveUrls($siteId, $site, [], []);
            if (empty($urls)) {
                $this->scanRepo->updateScan($scanId, ['scan_status' => 'cancelled']);
                continue;
            }

            // Enforce plan page limit for scheduled scans
            $siteOwner = $site['user_id'] ?? 0;
            if ($siteOwner > 0) {
                $scanLimit = $this->resolveScanLimit((int) $siteOwner);
                if ($scanLimit > 0 && \count($urls) > $scanLimit) {
                    $urls = \array_slice($urls, 0, $scanLimit);
                }
            }

            // Assign server
            $server = $this->scanRepo->getActiveScanServer();

            $this->scanRepo->updateScan($scanId, [
                'scan_status' => 'queued',
                'total_pages' => \count($urls),
                'server_id' => $server !== null ? (int) $server['id'] : null,
            ]);

            $this->scanRepo->createScanUrls($scanId, $urls);
            $this->enqueue($scanId);

            $this->logger->info("Scheduled scan {$scanId} queued for site {$siteId}");

            // If monthly, create next month's scan
            if (($scan['frequency'] ?? '') === 'monthly') {
                $nextDate = (new \DateTimeImmutable($scan['schedule_date'] ?? 'now'))
                    ->modify('+1 month')
                    ->format('Y-m-d');

                $this->scanRepo->createScan([
                    'site_id' => $siteId,
                    'scan_type' => 'full',
                    'scan_status' => 'scheduled',
                    'is_scheduled' => 1,
                    'is_monthly_scan' => 1,
                    'frequency' => 'monthly',
                    'schedule_date' => $nextDate,
                    'schedule_time' => $scan['schedule_time'] ?? '03:00:00',
                    'firstparty_url' => $scan['firstparty_url'] ?? '',
                ]);
            }
        }
    }

    // ── Private Helpers ──────────────────────────────────

    private function completeScan(int $scanId): void
    {
        $totalCookies = $this->scanRepo->countScanCookies($scanId);
        $totalBeacons = $this->scanRepo->countBeaconsByScan($scanId);

        // Count unique categories
        $cookies = $this->scanRepo->getScanCookies($scanId);
        $categories = [];
        foreach ($cookies as $c) {
            $cat = $c['category_slug'] ?: 'unclassified';
            $categories[$cat] = true;
        }

        $this->scanRepo->updateScan($scanId, [
            'scan_status' => 'completed',
            'completed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'total_cookies' => $totalCookies,
            'total_scripts' => $totalBeacons,
            'total_categories' => \count($categories),
        ]);

        $this->logger->info("Scan {$scanId} completed: {$totalCookies} cookies, {$totalBeacons} beacons, " . \count($categories) . ' categories');
    }

    /**
     * @param array<string, mixed> $scan
     */
    private function handleScanFailure(int $scanId, array $scan, string $error): void
    {
        $attempts = ((int) ($scan['scan_attempts'] ?? 0)) + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->scanRepo->updateScan($scanId, [
                'scan_status' => 'failed',
                'completed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'scan_attempts' => $attempts,
            ]);
        } else {
            // Re-queue for retry with backoff
            $this->scanRepo->updateScan($scanId, [
                'scan_status' => 'queued',
                'scan_attempts' => $attempts,
            ]);
            $this->enqueue($scanId);
        }
    }

    /**
     * Resolve URLs to scan for a site.
     *
     * @param array<string, mixed> $site
     * @param array<int, string> $includeUrls
     * @param array<int, string> $excludeUrls
     * @return array<int, string>
     */
    private function resolveUrls(int $siteId, array $site, array $includeUrls, array $excludeUrls): array
    {
        if (!empty($includeUrls)) {
            $urls = $includeUrls;
        } else {
            // Get URLs from site_urls table
            $urls = $this->getSiteUrls($siteId, $site);
        }

        // Normalize and deduplicate
        $normalized = [];
        foreach ($urls as $url) {
            $url = $this->normalizeUrl($url);
            if ($url !== '' && !\in_array($url, $normalized, true)) {
                $normalized[] = $url;
            }
        }

        // Apply exclusions
        if (!empty($excludeUrls)) {
            $normalized = array_values(array_filter($normalized, function ($url) use ($excludeUrls) {
                foreach ($excludeUrls as $exclude) {
                    if (str_contains($url, $exclude)) {
                        return false;
                    }
                }
                return true;
            }));
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $site
     * @return array<int, string>
     */
    private function getSiteUrls(int $siteId, array $site): array
    {
        $domain = $site['domain'] ?? '';
        $baseUrl = $this->buildSiteUrl($domain);

        // Try to get URLs from oci_site_urls table
        try {
            $rows = $this->scanRepo->findById($siteId); // Use site repo for URLs
            // For now, fallback to just the homepage
        } catch (\Throwable) {
            // Table may not exist yet
        }

        // Fallback: scan the homepage
        return [$baseUrl];
    }

    /**
     * Build a full URL from a domain, ensuring the protocol is correct.
     * Unlike ltrim($domain, 'https://') which strips individual characters,
     * this properly checks for and prepends the protocol prefix.
     */
    private function buildSiteUrl(string $domain): string
    {
        $domain = trim($domain);
        if ($domain === '') {
            return '';
        }

        if (str_starts_with($domain, 'http://') || str_starts_with($domain, 'https://')) {
            return $domain;
        }

        return 'https://' . $domain;
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        // Ensure protocol
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            $url = 'https://' . $url;
        }

        // Remove query parameters
        $parsed = parse_url($url);
        if ($parsed === false) {
            return '';
        }

        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $path = $parsed['path'] ?? '/';

        if ($host === '') {
            return '';
        }

        // Remove index files
        $path = preg_replace('#/index\.(php|html|htm)$#', '/', $path);

        // Ensure trailing slash for root paths
        if ($path === '') {
            $path = '/';
        }

        return $scheme . '://' . $host . $path;
    }

    /**
     * Basic cookie categorization based on name patterns.
     * Used as a fallback when no global cookie reference match is found.
     *
     * @param array<string, mixed> $cookie
     */
    private function categorizeCookie(array $cookie): ?string
    {
        $name = strtolower($cookie['name'] ?? '');
        $domain = strtolower($cookie['domain'] ?? '');

        // Conzent CMP cookies — always necessary (our own consent management cookies)
        if (preg_match('/^(conzentconsent|conzentconsentprefs|conzent_id|euconsent|lastreneweddate|wp_consent_)/', $name)) {
            return 'necessary';
        }

        // Conzent domain — all cookies from conzent.net are necessary
        if (str_contains($domain, 'conzent.net') || str_contains($domain, 'conzent.com')) {
            return 'necessary';
        }

        // Necessary cookies
        if (preg_match('/^(csrf|xsrf|session|phpsessid|jsessionid|asp\.net_session|__host-|__secure-)/', $name)) {
            return 'necessary';
        }

        // Analytics cookies
        if (preg_match('/^(_ga|_gid|_gat|_gac_|__utm|_hjid|_hjSession|_clck|_clsk|mp_|amplitude)/', $name)) {
            return 'analytics';
        }

        // Marketing cookies
        if (preg_match('/^(_fbp|_fbc|_gcl_|_uet|_tt_|IDE|MUID|fr|_pinterest)/', $name)) {
            return 'marketing';
        }

        // Functional cookies
        if (preg_match('/^(lang|locale|currency|timezone|consent|cookieconsent|cc_cookie)/', $name)) {
            return 'functional';
        }

        // Domain-based classification
        if (str_contains($domain, 'google') || str_contains($domain, 'youtube')) {
            return str_contains($name, 'consent') ? 'necessary' : 'analytics';
        }
        if (str_contains($domain, 'facebook') || str_contains($domain, 'meta')) {
            return 'marketing';
        }

        return null; // unclassified
    }

    /**
     * Call the scanner server's HTTP API.
     *
     * @param array<string, mixed> $server
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function callScannerApi(array $server, string $endpoint, array $data): ?array
    {
        $url = rtrim($server['server_url'], '/') . $endpoint;
        $apiKey = $server['api_key'] ?? '';
        $payload = json_encode($data, JSON_THROW_ON_ERROR);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'X-Api-Key: ' . $apiKey,
                    'Content-Length: ' . \strlen($payload),
                ]),
                'content' => $payload,
                'timeout' => 30,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $this->logger->error("Scanner API call failed: {$url}");
            return null;
        }

        try {
            return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->error("Scanner API returned invalid JSON: {$e->getMessage()}");
            return null;
        }
    }

    private function resolveScanLimit(int $userId): int
    {
        if ($this->planRepo->isEnterprise($userId)) {
            return 0;
        }

        $userPlan = $this->planRepo->getUserPlan($userId);
        if ($userPlan === null) {
            return 100;
        }

        $planKey = $userPlan['plan_key'] ?? null;
        if ($planKey !== null) {
            $limit = $this->pricingService !== null ? $this->pricingService->getLimit($planKey, 'pages_per_scan') : 0;
            return $limit > 0 ? $limit : 0;
        }

        return 100;
    }

    private function enqueue(int $scanId): void
    {
        $this->redis->lpush(self::QUEUE_KEY, [(string) $scanId]);
    }

    private function dequeue(): ?string
    {
        // Blocking pop with 5-second timeout
        $result = $this->redis->brpop([self::QUEUE_KEY], 5);

        return $result !== null ? $result[1] : null;
    }

    /**
     * @param array<int, mixed> $arr
     */
    private function count(array $arr): int
    {
        return \count($arr);
    }
}
