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
 * GET /cookies — List cookies for the current site.
 *
 * Mirrors legacy: cookie_lists.php
 */
final class CookieListHandler implements RequestHandlerInterface
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

        $search = $queryParams['search'] ?? null;

        $grouped = $this->cookieService->getCookiesGroupedByCategory($siteId, $search);

        $templateData = [
            'title' => 'Cookies',
            'user' => $user,
            'siteId' => $siteId,
            'sites' => $sites,
            'tabCategories' => $grouped['categories'],
            'cookiesByCategory' => $grouped['cookiesByCategory'],
            'totalCookies' => $grouped['totalCookies'],
            'scanId' => $grouped['scanId'],
            'currentSearch' => $search,
        ];

        if ($request->getHeaderLine('HX-Request') === 'true') {
            return ApiResponse::html($this->twig->render('pages/cookies/_cookie_table.html.twig', $templateData));
        }

        return ApiResponse::html($this->twig->render('pages/cookies/index.html.twig', $templateData));
    }
}
