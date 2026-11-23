<?php

declare(strict_types=1);

namespace OCI\Shared\Repository;

use OCI\Shared\Repository\PlanRepositoryInterface;

/**
 * No-op plan repository for OCI self-hosted edition.
 *
 * Returns "everything unlocked" — no plan limits, no subscriptions.
 * Used when the Monetization module is not installed.
 */
final class NullPlanRepository implements PlanRepositoryInterface
{
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

    public function getUserCompany(int $userId): ?array
    {
        return null;
    }
}
