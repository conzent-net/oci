<?php

declare(strict_types=1);

namespace OCI\Agency\Repository;

use OCI\Agency\Service\AgencyScope;

/**
 * Cross-account reads for an agency, scoped by the membership table itself.
 *
 * Every method here takes an {@see AgencyScope} rather than an id, and every
 * query joins `oci_agency_customers` rather than filtering on a list of user
 * ids assembled in PHP. The difference matters: an id list is built by a caller
 * who might forget to build it, and a forgotten filter **widens** a query. A
 * join has no list to forget — there is no way to express "all sites" here,
 * because the JOIN is the only route to a site row.
 *
 * Only active links count. `date_to IS NULL` is the definition of active and
 * appears in every query below; an ended relationship must stop granting
 * access the moment it ends, not whenever the row is eventually deleted.
 */
interface AgencyScopeRepositoryInterface
{
    /**
     * The agency row for an account, whatever state it is in.
     *
     * Returns pending, rejected, suspended and deactivated rows too, because
     * the application-status page has to be able to explain why somebody
     * cannot get in. Deciding whether the row grants access is
     * {@see AgencyScope::fromApprovedRow()}'s job, never this method's — note
     * the existing `AgencyRepository::findByUserId()` silently filters
     * `is_active = 1`, which makes a deactivated agency indistinguishable from
     * an account that never applied.
     *
     * @return array<string, mixed>|null
     */
    public function findAgencyByUserId(int $userId): ?array;

    /**
     * One page of sites across every client this agency currently manages.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findSites(AgencyScope $scope, int $limit = 100, int $offset = 0): array;

    /**
     * Total sites across every client this agency currently manages.
     */
    public function countSites(AgencyScope $scope): int;

    /**
     * One site, but only if this agency manages the account that owns it.
     *
     * Returns the row so that authorization sits on the fetch path: a caller
     * that needs the site has already been authorized by the act of getting it,
     * and cannot obtain the row any other way.
     *
     * @return array<string, mixed>|null null means "not visible to this agency",
     *                                   which deliberately does not distinguish
     *                                   "no such site" from "someone else's site"
     */
    public function findSite(AgencyScope $scope, int $siteId): ?array;

    /**
     * The agency's current clients, with their site counts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findClients(AgencyScope $scope): array;

    /**
     * One client account, only if currently linked to this agency.
     *
     * @return array<string, mixed>|null
     */
    public function findClient(AgencyScope $scope, int $customerUserId): ?array;

    /**
     * How many clients this agency currently has an active link to.
     *
     * Load-bearing beyond reporting: it is half of the white-label
     * entitlement rule, so it must count exactly what the dashboard counts.
     */
    public function countActiveClients(AgencyScope $scope): int;

    /**
     * Site ids for every client of this agency, for bulk operations that must
     * regenerate or re-check each one.
     *
     * @return array<int, int>
     */
    public function findSiteIds(AgencyScope $scope): array;
}
