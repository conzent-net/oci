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
     * @param string $basis `legacy` (built from a scan run without consent) or `inventory`
     *        (built from a verified after-consent pass); snapshots on different bases do not diff
     */
    public function saveSnapshot(int $siteId, ?int $scanId, array $entries, string $basis = 'legacy'): int;

    /**
     * Latest snapshot for a site, with decoded entries and its basis.
     *
     * @return array{id: int, entries: list<array<string, mixed>>, basis: string}|null
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
