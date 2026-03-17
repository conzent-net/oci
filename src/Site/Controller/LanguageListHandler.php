<?php

declare(strict_types=1);

namespace OCI\Site\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Monetization\Service\PricingService;
use OCI\Shared\Repository\PlanRepositoryInterface;
use OCI\Site\Repository\LanguageRepositoryInterface;
use OCI\Site\Repository\SiteRepositoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment as TwigEnvironment;

/**
 * GET /languages — Manage languages for the current site.
 */
final class LanguageListHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepositoryInterface $siteRepo,
        private readonly LanguageRepositoryInterface $languageRepo,
        private readonly PlanRepositoryInterface $planRepo,
        private readonly PricingService $pricingService,
        private readonly TwigEnvironment $twig,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, mixed>|null $user */
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::redirect('/login');
        }

        $userId = (int) $user['id'];
        $queryParams = $request->getQueryParams();
        $cookies = $request->getCookieParams();

        // Resolve site
        $sites = $this->siteRepo->findAllByUser($userId);
        if ($sites === []) {
            return ApiResponse::redirect('/sites');
        }

        $siteIds = array_map(static fn(array $s): int => (int) $s['id'], $sites);
        $siteId = 0;

        if (isset($queryParams['site_id'])) {
            $siteId = (int) $queryParams['site_id'];
        } elseif (isset($cookies['site_id']) && \in_array((int) $cookies['site_id'], $siteIds, true)) {
            $siteId = (int) $cookies['site_id'];
        }

        if (!\in_array($siteId, $siteIds, true)) {
            $siteId = $siteIds[0];
        }

        $currentSite = $this->siteRepo->findById($siteId);
        $siteLanguages = $this->languageRepo->getSiteLanguages($siteId);
        $allLanguages = $this->languageRepo->getAllLanguages();

        // Plan limits
        $maxLangs = $this->resolveLimit($userId, 'max_languages');
        $isEnterprise = $this->planRepo->isEnterprise($userId);
        $canAddLang = $maxLangs === 0 || $isEnterprise || \count($siteLanguages) < $maxLangs;

        // Filter out languages already added
        $siteLanguageIds = array_map(static fn(array $l): int => (int) $l['id'], $siteLanguages);
        $availableLanguages = array_filter(
            $allLanguages,
            static fn(array $l): bool => !\in_array((int) $l['id'], $siteLanguageIds, true),
        );

        $html = $this->twig->render('pages/languages/index.html.twig', [
            'title' => 'Languages',
            'active_page' => 'languages',
            'user' => $user,
            'sites' => $sites,
            'currentSite' => $currentSite,
            'siteId' => $siteId,
            'siteLanguages' => $siteLanguages,
            'availableLanguages' => array_values($availableLanguages),
            'canAddLang' => $canAddLang,
            'maxLangs' => $maxLangs,
            'isEnterprise' => $isEnterprise,
        ]);

        return ApiResponse::html($html);
    }

    private function resolveLimit(int $userId, string $limitKey): int
    {
        if ($this->planRepo->isEnterprise($userId)) {
            return 0;
        }

        $userPlan = $this->planRepo->getUserPlan($userId);
        if ($userPlan === null) {
            return 0;
        }

        $planKey = $userPlan['plan_key'] ?? null;
        if ($planKey !== null) {
            return $this->pricingService->getLimit($planKey, $limitKey);
        }

        return 0;
    }
}
