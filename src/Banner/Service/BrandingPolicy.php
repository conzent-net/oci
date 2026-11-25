<?php

declare(strict_types=1);

namespace OCI\Banner\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use OCI\Monetization\Service\PricingService;
use OCI\Monetization\Service\SubscriptionService;

/**
 * May Conzent branding come off this account's banners?
 *
 * One rule, one place. Before this, the same predicate was written out eight
 * times — six in {@see ScriptGenerationService} (GDPR and CCPA branches, logo,
 * revisit icon and branding each) and once each in the banner list and update
 * handlers. Eight copies of a revenue gate is eight chances for one of them to
 * drift, and the drift is invisible until somebody's logo appears where it
 * should not.
 *
 * The answer is true when **either** holds:
 *
 * 1. **The owner's plan includes `custom_branding`.** Unchanged behaviour for
 *    every self-serve customer, including the community edition's "no plan
 *    features configured means everything is unlocked".
 *
 * 2. **The site is inside an approved agency's book.** Either the owner is an
 *    active client of an approved, active agency, or the owner *is* an approved
 *    agency that has at least one active client. Free white-label is the
 *    agency channel's main perk, and it is deliberately not sold back to them.
 *
 * ## What the "at least one client" condition actually does
 *
 * It is worth being precise, because it is easy to credit it with more than it
 * does. It does **not** stop somebody creating a second account, calling it an
 * agency, inviting their own main account as a client, and taking
 * `custom_branding` for free — in that scenario the invited account *is* the
 * one client, so the condition is satisfied by the attack itself. **The thing
 * that closes that hole is the partnership approval**: an agency only exists
 * because a person reviewed a request and said yes.
 *
 * What the condition does do is decide the agency's *own* sites. An approved
 * agency with an empty client book is not yet doing the thing white-label is
 * for, so its own banners keep Conzent branding until it links a first client.
 * That is a small, honest rule about a perk — not a security control, and it
 * should not be described as one.
 */
final class BrandingPolicy
{
    public function __construct(
        private readonly Connection $db,
        private readonly ?SubscriptionService $subscriptions = null,
        private readonly ?PricingService $pricing = null,
    ) {
    }

    /**
     * The full answer for a site owner.
     *
     * @param bool|null $planAllows the plan verdict when the caller has already
     *                              computed it. {@see ScriptGenerationService}
     *                              passes its own, because it holds the loaded
     *                              feature array and must not re-query per
     *                              render. Null means "work it out".
     */
    public function canRemoveBranding(int $ownerUserId, ?bool $planAllows = null): bool
    {
        if ($planAllows ?? $this->planAllows($ownerUserId)) {
            return true;
        }

        return $this->isAgencyEntitled($ownerUserId);
    }

    /**
     * The plan half on its own, for callers that only have a user id.
     *
     * Mirrors what the banner handlers did inline: no subscription service
     * wired (the community edition) means unrestricted, otherwise the plan must
     * carry the feature.
     */
    public function planAllows(int $ownerUserId): bool
    {
        if ($this->subscriptions === null) {
            return true;
        }

        $planKey = $this->subscriptions->getPlanKey($ownerUserId);

        if ($planKey === null) {
            return false;
        }

        return $this->pricing === null || $this->pricing->hasFeature($planKey, 'custom_branding');
    }

    /**
     * Is this account inside an approved agency's book?
     *
     * Two shapes, one query each, because they are genuinely different
     * questions: "somebody manages me" and "I manage somebody".
     */
    public function isAgencyEntitled(int $ownerUserId): bool
    {
        if ($ownerUserId <= 0) {
            return false;
        }

        // Managed: an active link to an approved, active agency.
        $managed = $this->db->fetchOne(
            "SELECT 1
             FROM oci_agency_customers AS ac
             INNER JOIN oci_agencies AS a ON a.id = ac.agency_id
             WHERE ac.customer_user_id = :userId
               AND ac.date_to IS NULL
               AND a.status = 'approved'
               AND a.is_active = 1
             LIMIT 1",
            ['userId' => $ownerUserId],
            ['userId' => ParameterType::INTEGER],
        );

        if ($managed !== false) {
            return true;
        }

        // Managing: an approved, active agency that has at least one live link.
        $managing = $this->db->fetchOne(
            "SELECT 1
             FROM oci_agencies AS a
             INNER JOIN oci_agency_customers AS ac ON ac.agency_id = a.id AND ac.date_to IS NULL
             WHERE a.user_id = :userId
               AND a.status = 'approved'
               AND a.is_active = 1
             LIMIT 1",
            ['userId' => $ownerUserId],
            ['userId' => ParameterType::INTEGER],
        );

        return $managing !== false;
    }
}
