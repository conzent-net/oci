<?php

declare(strict_types=1);

namespace OCI\Scanning\Repository;

interface BeaconReclassRequestRepositoryInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int;

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array;

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function findByStatus(?string $status, int $page = 1, int $perPage = 50): array;

    public function existsPending(int $siteId, string $beaconDomain): bool;

    public function updateStatus(int $id, string $status, int $reviewedBy, ?string $note = null): void;

    public function countPending(): int;

    /**
     * @return list<string>
     */
    public function getPendingDomainsForSite(int $siteId): array;
}
