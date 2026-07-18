<?php

declare(strict_types=1);

namespace OCI\Consent\Controller;

use OCI\Consent\Repository\ConsentRepositoryInterface;
use OCI\Dashboard\Service\DashboardService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment as TwigEnvironment;

/**
 * GET /consents — Consent logs page with analytics cards, trend chart, and paginated log table.
 */
final class ConsentListHandler implements RequestHandlerInterface
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly ConsentRepositoryInterface $consentRepo,
        private readonly DashboardService $dashboardService,
        private readonly TwigEnvironment $twig,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            return $this->doHandle($request);
        } catch (\Throwable $e) {
            // Temporary: surface the real error for debugging
            return ApiResponse::error(
                $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(),
                500,
            );
        }
    }

    private function doHandle(ServerRequestInterface $request): ResponseInterface
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

        // Pagination & filters
        $page = max(1, (int) ($queryParams['page'] ?? 1));
        $filters = [
            'status' => $queryParams['status'] ?? '',
            'date_from' => $queryParams['date_from'] ?? '',
            'date_to' => $queryParams['date_to'] ?? '',
        ];
        $days = (int) ($queryParams['days'] ?? 7);
        if (!\in_array($days, [7, 30, 90], true)) {
            $days = 7;
        }

        // Fetch data
        $consents = $this->consentRepo->getConsentLog($siteId, $page, self::PER_PAGE, $filters);
        $totalCount = $this->consentRepo->getConsentLogCount($siteId, $filters);
        $totalPages = max(1, (int) ceil($totalCount / self::PER_PAGE));

        // Analytics
        $comparison = $this->consentRepo->getPeriodComparison($siteId, $days);
        $trend = $this->consentRepo->getDailyTrend($siteId, $days);

        $templateData = [
            'title' => 'Consent Logs',
            'user' => $user,
            'siteId' => $siteId,
            'sites' => $sites,
            'consents' => $consents,
            'totalCount' => $totalCount,
            'page' => $page,
            'totalPages' => $totalPages,
            'perPage' => self::PER_PAGE,
            'filters' => $filters,
            'days' => $days,
            'comparison' => $comparison,
            'trend' => $trend,
        ];

        if ($request->getHeaderLine('HX-Request') === 'true') {
            return ApiResponse::html($this->twig->render('pages/consent/_consent_table.html.twig', $templateData));
        }

        return ApiResponse::html($this->twig->render('pages/consent/list.html.twig', $templateData));
    }
}
