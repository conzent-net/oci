<?php

declare(strict_types=1);

namespace OCI\Banner\Controller;

use OCI\Banner\Service\LayoutService;
use OCI\Dashboard\Service\DashboardService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Monetization\Service\PricingService;
use OCI\Monetization\Service\SubscriptionService;
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
        private readonly PricingService $pricingService,
        private readonly TwigEnvironment $twig,
        private readonly ?SubscriptionService $subscriptionService = null,
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

        // Layout limit for the UI — custom layouts require business plan (prevents branding bypass)
        // Self-hosted (no SubscriptionService): all features unlocked
        // Cloud with no/personal plan: custom_layouts feature blocked
        $userId = (int) $user['id'];
        $isCloud = $this->subscriptionService !== null;
        $planKey = $this->subscriptionService?->getPlanKey($userId);
        $maxLayouts = $planKey !== null ? $this->pricingService->getLimit($planKey, 'max_layouts') : 0;
        $hasCustomLayouts = !$isCloud || ($planKey !== null && $this->pricingService->hasFeature($planKey, 'custom_layouts'));
        $canDuplicate = $hasCustomLayouts && ($maxLayouts === 0 || \count($customLayouts) < $maxLayouts);

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
}
