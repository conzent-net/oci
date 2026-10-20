<?php

declare(strict_types=1);

namespace OCI\Report\Controller;

use OCI\Http\Response\ApiResponse;
use OCI\Report\Service\ReportService;
use OCI\Site\Service\SiteAccess;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use OCI\Http\Handler\RequestHandlerInterface;

final class ReportDeleteHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly ReportService $reportService,
        private readonly SiteAccess $siteAccess,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $id = (int) $request->getAttribute('id');
        $report = $this->reportService->getReport($id);

        // Another tenant's report reads as missing, so ids can't be probed
        if ($report === null || !$this->siteAccess->owns($user, (int) ($report['site_id'] ?? 0))) {
            return ApiResponse::error('Report not found', 404);
        }

        $this->reportService->delete($id);

        return ApiResponse::success(['message' => 'Report deleted']);
    }
}
