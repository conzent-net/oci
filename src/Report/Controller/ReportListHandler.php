<?php

declare(strict_types=1);

namespace OCI\Report\Controller;

use OCI\Dashboard\Service\DashboardService;
use OCI\Http\Response\ApiResponse;
use OCI\Report\Service\ReportService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use OCI\Http\Handler\RequestHandlerInterface;
use Twig\Environment as TwigEnvironment;

final class ReportListHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly ReportService $reportService,
        private readonly DashboardService $dashboardService,
        private readonly TwigEnvironment $twig,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, mixed>|null $user */
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::redirect('/login');
        }

        $resolved = $this->dashboardService->resolveSiteId($user, $request->getCookieParams());
        if ($resolved['redirect'] !== null) {
            return ApiResponse::redirect($resolved['redirect']);
        }

        $siteId = (int) $resolved['siteId'];
        $page = max(1, (int) ($request->getQueryParams()['page'] ?? 1));

        $result = $this->reportService->getReportsBySite($siteId, $page);
        $schedule = $this->reportService->getSchedule($siteId, 'full');

        $html = $this->twig->render('pages/reports/index.html.twig', [
            'title' => 'Reports',
            'active_page' => 'reports',
            'user' => $user,
            'siteId' => $siteId,
            'sites' => $resolved['sites'],
            'reports' => $result['reports'],
            'total' => $result['total'],
            'page' => $page,
            'perPage' => 20,
            'schedule' => $schedule,
        ]);

        return ApiResponse::html($html);
    }
}
