<?php

declare(strict_types=1);

namespace OCI\Site\Controller;

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
        private readonly ?SubscriptionService $subscriptionService = null,
    ) {}

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

        // If no sites at all (not even deleted), show the page with create modal auto-opened
        $autoOpenCreate = false;
        if ($sites === [] && $statusFilter === null) {
            $autoOpenCreate = true;
        }

        // Plan limit check — self-hosted editions have unlimited domains
        $isEnterprise = $this->planRepo->isEnterprise($userId);
        $maxDomains = 0;
        $canAddSite = true;
        $hasSubscription = true;

        if ($this->edition->arePlanLimitsEnforced() && !$isEnterprise) {
            // Use SubscriptionService (new billing system)
            if ($this->subscriptionService !== null) {
                $hasSubscription = $this->subscriptionService->hasActiveAccess($userId);
                $maxDomains = $this->subscriptionService->getAllowedDomainCount($userId);

                if (!$hasSubscription) {
                    $canAddSite = false;
                } elseif ($maxDomains > 0) {
                    $activeSiteCount = $this->siteRepository->countByUser($userId);
                    $canAddSite = $activeSiteCount < $maxDomains;
                }
            }
        }

        // Attach language IDs to each site (for the edit modal)
        foreach ($sites as &$site) {
            $siteLangs = $this->languageRepo->getSiteLanguages((int) $site['id']);
            $site['language_ids'] = array_map(
                static fn(array $l): int => (int) ($l['language_id'] ?? $l['id'] ?? 0),
                $siteLangs,
            );
        }
        unset($site);

        // Count by status for filter badges (include deleted for accurate counts)
        $allSitesUnfiltered = $this->siteRepository->findAllByUser($userId, includeDeleted: true);
        $statusCounts = ['all' => 0, 'active' => 0, 'disabled' => 0, 'deleted' => 0, 'suspended' => 0];
        foreach ($allSitesUnfiltered as $s) {
            $statusCounts['all']++;
            $st = (string) ($s['status'] ?? '');
            if (isset($statusCounts[$st])) {
                $statusCounts[$st]++;
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
            'languages' => $this->languageRepo->getAllLanguages(),
            'autoOpenCreate' => $autoOpenCreate,
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
