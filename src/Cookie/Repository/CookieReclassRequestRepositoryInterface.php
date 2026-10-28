<?php

declare(strict_types=1);

namespace OCI\Cookie\Repository;

interface CookieReclassRequestRepositoryInterface
{
    /**
     * Create a reclassification request.
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data): int;

    /**
     * Get a single request by ID (with joined site domain + category names).
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array;

    /**
     * Get paginated requests filtered by status.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function findByStatus(?string $status, int $page = 1, int $perPage = 50): array;

    /**
     * Whether a pending request already exists for this site + cookie name.
     */
    public function existsPending(int $siteId, string $cookieName): bool;

    /**
     * Mark a request reviewed (approved/rejected).
     */
    public function updateStatus(int $id, string $status, int $reviewedBy, ?string $note = null): void;

    /**
     * Count all pending requests (for the admin nav badge).
     */
    public function countPending(): int;

    /**
     * Cookie names that currently have a pending request for a given site.
     *
     * @return list<string>
     */
    public function getPendingNamesForSite(int $siteId): array;
}
