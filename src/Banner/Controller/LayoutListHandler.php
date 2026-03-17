<?php

declare(strict_types=1);

namespace OCI\Banner\Controller;

use OCI\Banner\Service\LayoutService;
use OCI\Dashboard\Service\DashboardService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Monetization\Service\PricingService;
use OCI\Shared\Repository\PlanRepositoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment as TwigEnvironment;

/**
 * GET /layouts — Layout gallery showing system and custom layouts.
 */
final class LayoutListHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly DashboardService $dashboardService,
        private readonly LayoutService $layoutService,
        private readonly PlanRepositoryInterface $planRepo,
        private readonly PricingService $pricingService,
        private readonly TwigEnvironment $twig,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::redirect('/login');
        }

        $resolved = $this->dashboardService->resolveSiteId($user, $request->getCookieParams());
        if (isset($resolved['redirect'])) {
            return ApiResponse::redirect($resolved['redirect']);
        }

        $siteId = (int) $resolved['siteId'];

        $systemLayouts = $this->layoutService->getSystemLayouts('gdpr');
        $customLayouts = $this->layoutService->getCustomLayouts($siteId);

        // Layout limit for the UI
        $userId = (int) $user['id'];
        $maxLayouts = $this->resolveLimit($userId, 'max_layouts');
        $canDuplicate = $maxLayouts === 0 || \count($customLayouts) < $maxLayouts;

        $html = $this->twig->render('pages/layouts/list.html.twig', [
            'title' => 'Layouts',
            'active_page' => 'layouts',
            'user' => $user,
            'sites' => $resolved['sites'],
            'siteId' => $siteId,
            'systemLayouts' => $systemLayouts,
            'customLayouts' => $customLayouts,
            'maxLayouts' => $maxLayouts,
            'canDuplicate' => $canDuplicate,
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
