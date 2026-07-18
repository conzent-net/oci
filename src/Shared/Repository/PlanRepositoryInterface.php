<?php

declare(strict_types=1);

namespace OCI\Shared\Repository;

/**
 * Plan repository contract used by core services.
 *
 * Implemented by:
 * - PlanRepository (full SaaS, in src/Monetization/)
 * - NullPlanRepository (OCI self-hosted, everything unlocked)
 */
interface PlanRepositoryInterface
{
    public function isSubscribed(int $userId): bool;

    public function isEnterprise(int $userId): bool;

    public function getPriceModel(int $userId): string;

    /** @return array<string, mixed>|null */
    public function getUserPlan(int $userId): ?array;

    /** @return array<string, mixed>|null */
    public function findPlanById(int $planId): ?array;

    /** @return array<string, int|string> */
    public function getPlanFeatures(int $planId): array;

    /** @return array<int, array<string, mixed>> */
    public function getAllPlans(): array;

    /** @return array<string, int|string> */
    public function getAllFeatures(): array;

    /** @return array<string, int|string> */
    public function getUserPlanFeatureOverrides(int $userId, int $planId): array;

    /** @return array<string, mixed>|null */
    public function getUserCompany(int $userId): ?array;
}
