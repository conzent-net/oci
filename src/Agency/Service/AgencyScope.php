<?php

declare(strict_types=1);

namespace OCI\Agency\Service;

/**
 * Proof that a request is acting as a specific, approved, active agency.
 *
 * Every cross-account read in the agency feature takes one of these. That is
 * the whole point: a repository method that requires an `AgencyScope` cannot be
 * called with a bare integer someone pulled out of a query string, and the
 * scope itself cannot exist for an agency that is pending, rejected, suspended
 * or deactivated — the factory refuses to build one.
 *
 * Validation lives in the factory rather than the caller because a check the
 * caller performs is a check the caller can forget. Here the only way to obtain
 * the object is to hand over a row that already says `approved`, so "did anyone
 * verify this?" has one answer for every call site in the codebase.
 *
 * **The two ids are deliberately separate accessors with different names.**
 * `oci_agencies.id` and `oci_agencies.user_id` are both small integers, both
 * called "the agency id" in conversation, and mixing them silently returns the
 * wrong tenant's data rather than failing. The existing repository already has
 * this trap — `getCustomerHealthData(int $agencyId)` takes the agency row id
 * while its twenty sibling methods take the user id. Typed accessors with
 * unmistakable names are the cheapest available fix.
 */
final class AgencyScope
{
    private function __construct(
        private readonly int $agencyId,
        private readonly int $agencyUserId,
        private readonly string $name,
    ) {
    }

    /**
     * Build a scope from an agency row, or refuse.
     *
     * @param array<string, mixed> $row a row from `oci_agencies`
     *
     * @throws \DomainException when the row is not an agency that may act
     */
    public static function fromApprovedRow(array $row): self
    {
        $agencyId = (int) ($row['id'] ?? 0);
        $agencyUserId = (int) ($row['user_id'] ?? 0);
        $status = (string) ($row['status'] ?? '');
        $isActive = (int) ($row['is_active'] ?? 0) === 1;

        if ($agencyId <= 0 || $agencyUserId <= 0) {
            throw new \DomainException('An agency scope needs both the agency row id and its owning user id.');
        }

        if ($status !== 'approved') {
            throw new \DomainException(sprintf(
                'Agency %d is %s, not approved, so it cannot act as an agency.',
                $agencyId,
                $status === '' ? 'in an unknown state' : $status,
            ));
        }

        if (!$isActive) {
            throw new \DomainException(sprintf('Agency %d is approved but deactivated.', $agencyId));
        }

        return new self($agencyId, $agencyUserId, (string) ($row['name'] ?? ''));
    }

    /** The `oci_agencies.id` primary key. Joins to `oci_agency_customers.agency_id`. */
    public function agencyId(): int
    {
        return $this->agencyId;
    }

    /** The `oci_users.id` of the account that owns the agency. NOT the agency id. */
    public function agencyUserId(): int
    {
        return $this->agencyUserId;
    }

    public function name(): string
    {
        return $this->name;
    }
}
