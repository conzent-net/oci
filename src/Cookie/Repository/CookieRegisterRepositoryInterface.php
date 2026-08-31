<?php

declare(strict_types=1);

namespace OCI\Cookie\Repository;

/**
 * Persistence for the effective-register snapshots and their diff rows.
 */
interface CookieRegisterRepositoryInterface
{
    /**
     * Store a register snapshot.
     *
     * @param list<array<string, mixed>> $entries Normalized register entries
     */
    public function saveSnapshot(int $siteId, ?int $scanId, array $entries): int;

    /**
     * Latest snapshot for a site, with decoded entries.
     *
     * @return array{id: int, entries: list<array<string, mixed>>}|null
     */
    public function getLatestSnapshot(int $siteId): ?array;

    /**
     * Insert diff rows.
     *
     * @param list<array<string, mixed>> $changes
     * @return list<int> Inserted row ids
     */
    public function insertChanges(int $siteId, int $snapshotId, ?int $scanId, array $changes): array;

    /**
     * Paginated change timeline, newest first.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function getChanges(int $siteId, int $page, int $perPage, ?string $entryType = null): array;

    /**
     * Changes since a date (for the report mail section).
     *
     * @return list<array<string, mixed>>
     */
    public function getChangesSince(int $siteId, string $sinceDate, int $limit = 200): array;

    /**
     * Stamp change rows as notified.
     *
     * @param list<int> $ids
     */
    public function markNotified(array $ids): void;
}
