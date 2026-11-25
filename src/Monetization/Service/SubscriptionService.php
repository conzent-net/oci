<?php

declare(strict_types=1);

namespace OCI\Monetization\Service;

/**
 * Subscription service stub for Community Edition.
 *
 * In the Cloud Edition, this is replaced with the full Stripe-backed implementation.
 * In the Community Edition, this class is never instantiated — all constructor
 * injections are nullable and default to null. This stub exists solely to satisfy
 * PHP's type-hint autoloading.
 */
final class SubscriptionService
{
    public function hasActiveAccess(int $userId): bool
    {
        return true;
    }

    public function getPlanKey(int $userId): string
    {
        return 'community';
    }

    public function getAllowedDomainCount(int $userId): int
    {
        return 0; // 0 = unlimited
    }

    public function getActiveSubscription(int $userId): ?array
    {
        return null;
    }
}
