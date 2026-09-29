<?php

declare(strict_types=1);

namespace OCI\Scanning\Service;

use OCI\Cookie\Repository\CookieRepositoryInterface;
use OCI\Cookie\Service\CookieRegisterDiffService;
use OCI\Monetization\Service\PricingService;
use OCI\Report\Service\ReportService;
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

    private const PRE_CONSENT = ScanResultNormalizer::PRE_CONSENT;
    private const POST_CONSENT = ScanResultNormalizer::POST_CONSENT;

    /** Categories that have no business being set before a visitor consents. */
    private const NON_NECESSARY = ['functional', 'preferences', 'analytics', 'performance', 'marketing'];

    /**
     * @param \Closure(array<string, mixed> $server, string $endpoint, array<string, mixed> $data): ?array<string, mixed>|null $scannerHttp
     *        Replaces the HTTP call to the scanner. Tests only; production leaves it null.
     */
    public function __construct(
        private readonly ScanRepositoryInterface $scanRepo,
        private readonly SiteRepositoryInterface $siteRepo,
        private readonly PlanRepositoryInterface $planRepo,
        private readonly RedisClient $redis,
        private readonly LoggerInterface $logger,
        private readonly ReportService $reportService,
        private readonly CookieRegisterDiffService $registerDiff,
        private readonly CookieRepositoryInterface $cookieRepo,
        private readonly ?PricingService $pricingService = null,
        private readonly ?\Closure $scannerHttp = null,
    ) {}

    // ── Public API (called by handlers) ──────────────────

    /**
     * Initiate a new scan for a site.
     *
     * @param array<int, string> $includeUrls Specific URLs to scan (empty = use site_urls)
     * @param array<int, string> $excludeUrls URLs to exclude
     * @param string|null $initiatorRole The role of the person who started it, or of the
     *        admin impersonating them. Decides whether the scan also runs after consent.
     * @return array{scan_id: int, message: string}
     */
    public function initiateScan(
        int $siteId,
        int $userId,
        string $scanType = 'full',
        array $includeUrls = [],
        array $excludeUrls = [],
        ?string $initiatorRole = null,
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
        ] + $this->consentPhasesColumn($initiatorRole));

        // Create per-URL tracking records
        $this->scanRepo->createScanUrls($scanId, $urls);

        // Push to Redis queue for worker to pick up
        $this->enqueue($scanId);

        $this->logger->info("Scan {$scanId} queued for site {$siteId} ({$site['domain']}), " . \count($urls) . ' URLs');

        return [
            'scan_id' => $scanId,
            'total_urls' => \count($urls),
            'message' => 'Scan queued successfully',
        ];
    }

    /**
     * Queue the first scan for a freshly created site.
     *
     * Runs inside site creation, so it must never throw: any failure is
     * logged and the site creation proceeds. Returns the scan id, or null
     * when nothing could be queued (the dashboard's scan step offers a
     * manual start in that case).
     */
    public function queueFirstScan(int $siteId): ?int
    {
        try {
            if ($this->scanRepo->hasActiveScan($siteId)) {
                return null;
            }

            $site = $this->siteRepo->findById($siteId);
            if ($site === null) {
                return null;
            }

            $urls = $this->resolveUrls($siteId, $site, [], []);
            if (empty($urls)) {
                return null;
            }

            $ownerId = (int) ($site['user_id'] ?? 0);
            if ($ownerId > 0) {
                $scanLimit = $this->resolveScanLimit($ownerId);
                if ($scanLimit > 0 && \count($urls) > $scanLimit) {
                    $urls = \array_slice($urls, 0, $scanLimit);
                }
            }

            $server = $this->scanRepo->getActiveScanServer();

            $scanId = $this->scanRepo->createScan([
                'site_id' => $siteId,
                'scan_type' => 'full',
                'scan_status' => 'queued',
                'is_first_scan' => 1,
                'is_scheduled' => 0,
                'firstparty_url' => $this->buildSiteUrl((string) $site['domain']),
                'total_pages' => \count($urls),
                'server_id' => $server !== null ? (int) $server['id'] : null,
                'scan_location' => $server !== null ? (int) $server['id'] : 0,
            ] + $this->consentPhasesColumn(null));

            $this->scanRepo->createScanUrls($scanId, $urls);
            $this->enqueue($scanId);

            $this->logger->info("First scan {$scanId} queued for new site {$siteId}");

            return $scanId;
        } catch (\Throwable $e) {
            $this->logger->error('First scan could not be queued', [
                'site_id' => $siteId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Lightweight status of a site's most recent real scan, for UI polling.
     *
     * Skips future scheduled placeholder rows — the monthly schedule created
     * alongside a new site must not mask the first scan's progress.
     *
     * @return array{scan_id: ?int, status: string, urls_completed: int, total_pages: int,
     *               total_cookies: int, total_categories: int, has_completed_scan: bool}
     */
    public function getLatestScanStatus(int $siteId): array
    {
        $latest = null;
        foreach ($this->scanRepo->findBySite($siteId, 10, 0) as $scan) {
            if (($scan['scan_status'] ?? '') === 'scheduled') {
                continue;
            }
            $latest = $scan;
            break;
        }

        $hasCompleted = $this->scanRepo->getLastCompletedScan($siteId) !== null;

        if ($latest === null) {
            return [
                'scan_id' => null,
                'status' => 'none',
                'urls_completed' => 0,
                'total_pages' => 0,
                'total_cookies' => 0,
                'total_categories' => 0,
                'has_completed_scan' => $hasCompleted,
            ];
        }

        $scanId = (int) $latest['id'];
        $status = (string) $latest['scan_status'];

        $urlsCompleted = 0;
        if (\in_array($status, ['queued', 'in_progress'], true)) {
            $urlsCompleted = $this->scanRepo->countScanUrlsByStatus($scanId, 'completed');
        }

        return [
            'scan_id' => $scanId,
            'status' => $status,
            'urls_completed' => $urlsCompleted,
            'total_pages' => (int) ($latest['total_pages'] ?? 0),
            'total_cookies' => (int) ($latest['total_cookies'] ?? 0),
            'total_categories' => (int) ($latest['total_categories'] ?? 0),
            'has_completed_scan' => $hasCompleted,
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

        $urls = $this->scanRepo->getScanUrls($scanId);
        $phased = null;

        if ($this->isTwoPhase($scan)) {
            $split = $this->phaseCookies((int) $scan['site_id'], $scanId);
            $pre = $split['pre'];
            $post = $split['post'];

            $phased = [
                'inventory_complete' => (int) ($scan['inventory_complete'] ?? 0) === 1,
                'pre' => [
                    'cookies' => $pre,
                    'beacons' => $this->scanRepo->getBeaconsByScanAndPhase($scanId, self::PRE_CONSENT),
                ],
                'post' => [
                    'cookies' => $post,
                    'beacons' => $this->scanRepo->getBeaconsByScanAndPhase($scanId, self::POST_CONSENT),
                ],
                'new_after_consent' => $split['new_after_consent'],
                'leaks' => $split['leaks'],
                'unclassified' => $split['unclassified'],
                'observed_elsewhere' => $this->observedAfterDecisionOnly((int) $scan['site_id'], $post),
            ];

            // The rest of the page reads the inventory: after consent when the
            // scan has it, otherwise what it saw before consent.
            $cookies = $post !== [] ? $post : $pre;
            $beacons = $post !== [] ? $phased['post']['beacons'] : $phased['pre']['beacons'];
        } else {
            $cookies = $this->scanRepo->getScanCookies($scanId);
            $beacons = $this->scanRepo->getBeaconsByScan($scanId);
        }

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
            'phased' => $phased,
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

        // Build callback URL for the scanner to POST results back.
        // SCANNER_CALLBACK_URL lets an in-compose scanner reply over the internal
        // network (http://nginx) instead of looping out through the public domain.
        // Empty values fall through to APP_URL — an unset-but-present env var is
        // an empty string, not null, so ?? alone would yield a bare path here.
        $callbackBase = trim((string) ($_ENV['SCANNER_CALLBACK_URL'] ?? ''));
        if ($callbackBase === '') {
            $callbackBase = trim((string) ($_ENV['APP_URL'] ?? ''));
        }
        if ($callbackBase === '') {
            $callbackBase = 'http://localhost:8100';
        }
        $callbackUrl = rtrim($callbackBase, '/') . '/api/v1/scan-webhook';

        $request = [
            'scan_id' => $scanId,
            'urls' => $urlStrings,
            'callback_url' => $callbackUrl,
            'options' => $this->scanOptions($scan),
        ];

        // The webhook checks X-Api-Key whenever the secret is set; the
        // scanner sends back whatever it is given here.
        $webhookSecret = trim((string) ($_ENV['SCANNER_WEBHOOK_SECRET'] ?? ''));
        if ($webhookSecret !== '') {
            $request['callback_api_key'] = $webhookSecret;
        }

        // Mark URLs as scanning before dispatch. The webhook only accepts
        // results for scanning URLs, and the scanner starts work as soon as it
        // accepts the batch, so a page that fails at once could call back
        // before URLs marked afterwards were ready for it.
        foreach ($urls as $url) {
            $this->scanRepo->updateScanUrl((int) $url['id'], ['status' => 'scanning']);
        }

        // Send batch scan request to scanner server
        $response = $this->callScannerApi($server, '/scan/batch', $request);

        if ($response === null || !($response['success'] ?? false)) {
            // Back to pending, or the retry would find nothing to send.
            foreach ($urls as $url) {
                $this->scanRepo->updateScanUrl((int) $url['id'], ['status' => 'pending']);
            }
            throw new \RuntimeException('Scanner API returned error: ' . json_encode($response));
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

        // Results only land on a scan that is waiting for them. A callback for
        // a cancelled scan used to store its results and then complete it; one
        // arriving after a stale retry re-queued the scan belongs to a dispatch
        // that no longer counts, and the retry brings its own.
        if (($scan['scan_status'] ?? '') !== 'in_progress') {
            $this->logger->warning("Webhook for scan {$scanId} ignored: scan is {$scan['scan_status']}");
            return;
        }

        $siteId = (int) $scan['site_id'];
        $results = \is_array($payload['results'] ?? null) ? $payload['results'] : [];
        $twoPhase = $this->isTwoPhase($scan);

        foreach ($results as $result) {
            if (!\is_array($result)) {
                continue;
            }
            $normalized = ScanResultNormalizer::normalize($result, $twoPhase);
            $url = $normalized['url'];
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            // Only a URL still being scanned takes results. That is what makes
            // a repeated callback harmless: the first one completes the URL,
            // and a second finds nothing to write to.
            $matchedUrlId = null;
            foreach ($this->scanRepo->getScanUrls($scanId, 'scanning') as $su) {
                if ($su['url'] === $url) {
                    $matchedUrlId = (int) $su['id'];
                    break;
                }
            }
            if ($matchedUrlId === null) {
                $this->logger->info("Webhook result for scan {$scanId} skipped: no URL awaiting {$url}");
                continue;
            }

            if ($normalized['error'] !== null) {
                $this->scanRepo->updateScanUrl($matchedUrlId, [
                    'status' => 'failed',
                    'error_message' => $normalized['error'],
                    'scanned_at' => $now,
                ]);
                continue;
            }

            $prePhase = $twoPhase ? self::PRE_CONSENT : null;
            $cookiesFound = $this->storeCookies($scanId, $url, $normalized['pre']['cookies'], $prePhase);
            $beaconsFound = $this->storeBeacons($siteId, $scanId, $normalized['pre']['beacons'], $prePhase);

            $urlUpdate = [
                'status' => 'completed',
                'cookies_found' => $cookiesFound,
                'beacons_found' => $beaconsFound,
                'scanned_at' => $now,
            ];

            if ($twoPhase) {
                $post = $normalized['post'];

                // An unverified after-consent pass is recorded, never stored as
                // cookies: its rows would otherwise become the site's public
                // declaration on the strength of a consent that did not hold.
                if ($post !== null && $post['verified']) {
                    $this->storeCookies($scanId, $url, $post['cookies'], self::POST_CONSENT);
                    $this->storeBeacons($siteId, $scanId, $post['beacons'], self::POST_CONSENT);
                }

                $urlUpdate += [
                    'post_consent_status' => $normalized['post_status'],
                    'consent_method' => $post['method'] ?? null,
                    'post_consent_error' => $post !== null
                        ? (mb_substr((string) ($post['error'] ?? ($post['verified'] ? '' : $post['reason'])), 0, 500) ?: null)
                        : 'The scanner does not support scanning after consent yet.',
                    'final_url' => $post['final_url'] ?? null,
                    'diagnostics' => $post !== null && $post['diagnostics'] !== []
                        ? json_encode($post['diagnostics'], JSON_UNESCAPED_SLASHES)
                        : null,
                ];
            }

            $this->scanRepo->updateScanUrl($matchedUrlId, $urlUpdate);
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
        // This path is unauthenticated and scan ids are sequential. Only a scan
        // that is actually running takes data, or anyone could append cookies
        // to a finished scan and through it to a site's public declaration.
        if ($scan === null || ($scan['scan_status'] ?? '') !== 'in_progress') {
            return;
        }

        // These rows carry no phase. In a two-phase scan a phase-less row would
        // make the scan read as a single-phase one, and its rows could become
        // the site's inventory without any verified pass. The scanner collects
        // both passes itself; the runtime's own scan beacons arrive through
        // /api/v1/scan_data with their phase.
        if ($this->isTwoPhase($scan)) {
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
            $rawUrl = \is_string($beacon) ? $beacon : (string) ($beacon['url'] ?? '');
            $url = $this->beaconIdentity($rawUrl, \is_array($beacon) ? (string) ($beacon['domain'] ?? '') : '');
            if ($url !== '') {
                $beaconId = $this->scanRepo->upsertBeacon($siteId, [
                    'beacon_url' => $url,
                    'beacon_type' => \is_array($beacon) ? ($beacon['type'] ?? $beacon['category'] ?? null) : null,
                ]);
                $this->scanRepo->linkBeaconToScan($beaconId, $scanId);
            }
        }
    }

    /**
     * A beacon's stored identity: host + path, no scheme, no query string.
     * The path is what tells a tracking pixel from a library; the query is
     * per-visitor noise that would explode row cardinality if kept.
     */
    private function beaconIdentity(string $url, string $domain): string
    {
        $url = trim($url);
        if ($url === '') {
            return trim($domain);
        }

        $parts = parse_url(str_contains($url, '://') ? $url : 'http://' . $url);
        $host = (string) ($parts['host'] ?? '');
        if ($host === '') {
            return trim($domain);
        }

        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        return mb_substr($host . $path, 0, 500);
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

            // A scan of many pages, or one that also scans after consent, is
            // legitimately quiet for longer than two hours. Re-dispatching it
            // while the scanner still works on it runs every URL twice.
            $updatedAt = strtotime((string) ($scan['updated_at'] ?? '')) ?: 0;
            $quietHours = $updatedAt > 0 ? (time() - $updatedAt) / 3600 : PHP_INT_MAX;
            if ($quietHours < $this->staleAfterHours($scan)) {
                continue;
            }

            if ($attempts >= self::MAX_ATTEMPTS) {
                $this->scanRepo->updateScan($scanId, [
                    'scan_status' => 'failed',
                    'completed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ]);
                $this->logger->warning("Scan {$scanId} failed after {$attempts} attempts (stale)");
            } else {
                // Re-queue for retry, from a clean slate: URLs back to pending
                // (the dispatcher only sends pending ones) and nothing kept
                // from the attempt that went quiet.
                $this->scanRepo->resetScanForRetry($scanId);
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
            ] + $this->consentPhasesColumn(null));

            $this->scanRepo->createScanUrls($scanId, $urls);
            $this->enqueue($scanId);

            $this->logger->info("Scheduled scan {$scanId} queued for site {$siteId}");

            // If monthly, schedule the next occurrence — same day-of-month,
            // advanced past today so it can't immediately re-qualify as due.
            if (($scan['frequency'] ?? '') === 'monthly') {
                $baseDate = !empty($scan['schedule_date'])
                    ? (string) $scan['schedule_date']
                    : (new \DateTimeImmutable())->format('Y-m-d');
                $nextDate = $this->nextMonthlyScheduleDate($baseDate);

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
        $current = $this->scanRepo->findById($scanId);
        // Completing twice sent the register-change alert and the scheduled
        // report twice. A scan that is already completed stays as it was.
        if ($current === null || ($current['scan_status'] ?? '') === 'completed') {
            return;
        }

        if ($this->isTwoPhase($current)) {
            $totals = $this->twoPhaseTotals((int) $current['site_id'], $scanId);
            $totalCookies = $totals['total_cookies'];
            $totalBeacons = $totals['total_scripts'];
            $categoryCount = $totals['total_categories'];

            $this->scanRepo->updateScan($scanId, [
                'scan_status' => 'completed',
                'completed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ] + $totals);
        } else {
            $totalCookies = $this->scanRepo->countScanCookies($scanId);
            $totalBeacons = $this->scanRepo->countBeaconsByScan($scanId);

            // Count unique categories
            $cookies = $this->scanRepo->getScanCookies($scanId);
            $categories = [];
            foreach ($cookies as $c) {
                $cat = $c['category_slug'] ?: 'unclassified';
                $categories[$cat] = true;
            }
            $categoryCount = \count($categories);

            $this->scanRepo->updateScan($scanId, [
                'scan_status' => 'completed',
                'completed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'total_cookies' => $totalCookies,
                'total_scripts' => $totalBeacons,
                'total_categories' => $categoryCount,
            ]);
        }

        $this->logger->info("Scan {$scanId} completed: {$totalCookies} cookies, {$totalBeacons} beacons, {$categoryCount} categories");

        // Register change log: snapshot the effective register and diff it
        // against the previous snapshot — for EVERY completed scan, manual or
        // scheduled (the register changes either way). Must never fail a scan.
        $scanRow = $this->scanRepo->findById($scanId);
        if ($scanRow !== null) {
            try {
                $result = $this->registerDiff->snapshotAndDiff((int) $scanRow['site_id'], $scanId);
                if ($result['changes'] !== []) {
                    $this->logger->info('Register changes detected', [
                        'scan_id' => $scanId,
                        'site_id' => $scanRow['site_id'],
                        'changes' => \count($result['changes']),
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger->error('Register snapshot/diff failed', [
                    'scan_id' => $scanId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // A scheduled scan runs unattended, so its result is delivered: generate
        // a scan report and email it to the site owner. A report failure must
        // never fail the completed scan.
        $scan = $this->scanRepo->findById($scanId);
        if ($scan !== null && (int) ($scan['is_scheduled'] ?? 0) === 1) {
            try {
                $this->reportService->sendScheduledScanReport((int) $scan['site_id']);
            } catch (\Throwable $e) {
                $this->logger->error('Scheduled scan report delivery failed', [
                    'scan_id' => $scanId,
                    'site_id' => $scan['site_id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
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
     * Compute the next monthly occurrence after a scheduled scan fires.
     *
     * Preserves the original day-of-month (the 10th → the 10th next month),
     * clamping to the month's last day for short months so a scan set for the
     * 31st doesn't drift forward via PHP's "+1 month" overflow (Jan 31 + 1
     * month would otherwise land on Mar 3). Advances past today so a scheduler
     * outage can't leave a back-dated date that fires repeatedly to catch up.
     */
    private function nextMonthlyScheduleDate(string $currentDate): string
    {
        $desiredDay = (int) (new \DateTimeImmutable($currentDate))->format('d');
        $today = new \DateTimeImmutable('today');

        $next = new \DateTimeImmutable($currentDate);
        do {
            // Jump to the 1st of the following month (day-agnostic, so no
            // overflow), then clamp the desired day to that month's length.
            $firstOfNext = $next->modify('first day of next month');
            $daysInMonth = (int) $firstOfNext->format('t');
            $next = $firstOfNext->setDate(
                (int) $firstOfNext->format('Y'),
                (int) $firstOfNext->format('n'),
                min($desiredDay, $daysInMonth),
            );
        } while ($next <= $today);

        return $next->format('Y-m-d');
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
    private function categorizeCookie(array $cookie, string $pageUrl = ''): ?string
    {
        $name = strtolower($cookie['name'] ?? '');
        $domain = strtolower($cookie['domain'] ?? '');

        // Conzent CMP cookies — always necessary (our own consent management cookies)
        if (preg_match('/^(conzentconsent|conzentconsentprefs|conzent_id|euconsent|lastreneweddate|wp_consent_)/', $name)) {
            return 'necessary';
        }

        // Cookies Conzent's service sets on a customer's page are necessary;
        // a Conzent website's own analytics cookies are not.
        if (ConzentCookieDomain::isServiceCookieOnAnotherSite($domain, $pageUrl)) {
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
        if ($this->scannerHttp !== null) {
            return ($this->scannerHttp)($server, $endpoint, $data);
        }

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

    // ── Two-phase scans ──────────────────────────────────

    /**
     * Which passes a new scan runs.
     *
     * `SCAN_CONSENT_PHASES` decides: `admin` (the default) runs the
     * after-consent pass only for scans an admin starts, `all` runs it for
     * every scan, `off` for none. Scheduled and first scans have no one who
     * started them, so only `all` reaches them.
     *
     * @return list<string>
     */
    public function resolveConsentPhases(?string $initiatorRole): array
    {
        $mode = strtolower(trim((string) ($_ENV['SCAN_CONSENT_PHASES'] ?? 'admin')));

        $twoPhase = match ($mode) {
            'off' => false,
            'all' => true,
            default => $initiatorRole === 'admin',
        };

        return $twoPhase ? [self::PRE_CONSENT, self::POST_CONSENT] : [self::PRE_CONSENT];
    }

    /**
     * The `consent_phases` column for a new scan. Left out entirely for a
     * single-phase scan, so that a scan created while a deploy is still
     * running its migrations never names a column that may not exist yet.
     *
     * @return array<string, string>
     */
    private function consentPhasesColumn(?string $initiatorRole): array
    {
        $phases = $this->resolveConsentPhases($initiatorRole);

        return \count($phases) > 1 ? ['consent_phases' => implode(',', $phases)] : [];
    }

    /**
     * @param array<string, mixed> $scan
     */
    private function isTwoPhase(array $scan): bool
    {
        return str_contains((string) ($scan['consent_phases'] ?? ''), self::POST_CONSENT);
    }

    /**
     * Options sent to the scanner for one scan.
     *
     * Every scan now carries `blockUrls`, the runtime's write endpoints on this
     * installation. Until now each scan loaded the customer's banner like a
     * visitor, so it counted a pageview against their quota and wrote cookie
     * observations; the scanner answers those calls itself. A scanner that
     * predates the option ignores it and behaves as before.
     *
     * @param array<string, mixed> $scan
     * @return array<string, mixed>
     */
    private function scanOptions(array $scan): array
    {
        $options = [
            'waitForNetworkIdle' => true,
            'extraWait' => 3000,
        ];

        $blockUrls = $this->runtimeWriteEndpoints();
        if ($blockUrls !== []) {
            $options['blockUrls'] = $blockUrls;
        }

        if ($this->isTwoPhase($scan)) {
            $site = $this->siteRepo->findById((int) $scan['site_id']) ?? [];
            $options['consentPhases'] = [self::PRE_CONSENT, self::POST_CONSENT];
            $options['consentContext'] = [
                'banner_delay_ms' => (int) ($site['banner_delay_ms'] ?? 0),
                'consent_sharing_enabled' => (int) ($site['consent_sharing_enabled'] ?? 1),
            ];
        }

        return $options;
    }

    /**
     * The endpoints the banner runtime writes to, as its bundle names them:
     * `APP_URL` plus `/api/v1`. Built from APP_URL and never from the callback
     * URL, which may be an internal host the scanner's browser never sees. The
     * scanner also matches these paths on any host during an after-consent
     * pass, so a wrong APP_URL cannot let a consent through.
     *
     * @return list<string>
     */
    private function runtimeWriteEndpoints(): array
    {
        $base = rtrim(trim((string) ($_ENV['APP_URL'] ?? '')), '/');
        if ($base === '') {
            return [];
        }

        return [$base . '/api/v1/log', $base . '/api/v1/consent', $base . '/api/v1/scan_data'];
    }

    /**
     * @param list<array<string, mixed>> $cookies
     */
    private function storeCookies(int $scanId, string $url, array $cookies, ?string $phase): int
    {
        $stored = 0;
        foreach ($cookies as $cookie) {
            $row = [
                'cookie_name' => (string) $cookie['name'],
                'cookie_domain' => $cookie['domain'] ?? null,
                'category_slug' => $this->categorizeCookie($cookie, $url),
                'expiry_duration' => $cookie['expiry_duration'] ?? null,
                'http_only' => ($cookie['http_only'] ?? false) ? 1 : 0,
                'secure' => ($cookie['secure'] ?? false) ? 1 : 0,
                'same_site' => $cookie['same_site'] ?? null,
                'found_on_url' => $url,
            ];
            if ($phase !== null) {
                $row['source'] = 'server';
                $row['consent_phase'] = $phase;
            }
            $this->scanRepo->addScanCookie($scanId, $row);
            $stored++;
        }

        return $stored;
    }

    /**
     * Beacons are stored by host + path — the actual script or pixel, not just
     * its domain — with the query string stripped so per-visitor parameters
     * cannot explode row cardinality.
     *
     * @param list<array<string, mixed>> $beacons
     */
    private function storeBeacons(int $siteId, int $scanId, array $beacons, ?string $phase): int
    {
        $stored = 0;
        foreach ($beacons as $beacon) {
            $beaconUrl = $this->beaconIdentity(
                (string) ($beacon['url'] ?? ''),
                (string) ($beacon['domain'] ?? ''),
            );
            if ($beaconUrl === '') {
                continue; // a beacon with no URL is not real
            }
            $beaconId = $this->scanRepo->upsertBeacon($siteId, [
                'beacon_url' => $beaconUrl,
                'beacon_type' => $beacon['type'] ?? $beacon['category'] ?? null,
            ]);
            $this->scanRepo->linkBeaconToScan($beaconId, $scanId, $phase);
            $stored++;
        }

        return $stored;
    }

    /**
     * What a two-phase scan found, as the columns `oci_scans` stores.
     *
     * `total_cookies`, `total_scripts` and `total_categories` describe the
     * inventory: after consent when the scan has it, what it saw before
     * consent otherwise. `inventory_complete` is set only when every URL that
     * completed also has a verified after-consent pass, because it is the
     * flag that lets this scan replace the site's public cookie declaration.
     *
     * @return array<string, int>
     */
    private function twoPhaseTotals(int $siteId, int $scanId): array
    {
        $split = $this->phaseCookies($siteId, $scanId);
        $pre = $split['pre'];
        $post = $split['post'];

        $inventory = $post !== [] ? $post : $pre;
        $categories = [];
        foreach ($inventory as $c) {
            $categories[(string) ($c['category_slug'] ?: 'unclassified')] = true;
        }

        $preBeacons = \count($this->scanRepo->getBeaconsByScanAndPhase($scanId, self::PRE_CONSENT));
        $postBeacons = \count($this->scanRepo->getBeaconsByScanAndPhase($scanId, self::POST_CONSENT));

        $completed = 0;
        $verified = 0;
        foreach ($this->scanRepo->getScanUrls($scanId) as $u) {
            if (($u['status'] ?? '') !== 'completed') {
                continue;
            }
            $completed++;
            if (($u['post_consent_status'] ?? '') === ScanResultNormalizer::STATUS_VERIFIED) {
                $verified++;
            }
        }

        return [
            'total_cookies' => \count($inventory),
            'total_scripts' => $post !== [] ? $postBeacons : $preBeacons,
            'total_categories' => \count($categories),
            'pre_consent_cookies' => \count($pre),
            'post_consent_cookies' => \count($post),
            'pre_consent_beacons' => $preBeacons,
            'post_consent_beacons' => $postBeacons,
            'pre_consent_leaks' => \count($split['leaks']),
            'pre_consent_unclassified' => \count($split['unclassified']),
            'inventory_complete' => ($completed > 0 && $verified === $completed) ? 1 : 0,
        ];
    }

    /**
     * A two-phase scan's cookies, each once, in the categories the /cookies
     * page would show them in (so a cookie the site owner classified as
     * necessary is not reported as a leak here).
     *
     * A cookie in a known non-necessary category before consent is a leak.
     * Unclassified before consent is a question, not a verdict: necessary
     * platform cookies such as Cloudflare's __cf_bm often carry no category.
     *
     * @return array{pre: list<array<string, mixed>>, post: list<array<string, mixed>>,
     *               leaks: list<array<string, mixed>>, unclassified: list<array<string, mixed>>,
     *               new_after_consent: list<array<string, mixed>>}
     */
    private function phaseCookies(int $siteId, int $scanId): array
    {
        $pre = $this->cookieRepo->resolveCategories(
            $siteId,
            $this->uniqueCookies($this->scanRepo->getScanCookiesByPhase($scanId, self::PRE_CONSENT)),
        );
        $post = $this->cookieRepo->resolveCategories(
            $siteId,
            $this->uniqueCookies($this->scanRepo->getScanCookiesByPhase($scanId, self::POST_CONSENT)),
        );

        $preNames = [];
        foreach ($pre as $c) {
            $preNames[strtolower((string) $c['cookie_name'])] = true;
        }

        return [
            'pre' => $pre,
            'post' => $post,
            'leaks' => array_values(array_filter(
                $pre,
                static fn (array $c): bool => \in_array((string) ($c['category_slug'] ?? ''), self::NON_NECESSARY, true),
            )),
            'unclassified' => array_values(array_filter(
                $pre,
                static fn (array $c): bool => \in_array((string) ($c['category_slug'] ?? ''), ['', 'unclassified'], true),
            )),
            'new_after_consent' => array_values(array_filter(
                $post,
                static fn (array $c): bool => !isset($preNames[strtolower((string) $c['cookie_name'])]),
            )),
        ];
    }

    /**
     * Cookies real visitors' browsers reported after a consent decision that
     * this scan did not see after Accept All. The decision includes people who
     * rejected, so these are not "after Accept All" cookies; they are what the
     * scan may have missed (a page it did not visit, a login, a basket).
     *
     * @param list<array<string, mixed>> $post
     * @return list<array<string, mixed>>
     */
    private function observedAfterDecisionOnly(int $siteId, array $post): array
    {
        $seen = [];
        foreach ($post as $c) {
            $seen[strtolower((string) $c['cookie_name'])] = true;
        }

        $observed = [];
        foreach ($this->cookieRepo->getObservedCookies($siteId) as $o) {
            $name = strtolower((string) ($o['cookie_name'] ?? ''));
            if ($name === '' || isset($seen[$name]) || (int) ($o['post_consent_count'] ?? 0) === 0) {
                continue;
            }
            $seen[$name] = true;
            $observed[] = $o;
        }

        return $observed;
    }

    /**
     * One row per cookie name and domain. The same cookie set on several
     * scanned pages is still one cookie.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function uniqueCookies(array $rows): array
    {
        $unique = [];
        foreach ($rows as $row) {
            $key = strtolower((string) $row['cookie_name']) . '|' . strtolower(ltrim((string) ($row['cookie_domain'] ?? ''), '.'));
            $unique[$key] ??= $row;
        }

        return array_values($unique);
    }

    /**
     * How long a scan may stay quiet before it counts as stale: two hours, or
     * about three minutes a page for larger scans (a page scanned twice, once
     * after consent, plus the waits).
     *
     * @param array<string, mixed> $scan
     */
    private function staleAfterHours(array $scan): float
    {
        $pages = max(1, (int) ($scan['total_pages'] ?? 1));
        $minutesPerPage = $this->isTwoPhase($scan) ? 3 : 2;

        return max(2.0, ($pages * $minutesPerPage) / 60);
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
