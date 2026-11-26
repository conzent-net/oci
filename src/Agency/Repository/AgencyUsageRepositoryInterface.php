<?php

declare(strict_types=1);

namespace OCI\Agency\Repository;

use OCI\Agency\Service\AgencyScope;

/**
 * Per-client usage figures for a reporting period.
 *
 * With client-direct billing this is a **service report, not an invoice**. The
 * agency is not billing anybody for Conzent — the client pays Conzent — so the
 * artefact this produces is evidence of work done, which is a different
 * document with a different tone. Nothing here is a price.
 */
interface AgencyUsageRepositoryInterface
{
    /**
     * One row per client for the period.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byClient(AgencyScope $scope, string $from, string $to): array;

    /**
     * One row per site for the period — the detail behind the client totals.
     *
     * @return array<int, array<string, mixed>>
     */
    public function bySite(AgencyScope $scope, string $from, string $to, ?int $clientUserId = null): array;
}
