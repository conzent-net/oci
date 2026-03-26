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
}
