<?php

declare(strict_types=1);

namespace OCI\Shared\Repository;

use OCI\Identity\Repository\UserRepositoryInterface;
use OCI\Shared\Repository\PlanRepositoryInterface;

/**
 * No-op plan repository for OCI self-hosted edition.
 *
 * Returns "everything unlocked" — no plan limits, no subscriptions.
 * Used when the Monetization module is not installed.
 *
 * The company record is not a plan matter and exists in every edition, so it
 * is read from the identity side rather than stubbed.
 */
final class NullPlanRepository implements PlanRepositoryInterface
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepo,
    ) {}

    public function isSubscribed(int $userId): bool
    {
        return true;
    }

    public function isEnterprise(int $userId): bool
    {
        return true;
    }

    public function getPriceModel(int $userId): string
    {
        return 'oci';
    }

    public function getUserPlan(int $userId): ?array
    {
        return [
            'user_id' => $userId,
            'plan_id' => 0,
            'plan_key' => 'oci',
            'is_active' => 1,
        ];
    }

    public function findPlanById(int $planId): ?array
    {
        return [
            'id' => $planId,
            'plan_key' => 'oci',
            'plan_name' => 'OCI Self-Hosted',
        ];
    }

    public function getPlanFeatures(int $planId): array
    {
        return [];
    }

    public function getAllPlans(): array
    {
        return [];
    }

    public function getAllFeatures(): array
    {
        return [];
    }

    public function getUserPlanFeatureOverrides(int $userId, int $planId): array
    {
        return [];
    }

    /**
     * The same record account setup reads and writes. Returning null here
     * sent the dashboard to /account/setup after setup was done, and setup
     * sent the user back to /: a redirect loop until a site existed.
     */
    public function getUserCompany(int $userId): ?array
    {
        return $this->userRepo->getUserCompany($userId);
    }
}
