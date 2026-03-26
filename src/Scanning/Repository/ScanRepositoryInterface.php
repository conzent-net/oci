<?php

declare(strict_types=1);

namespace OCI\Scanning\Repository;

interface ScanRepositoryInterface
{
    // ── Scan CRUD ────────────────────────────────────────

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $scanId): ?array;

    /**
     * @param array<string, mixed> $data
     */
    public function createScan(array $data): int;

    /**
     * @param array<string, mixed> $data
     */
    public function updateScan(int $scanId, array $data): void;

    // ── Scan Queries ─────────────────────────────────────

    /**
     * Get all scans for a site, ordered newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findBySite(int $siteId, int $limit = 50, int $offset = 0): array;

    public function countBySite(int $siteId): int;

    /**
     * @return array<string, mixed>|null
     */
    public function getLastCompletedScan(int $siteId): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function getNextScheduledScan(int $siteId): ?array;

    /**
     * Check if a site has any active (non-terminal) scan.
     */
    public function hasActiveScan(int $siteId): bool;

    /**
     * Get scans ready for processing by the queue worker.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getQueuedScans(int $limit = 10): array;

    /**
     * Get scans that are in_progress and may need result checking.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getInProgressScans(int $limit = 50): array;

    /**
     * Get scheduled scans that are due to run now.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDueScheduledScans(int $limit = 10): array;

    /**
     * Get stale scans stuck in a status for more than N hours.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getStaleScans(string $status, int $hours = 2, int $limit = 10): array;

    // ── Scan URLs ────────────────────────────────────────

    /**
     * @param array<int, string> $urls
     */
    public function createScanUrls(int $scanId, array $urls): void;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getScanUrls(int $scanId, ?string $status = null): array;

    /**
     * @param array<string, mixed> $data
     */
    public function updateScanUrl(int $urlId, array $data): void;

    public function countScanUrlsByStatus(int $scanId, string $status): int;

    // ── Scan Cookies ─────────────────────────────────────

    /**
     * @param array<string, mixed> $cookie
     */
    public function addScanCookie(int $scanId, array $cookie): int;

    /**
     * Bulk insert scan cookies.
     *
     * @param array<int, array<string, mixed>> $cookies
     */
    public function addScanCookies(int $scanId, array $cookies): void;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getScanCookies(int $scanId): array;

    public function countScanCookies(int $scanId): int;

    /**
     * Get cookie count grouped by category_slug for a scan.
     *
     * @return array<int, array{category_slug: string, total: int}>
     */
    public function getScanCookieBreakdown(int $scanId): array;

    // ── Scan Servers ─────────────────────────────────────

    /**
     * @return array<string, mixed>|null
     */
    public function getActiveScanServer(): ?array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAllScanServers(): array;

    /**
     * @param array<string, mixed> $data
     */
    public function createScanServer(array $data): int;

    /**
     * @param array<string, mixed> $data
     */
    public function updateScanServer(int $serverId, array $data): void;

    public function deleteScanServer(int $serverId): void;

    public function updateServerHeartbeat(int $serverId): void;

    // ── Beacons ──────────────────────────────────────────

    /**
     * @param array<string, mixed> $beacon
     */
    public function upsertBeacon(int $siteId, array $beacon): int;

    public function linkBeaconToScan(int $beaconId, int $scanId): void;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getBeaconsByScan(int $scanId): array;

    public function countBeaconsByScan(int $scanId): int;

    // ── Cookie Observations ───────────────────────────────

    /**
     * Upsert a cookie observation — one row per cookie/site/day.
     *
     * @param array{cookie_name: string, cookie_domain?: string|null, category_slug?: string|null} $data
     */
    public function upsertCookieObservation(int $siteId, string $date, string $phase, array $data): void;

    /**
     * Batch upsert multiple cookie observations for a single site/date.
     *
     * @param list<array{cookie_name: string, cookie_domain?: string|null, category_slug?: string|null, phase: string}> $observations
     */
    public function upsertCookieObservationBatch(int $siteId, string $date, array $observations): void;

    /**
     * Get cookies observed pre-consent in a date range, grouped by cookie.
     *
     * @return array<int, array{cookie_name: string, cookie_domain: ?string, category_slug: ?string, total_pre_consent: int, total_post_consent: int, first_seen: string, last_seen: string}>
     */
    public function getPreConsentObservations(int $siteId, string $startDate, string $endDate): array;
}
