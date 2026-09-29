<?php

declare(strict_types=1);

namespace OCI\Scanning\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Scanning\Service\ScanService;
use OCI\Site\Repository\SiteRepositoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /app/scans/status?site_id=X — latest scan status for UI polling.
 *
 * Backs the dashboard's "Scan site" wizard step: progress while a scan
 * runs, the result once it completes, and whether the site has ever
 * finished a scan (gates the install step).
 */
final class ScanStatusHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly ScanService $scanService,
        private readonly SiteRepositoryInterface $siteRepo,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $siteId = (int) ($request->getQueryParams()['site_id'] ?? 0);
        if ($siteId <= 0) {
            return ApiResponse::error('Missing site_id', 400);
        }

        if (!$this->siteRepo->belongsToUser($siteId, (int) $user['id'])) {
            return ApiResponse::error('Site not found', 404);
        }

        return ApiResponse::success($this->scanService->getLatestScanStatus($siteId));
    }
}
