<?php

declare(strict_types=1);

namespace OCI\Banner\Controller;

use OCI\Banner\Service\LayoutService;
use OCI\Dashboard\Service\DashboardService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
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

        $html = $this->twig->render('pages/layouts/list.html.twig', [
            'title' => 'Layouts',
            'active_page' => 'layouts',
            'user' => $user,
            'sites' => $resolved['sites'],
            'siteId' => $siteId,
            'systemLayouts' => $systemLayouts,
            'customLayouts' => $customLayouts,
        ]);

        return ApiResponse::html($html);
    }
}
