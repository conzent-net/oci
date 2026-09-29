<?php

declare(strict_types=1);

namespace OCI\Site\Controller;

use OCI\Agency\Repository\AgencyRepositoryInterface;
use OCI\Compliance\Repository\PrivacyFrameworkRepositoryInterface;
use OCI\Compliance\Service\PrivacyFrameworkService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Shared\Repository\PlanRepositoryInterface;
use OCI\Monetization\Service\SubscriptionService;
use OCI\Shared\Service\EditionService;
use OCI\Site\Repository\LanguageRepositoryInterface;
use OCI\Site\Repository\SiteRepositoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment as TwigEnvironment;

/**
 * GET /sites — List all sites for the current user.
 *
 * Mirrors legacy: user_sites.php
 * - Role-based views (admin, agency, customer)
 * - Status filtering (enabled, disabled, deleted, suspended)
 * - Edit / delete / restore / destroy actions
 * - Website key display
 * - Plan-based domain limit awareness
 */
final class SiteListHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepositoryInterface $siteRepository,
        private readonly PlanRepositoryInterface $planRepo,
        private readonly LanguageRepositoryInterface $languageRepo,
        private readonly EditionService $edition,
        private readonly TwigEnvironment $twig,
        private readonly PrivacyFrameworkService $frameworkService,
        private readonly PrivacyFrameworkRepositoryInterface $frameworkRepo,
        private readonly AgencyRepositoryInterface $agencyRepo,
        private readonly ?SubscriptionService $subscriptionService = null,
    ) {}

    /**
     * Is this account an approved agency?
     *
     * Read from the agency row rather than `oci_users.role`: an admin who also
     * runs an agency keeps the admin role, and a suspended agency still has a
     * row but must not get agency treatment.
     */
    private function isApprovedAgency(int $userId): bool
    {
        $agency = $this->agencyRepo->findByUserId($userId);

        return $agency !== null && ($agency['status'] ?? '') === 'approved';
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, mixed>|null $user */
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::redirect('/login');
        }

        $userId = (int) $user['id'];
        $role = (string) ($user['role'] ?? 'customer');
        $queryParams = $request->getQueryParams();

        // Status filter from query param
        $statusFilter = $queryParams['status'] ?? null;

        // Get sites — include deleted rows so restore/destroy works
        $sites = $this->siteRepository->findAllByUser($userId, $statusFilter, includeDeleted: true);

        // An approved agency is explicitly not required to run Conzent on its
        // own website — so an agency with no sites is in a normal state, not an
        // incomplete one. Neither the auto-opening create wizard nor the
        // "subscription required" banner applies to it, and showing both
        // contradicts what the partner programme told them on the way in.
        //
        // This is scoped narrowly on purpose: it only holds while the agency
        // owns NO sites. The moment it creates one it is also a customer, and
        // every prompt below applies to it exactly as to anyone else.
        $agencyWithoutOwnSites = $sites === []
            && $this->siteRepository->findAllByUser($userId, includeDeleted: true) === []
            && $this->isApprovedAgency($userId);

        // If no sites at all (not even deleted), show the page with create modal auto-opened
        $autoOpenCreate = false;
        if ($sites === [] && $statusFilter === null && !$agencyWithoutOwnSites) {
            $autoOpenCreate = true;
        }

        // Plan limit check — self-hosted editions have unlimited domains
        $isEnterprise = $this->planRepo->isEnterprise($userId);
        $maxDomains = 0;
        $canAddSite = true;
        $hasSubscription = true;

        if ($this->edition->arePlanLimitsEnforced() && !$isEnterprise && !$agencyWithoutOwnSites) {
            // Use SubscriptionService (new billing system)
            if ($this->subscriptionService !== null) {
                $hasSubscription = $this->subscriptionService->hasActiveAccess($userId);
                $maxDomains = $this->subscriptionService->getAllowedDomainCount($userId);

                // Sites can always be added (excess will be suspended).
                // But hide the add button when at limit to guide users to upgrade.
                if ($hasSubscription && $maxDomains > 0) {
                    $activeSiteCount = $this->siteRepository->countActiveByUser($userId);
                    $canAddSite = $activeSiteCount < $maxDomains;
                }
            }
        }

        // Attach language IDs and framework IDs to each site (for the edit modal)
        foreach ($sites as &$site) {
            $siteLangs = $this->languageRepo->getSiteLanguages((int) $site['id']);
            $site['language_ids'] = array_map(
                static fn(array $l): int => (int) ($l['language_id'] ?? $l['id'] ?? 0),
                $siteLangs,
            );
            $site['framework_ids'] = $this->frameworkRepo->getFrameworksForSite((int) $site['id']);
        }
        unset($site);

        // Count by status for filter badges (include deleted for accurate counts)
        $allSitesUnfiltered = $this->siteRepository->findAllByUser($userId, includeDeleted: true);
        $statusCounts = ['all' => 0, 'active' => 0, 'disabled' => 0, 'deleted' => 0, 'suspended' => 0];
        $planLimitSuspendedCount = 0;
        foreach ($allSitesUnfiltered as $s) {
            $statusCounts['all']++;
            $st = (string) ($s['status'] ?? '');
            if (isset($statusCounts[$st])) {
                $statusCounts[$st]++;
            }
            if ($st === 'suspended' && ($s['suspended_reason'] ?? '') === 'plan_limit') {
                $planLimitSuspendedCount++;
            }
        }

        $templateData = [
            'title' => 'My Sites',
            'user' => $user,
            'role' => $role,
            'sites' => $sites,
            'statusFilter' => $statusFilter,
            'statusCounts' => $statusCounts,
            'canAddSite' => $canAddSite,
            'hasSubscription' => $hasSubscription,
            'maxDomains' => $maxDomains,
            'isEnterprise' => $isEnterprise,
            'planLimitSuspendedCount' => $planLimitSuspendedCount,
            'activeSiteCount' => $statusCounts['active'],
            'languages' => $this->languageRepo->getAllLanguages(),
            'groupedFrameworks' => $this->frameworkService->getFrameworksGroupedByRegion(),
            'autoOpenCreate' => $autoOpenCreate,
            'agencyWithoutOwnSites' => $agencyWithoutOwnSites,
        ];

        // htmx partial response
        if ($request->getHeaderLine('HX-Request') === 'true') {
            $html = $this->twig->render('partials/sites/_site_table.html.twig', $templateData);
            return ApiResponse::html($html);
        }

        $html = $this->twig->render('pages/sites/index.html.twig', $templateData);
        return ApiResponse::html($html);
    }
}
