<?php

declare(strict_types=1);

namespace OCI\Site\Service;

use OCI\Site\Repository\SiteRepositoryInterface;

/**
 * The one answer to "may the signed-in user touch this site?".
 *
 * Every handler that takes a site id, or a record id that belongs to a site
 * (consent, report, scan, banner, experiment), asks this before reading or
 * writing. The `web` middleware group only proves someone is signed in; it
 * says nothing about which tenant they are, and ids are sequential.
 *
 * Owner only, by design: agencies act on a customer's site by impersonating
 * that customer, so the session user is always the one to check, and admins
 * use the admin routes. tests/Unit/Http/TenantIsolationTest.php fails when a
 * `web` handler takes an id without going through here (or an equivalent).
 */
final class SiteAccess
{
    public function __construct(
        private readonly SiteRepositoryInterface $sites,
    ) {}

    /**
     * @param mixed $user the request's `user` attribute
     */
    public function owns(mixed $user, int $siteId): bool
    {
        if (!\is_array($user) || $siteId <= 0) {
            return false;
        }

        $userId = (int) ($user['id'] ?? 0);

        return $userId > 0 && $this->sites->belongsToUser($siteId, $userId);
    }

    /**
     * True only when every site in the list is the user's. An empty list is
     * not a pass: callers that act on "these sites" must have named some.
     *
     * @param mixed $user
     * @param array<int|string, mixed> $siteIds
     */
    public function ownsAll(mixed $user, array $siteIds): bool
    {
        if ($siteIds === []) {
            return false;
        }

        foreach ($siteIds as $siteId) {
            if (!$this->owns($user, (int) $siteId)) {
                return false;
            }
        }

        return true;
    }
}
