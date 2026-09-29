<?php

declare(strict_types=1);

namespace OCI\Agency\Service;

use OCI\Agency\Exception\AgencyAccessDenied;
use OCI\Agency\Repository\AgencyScopeRepositoryInterface;

/**
 * The one place that decides whether an agency may touch something.
 *
 * Two rules make this hold up in practice rather than only on paper:
 *
 * 1. **Authorization sits on the fetch path, not beside it.**
 *    `assertCanAccessSite()` returns the site row. A handler that needs the
 *    site has to call it, because there is no other way to get the row — so
 *    "did this handler check?" stops being a question anyone has to ask per
 *    handler. Compare the current agency controllers, where ownership is
 *    verified in two handlers out of ten and nothing structural notices.
 *
 * 2. **The scope is built from the database, once, per request.**
 *    {@see AgencyScope} cannot be constructed from a request parameter, and
 *    refuses to exist for an agency that is not approved and active. So the
 *    approval gate cannot be bypassed by any amount of creative routing.
 *
 * `resolve()` returns null rather than throwing for an account that simply is
 * not an agency, because that is a normal state for most users — the middleware
 * turns it into a redirect. `require()` is for code that has already been
 * routed behind the agency middleware and should never see null.
 */
final class AgencyAccessService
{
    public function __construct(
        private readonly AgencyScopeRepositoryInterface $scopeRepo,
    ) {
    }

    /**
     * The agency scope for an authenticated user, or null if there isn't one.
     *
     * Null covers every "not an agency right now" case on purpose — no agency
     * row, a pending application, a rejection, a suspension, a deactivated
     * account. The caller decides what to show; none of them may proceed.
     */
    public function resolve(int $userId): ?AgencyScope
    {
        $row = $this->scopeRepo->findAgencyByUserId($userId);

        if ($row === null) {
            return null;
        }

        try {
            return AgencyScope::fromApprovedRow($row);
        } catch (\DomainException) {
            // Pending, rejected, suspended or deactivated. Not an error — the
            // application-status page is the correct destination, and the
            // reason belongs there rather than in an exception trace.
            return null;
        }
    }

    /**
     * The raw agency row regardless of status, for the application-status page.
     *
     * Separate from {@see self::resolve()} so that "show this person why they
     * cannot get in" never accidentally becomes "let this person in".
     *
     * @return array<string, mixed>|null
     */
    public function findAgencyRow(int $userId): ?array
    {
        return $this->scopeRepo->findAgencyByUserId($userId);
    }

    /**
     * @throws AgencyAccessDenied when the account is not an approved agency
     */
    public function require(int $userId): AgencyScope
    {
        return $this->resolve($userId) ?? throw AgencyAccessDenied::notAnAgency();
    }

    /**
     * Return the site, or refuse. The return value is the authorization.
     *
     * @return array<string, mixed>
     *
     * @throws AgencyAccessDenied
     */
    public function assertCanAccessSite(AgencyScope $scope, int $siteId): array
    {
        return $this->scopeRepo->findSite($scope, $siteId)
            ?? throw AgencyAccessDenied::site($siteId);
    }

    /**
     * Return the client account, or refuse.
     *
     * @return array<string, mixed>
     *
     * @throws AgencyAccessDenied
     */
    public function assertCanAccessClient(AgencyScope $scope, int $customerUserId): array
    {
        return $this->scopeRepo->findClient($scope, $customerUserId)
            ?? throw AgencyAccessDenied::client($customerUserId);
    }

    /**
     * Authorize a batch in one query, and refuse the whole batch if any member
     * is out of scope.
     *
     * Bulk operations are where cross-tenant writes actually happen: applying a
     * template to forty sites is forty chances to slip one foreign id into the
     * list. Checking them one at a time invites a partial apply that has
     * already written to some sites before it notices, so this resolves the
     * whole set up front and returns the verified ids.
     *
     * @param array<int, int> $siteIds
     *
     * @return array<int, int> the same ids, verified, de-duplicated, ordered
     *
     * @throws AgencyAccessDenied naming the first id that is not in scope
     */
    public function assertCanAccessSites(AgencyScope $scope, array $siteIds): array
    {
        $requested = array_values(array_unique(array_map('intval', $siteIds)));

        if ($requested === []) {
            return [];
        }

        $allowed = $this->scopeRepo->findSiteIds($scope);
        $missing = array_values(array_diff($requested, $allowed));

        if ($missing !== []) {
            throw AgencyAccessDenied::site($missing[0]);
        }

        sort($requested);

        return $requested;
    }

    /**
     * Whether this agency has at least one active client.
     *
     * Half of the white-label entitlement rule, and deliberately expressed here
     * rather than inline in the branding policy so both read the same number
     * the dashboard shows.
     */
    public function hasActiveClients(AgencyScope $scope): bool
    {
        return $this->scopeRepo->countActiveClients($scope) > 0;
    }
}
