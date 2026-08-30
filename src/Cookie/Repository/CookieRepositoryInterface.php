<?php

declare(strict_types=1);

namespace OCI\Cookie\Repository;

interface CookieRepositoryInterface
{
    /**
     * Get paginated site cookies with category info.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function findBySite(int $siteId, int $page = 1, int $perPage = 50, ?string $category = null, ?string $search = null): array;

    /**
     * Get a single site cookie by ID.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array;

    /**
     * Create a site cookie.
     */
    public function create(int $siteId, array $data): int;

    /**
     * Update a site cookie.
     */
    public function update(int $id, array $data): void;

    /**
     * Delete a site cookie.
     */
    public function delete(int $id): void;

    /**
     * Count cookies per category for a site.
     *
     * @return array<string, int>
     */
    public function countByCategory(int $siteId): array;

    /**
     * Import cookies from a scan into site cookies (skip duplicates).
     *
     * @return int Number of cookies imported
     */
    public function importFromScan(int $siteId, int $scanId): int;

    /**
     * Match a cookie name against the global reference database.
     *
     * @return array<string, mixed>|null
     */
    public function matchGlobal(string $cookieName, ?string $domain = null): ?array;

    /**
     * Get all site cookies for export/banner use (no pagination).
     *
     * @return list<array<string, mixed>>
     */
    public function getAllForSite(int $siteId): array;

    /**
     * Get cookies from the latest completed scan for a site.
     *
     * @return array{scan_id: int|null, cookies: list<array<string, mixed>>}
     */
    public function getLatestScanCookies(int $siteId): array;

    /**
     * Get cookies discovered via client-side beacons (aggregated observations).
     *
     * @return list<array<string, mixed>>
     */
    public function getObservedCookies(int $siteId): array;

    /**
     * Get the per-site classification overrides a user has set for cookies.
     *
     * Keyed by lowercase cookie name, these take priority over scan/global
     * classification when building the overview and public cookie list.
     *
     * @return array<string, array{category_slug: string, category_name: ?string}>
     */
    public function getSiteClassificationOverrides(int $siteId): array;

    /**
     * Upsert a user-set classification for a cookie into oci_site_cookies.
     *
     * Written with from_scan = 0 to mark it as a deliberate user classification.
     */
    public function upsertSiteClassification(int $siteId, string $cookieName, ?string $cookieDomain, int $categoryId): void;

    /**
     * Apply a classification to the global reference database.
     *
     * Updates the exact-name global row if it exists, otherwise inserts a new one.
     * Platform/description are only written when provided (non-null).
     */
    public function applyGlobalClassification(
        string $cookieName,
        ?string $domain,
        int $categoryId,
        ?string $platform = null,
        ?string $description = null,
        ?float $aiConfidence = null,
        string $classifiedBy = 'human',
    ): void;

    /**
     * Global entries flagged for the weekly human review.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getNeedsReview(int $limit = 100): array;

    public function countNeedsReview(): int;

    public function markReviewed(int $id, ?int $categoryId = null): void;

    /**
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function listGlobalClassified(int $page = 1, int $perPage = 50, ?string $search = null): array;

    public function updateGlobalEntry(
        int $id,
        string $cookieName,
        int $categoryId,
        ?string $platform,
        ?string $description,
        ?float $aiConfidence = null,
        string $classifiedBy = 'human',
    ): void;

    public function deleteGlobalEntry(int $id): void;

    /**
     * Aggregate all currently-unclassified cookies observed across every site.
     *
     * Merges scan cookies and beacon observations, drops any name already
     * classified in the global database (exact or wildcard), and returns a
     * paginated backlog for admin classification.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function getUnclassifiedBacklog(int $page = 1, int $perPage = 25, ?string $search = null): array;
}
