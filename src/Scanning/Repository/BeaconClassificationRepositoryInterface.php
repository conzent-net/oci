<?php

declare(strict_types=1);

namespace OCI\Scanning\Repository;

interface BeaconClassificationRepositoryInterface
{
    /**
     * Normalise a beacon URL to a bare host/domain used as the classification key.
     */
    public function normalizeDomain(string $url): string;

    /**
     * Enrich scan beacon rows with a resolved category.
     *
     * Adds to each row: `domain`, `resolved_slug`, `resolved_name`, and
     * `is_global` (true when the domain is classified in the Conzent global DB).
     * Global classification takes priority over the scanner's beacon_type.
     *
     * @param list<array<string, mixed>> $beacons
     * @return list<array<string, mixed>>
     */
    public function resolveBeacons(array $beacons): array;

    /**
     * Look up a beacon domain in the global reference database.
     *
     * @return array<string, mixed>|null
     */
    public function matchGlobal(string $domain): ?array;

    /**
     * Get a single beacon row by id (with its site_id).
     *
     * @return array<string, mixed>|null
     */
    public function findBeacon(int $beaconId): ?array;

    /**
     * Set the per-site category (beacon_type) for a beacon the user owns.
     */
    public function classifyBeacon(int $beaconId, string $slug): void;

    /**
     * Apply a classification to the global beacon reference database.
     *
     * Updates the domain row if it exists, otherwise inserts a new one.
     */
    public function applyGlobalClassification(
        string $domain,
        int $categoryId,
        ?string $platform = null,
        ?string $description = null,
        ?float $aiConfidence = null,
        string $classifiedBy = 'human',
    ): void;

    /**
     * Global beacon entries flagged for the weekly human review.
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
        string $domain,
        int $categoryId,
        ?string $platform,
        ?string $description,
        ?float $aiConfidence = null,
        string $classifiedBy = 'human',
    ): void;

    public function deleteGlobalEntry(int $id): void;

    /**
     * Aggregate unclassified beacon domains observed across every site.
     *
     * Drops domains already classified in the global reference database.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function getUnclassifiedBacklog(int $page = 1, int $perPage = 25, ?string $search = null): array;
}
