<?php

declare(strict_types=1);

namespace OCI\Scanning\Controller;

use OCI\Dashboard\Service\DashboardService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Scanning\Repository\ScanRepositoryInterface;
use OCI\Site\Repository\SiteRepositoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment as TwigEnvironment;

/**
 * GET /scans — Scan management page.
 *
 * Lists all scans for the current site with status, results summary,
 * and actions (start scan, cancel, view details, schedule).
 */
final class ScanListHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly ScanRepositoryInterface $scanRepo,
        private readonly SiteRepositoryInterface $siteRepo,
        private readonly DashboardService $dashboardService,
        private readonly TwigEnvironment $twig,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::redirect('/login');
        }

        $userId = (int) $user['id'];
        $queryParams = $request->getQueryParams();
        $cookies = $request->getCookieParams();

        // Resolve site
        $resolved = $this->dashboardService->resolveSiteId($user, $cookies);
        if ($resolved['redirect'] !== null) {
            return ApiResponse::redirect($resolved['redirect']);
        }

        $siteId = (int) $resolved['siteId'];

        // Pagination
        $page = max(1, (int) ($queryParams['page'] ?? 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $scans = $this->scanRepo->findBySite($siteId, $perPage, $offset);
        $total = $this->scanRepo->countBySite($siteId);
        $totalPages = (int) ceil($total / $perPage);

        // Get server list for the scan form
        $servers = $this->scanRepo->getAllScanServers();
        $hasActiveScans = $this->scanRepo->hasActiveScan($siteId);
        $lastScan = $this->scanRepo->getLastCompletedScan($siteId);
        $nextScheduled = $this->scanRepo->getNextScheduledScan($siteId);

        // Get all user sites for site selector
        $allSites = $this->siteRepo->findAllByUser($userId);

        $data = [
            'title' => 'Cookie Scans',
            'user' => $user,
            'siteId' => $siteId,
            'sites' => $allSites,
            'scans' => $scans,
            'servers' => $servers,
            'hasActiveScans' => $hasActiveScans,
            'lastScan' => $lastScan,
            'nextScheduled' => $nextScheduled,
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'totalPages' => $totalPages,
            ],
        ];

        // htmx partial — includes notification + table wrapped in swap target
        if ($request->getHeaderLine('HX-Request') === 'true') {
            $html = $this->twig->render('pages/scans/_scan_content.html.twig', $data);
            return ApiResponse::html($html);
        }

        $html = $this->twig->render('pages/scans/index.html.twig', $data);
        $response = ApiResponse::html($html);

        return $response->withAddedHeader(
            'Set-Cookie',
            'site_id=' . $siteId . '; Path=/; SameSite=Lax; Max-Age=31536000',
        );
    }
}
