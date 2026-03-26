<?php

declare(strict_types=1);

namespace OCI\Scanning\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Scanning\Service\ScanService;
use OCI\Site\Repository\SiteRepositoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment as TwigEnvironment;

/**
 * GET /scans/{id} — Scan detail page (results, cookies found, beacons).
 */
final class ScanDetailHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly ScanService $scanService,
        private readonly SiteRepositoryInterface $siteRepo,
        private readonly TwigEnvironment $twig,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::redirect('/login');
        }

        $scanId = (int) ($request->getAttribute('id') ?? 0);
        if ($scanId <= 0) {
            return ApiResponse::redirect('/scans');
        }

        try {
            $details = $this->scanService->getScanDetails($scanId);
        } catch (\InvalidArgumentException) {
            return ApiResponse::redirect('/scans');
        }

        // Verify ownership
        $siteId = (int) $details['scan']['site_id'];
        if (!$this->siteRepo->belongsToUser($siteId, (int) $user['id'])) {
            return ApiResponse::redirect('/scans');
        }

        $site = $this->siteRepo->findById($siteId);

        // JSON response
        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return ApiResponse::success($details);
        }

        $html = $this->twig->render('pages/scans/detail.html.twig', [
            'title' => 'Scan #' . $scanId,
            'user' => $user,
            'site' => $site,
            'siteId' => $siteId,
            'scanId' => $scanId,
            'scan' => $details['scan'],
            'cookies' => $details['cookies'],
            'cookiesByCategory' => $details['cookiesByCategory'],
            'urls' => $details['urls'],
            'beacons' => $details['beacons'],
            'stats' => $details['stats'],
        ]);

        return ApiResponse::html($html);
    }
}
