<?php

declare(strict_types=1);

namespace OCI\Site\Repository;

interface SiteRepositoryInterface
{
    /**
     * Get a single site by ID.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array;

    /**
     * Get all sites for a user, ordered by domain.
     * When $includeDeleted is true, soft-deleted rows are included.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAllByUser(int $userId, ?string $status = null, bool $includeDeleted = false): array;

    /**
     * Get the main website for a user (first active site).
     *
     * @return array<string, mixed>|null
     */
    public function findMainWebsite(int $userId): ?array;

    /**
     * Update the status field of a site.
     */
    public function updateStatus(int $siteId, string $status): void;

    /**
     * Update compliance status.
     */
    public function updateCompliantStatus(int $siteId, string $status): void;

    /**
     * Get compliant status for a site.
     */
    public function getCompliantStatus(int $siteId): ?string;

    /**
     * Get wizard data for a site.
     *
     * @return array<string, mixed>|null
     */
    public function getWizard(int $siteId, int $userId): ?array;

    /**
     * Update site setting columns.
     *
     * @param array<string, mixed> $data
     */
    public function updateSiteSettings(int $siteId, array $data): void;

    /**
     * Create a new site. Returns the inserted site ID.
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data): int;

    /**
     * Check if a domain is already registered.
     */
    public function domainExists(string $domain): bool;

    /**
     * Count sites for a user (excluding soft-deleted).
     */
    public function countByUser(int $userId): int;

    /**
     * Count active sites for a user (status = 'active', excluding soft-deleted).
     */
    public function countActiveByUser(int $userId): int;

    /**
     * Soft-delete a site (sets deleted_at and status).
     */
    public function softDelete(int $siteId): void;

    /**
     * Restore a soft-deleted site.
     */
    public function restore(int $siteId): void;

    /**
     * Permanently remove a site and all related data.
     */
    public function destroy(int $siteId): void;

    /**
     * Check if a site belongs to a user.
     */
    public function belongsToUser(int $siteId, int $userId): bool;

    /**
     * Generate a unique website key.
     */
    public function generateWebsiteKey(): string;

    /**
     * Save wizard data for a site.
     *
     * @param array<string, mixed> $data
     */
    public function saveWizard(array $data): void;

    /**
     * Update wizard data for a site.
     *
     * @param array<string, mixed> $data
     */
    public function updateWizard(int $siteId, array $data): void;
}
