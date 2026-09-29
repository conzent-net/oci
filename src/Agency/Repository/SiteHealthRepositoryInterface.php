<?php

declare(strict_types=1);

namespace OCI\Agency\Repository;

use OCI\Agency\Service\AgencyScope;

/**
 * The raw signals behind the multi-client compliance view.
 *
 * One query for every site in an agency's book, not one query per site. At 21
 * sites the difference is invisible; at 500 it is the difference between a page
 * and a timeout, and the shape is much harder to change later than to get right
 * now.
 *
 * Everything returned here is a **measurement**, never a verdict. Whether a
 * number means "failing" is {@see \OCI\Agency\Service\SiteHealthScorer}'s job,
 * and keeping that separate is what makes it possible to say "not measured"
 * instead of quietly rendering absent data as a pass.
 */
interface SiteHealthRepositoryInterface
{
    /**
     * Raw health signals for every site this agency manages.
     *
     * @return array<int, array<string, mixed>> keyed by site id
     */
    public function signalsForAgency(AgencyScope $scope): array;
}
