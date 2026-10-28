<?php

declare(strict_types=1);

namespace OCI\Notification\Service;

/**
 * Keeps the mailing list in line with the account. Signup, profile edits and
 * billing changes call it; what sits behind it depends on the edition.
 *
 * Implementations must never throw into the caller: a mailing list that is
 * down must not break a signup or a Stripe webhook.
 */
interface SubscriberSyncInterface
{
    /** The user signed up, logged in for the first time, or changed their details. */
    public function syncSubscriber(int $userId): void;

    /** Stripe reported a subscription change for this customer (cus_…). */
    public function syncBillingCustomer(string $stripeCustomerId): void;
}
