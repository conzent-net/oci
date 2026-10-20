<?php

declare(strict_types=1);

namespace OCI\Agency\Repository;

/**
 * Stored human decisions about derived issues.
 *
 * The issues are derived live; only the decision is persisted. That split is
 * deliberate — a stored issue can go stale silently, whereas a stored decision
 * about a live issue cannot, because the issue has to still derive for the
 * decision to apply to anything.
 */
interface IssueStateRepositoryInterface
{
    /**
     * Decisions for a set of sites, keyed `siteId:issueKey`.
     *
     * @param array<int, int> $siteIds
     *
     * @return array<string, array<string, mixed>>
     */
    public function forSites(array $siteIds): array;

    /**
     * Record an acknowledgement or a snooze.
     *
     * @param string|null $until `Y-m-d`, or null for "until the evidence changes"
     */
    public function decide(
        int $siteId,
        string $issueKey,
        string $severity,
        string $state,
        ?int $magnitude,
        ?string $evidenceHash,
        ?string $until,
        ?string $note,
        int $actorId,
    ): void;

    /** Reopen a single decision — the "actually, show me this again" path. */
    public function reopen(int $siteId, string $issueKey): bool;

    /**
     * Mark decisions resolved when their issue no longer derives.
     *
     * @param array<int, int>    $siteIds  sites that were just evaluated
     * @param array<int, string> $stillOpen `siteId:issueKey` pairs still deriving
     *
     * @return int rows resolved
     */
    public function resolveDisappeared(array $siteIds, array $stillOpen): int;
}
