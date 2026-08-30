<?php

declare(strict_types=1);

namespace OCI\Agency\Repository;

interface AgencyRepositoryInterface
{
    /**
     * Get all agencies with their owner info and customer counts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAll(): array;

    /**
     * Get agency info by the agency's user_id.
     *
     * @return array<string, mixed>|null
     */
    public function findByUserId(int $userId): ?array;

    /**
     * Find or create an agency profile for a user.
     * Auto-creates a minimal agency record if one doesn't exist yet.
     *
     * @return array<string, mixed>
     */
    public function findOrCreateByUserId(int $userId, string $email): array;

    /**
     * Check which agency owns this customer.
     *
     * @return array<string, mixed>|null  The agency user record, or null.
     */
    public function findAgencyForCustomer(int $customerUserId): ?array;

    /**
     * Get monthly commission totals for the last 12 months.
     *
     * @return array<int, array{date_month: string, total_commission: string}>
     */
    public function getMonthlyCommissions(int $agencyUserId): array;

    /**
     * Get monthly customer counts for the last 12 months.
     *
     * @return array<int, array{date_month: string, total_customers: int}>
     */
    public function getMonthlyCustomers(int $agencyUserId): array;

    /**
     * Get total payout (paid commissions) in the last 30 days.
     */
    public function getPayoutLast30Days(int $agencyUserId): float;

    /**
     * Get all customers for an agency.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCustomers(int $agencyUserId): array;

    /**
     * Check if a user is a customer of this agency.
     */
    public function isCustomer(int $agencyUserId, int $customerUserId): bool;

    /**
     * Add a customer to an agency.
     */
    public function addCustomer(int $agencyUserId, int $customerUserId): void;

    /**
     * Remove a customer from an agency.
     */
    public function removeCustomer(int $agencyUserId, int $customerUserId): void;

    /**
     * Create an invitation from agency to user.
     */
    public function createInvite(int $agencyUserId, int $targetUserId, string $token): void;

    /**
     * Get a pending invite by token.
     *
     * @return array<string, mixed>|null
     */
    public function findInviteByToken(string $token): ?array;

    /**
     * Accept an invite (mark as accepted and add customer relationship).
     */
    public function acceptInvite(string $token): void;

    /**
     * Decline an invite.
     */
    public function declineInvite(string $token): void;

    /**
     * Get pending invites for a user.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPendingInvitesForUser(int $userId): array;

    /**
     * Accept an invite by ID (verifying it belongs to the given user).
     */
    public function acceptInviteById(int $inviteId, int $userId): bool;

    /**
     * Decline an invite by ID (verifying it belongs to the given user).
     */
    public function declineInviteById(int $inviteId, int $userId): bool;

    /**
     * Get pending invites sent by this agency.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPendingInvitesByAgency(int $agencyUserId): array;

    /**
     * Withdraw (cancel) a pending invite by ID (verifying it belongs to the agency).
     */
    public function withdrawInvite(int $inviteId, int $agencyUserId): bool;

    /**
     * Get customer health data for the agency dashboard.
     *
     * Returns per-customer site health info: site counts, last scan, banner/policy status, beacon counts.
     *
     * @return array<string, mixed>
     */
    public function getCustomerHealthData(int $agencyId): array;
}
