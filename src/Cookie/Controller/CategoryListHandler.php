<?php

declare(strict_types=1);

namespace OCI\Cookie\Controller;

use OCI\Cookie\Service\CookieService;
use OCI\Dashboard\Service\DashboardService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Site\Repository\SiteRepositoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment as TwigEnvironment;

/**
 * GET /categories — List cookie categories for the current site.
 *
 * Mirrors legacy: categories.php
 */
final class CategoryListHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly CookieService $cookieService,
        private readonly DashboardService $dashboardService,
        private readonly SiteRepositoryInterface $siteRepo,
        private readonly TwigEnvironment $twig,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::redirect('/login');
        }

        $queryParams = $request->getQueryParams();
        $cookies = $request->getCookieParams();

        $resolved = $this->dashboardService->resolveSiteId($user, $cookies);
        if (isset($resolved['redirect'])) {
            return ApiResponse::redirect($resolved['redirect']);
        }

        $siteId = (int) $resolved['siteId'];
        $sites = $resolved['sites'];

        $data = $this->cookieService->getCategoryList($siteId);

        $templateData = [
            'title' => 'Cookie Categories',
            'user' => $user,
            'siteId' => $siteId,
            'sites' => $sites,
            'categories' => $data['categories'],
            'globalCategories' => $data['globalCategories'],
        ];

        return ApiResponse::html($this->twig->render('pages/categories/index.html.twig', $templateData));
    }
}
