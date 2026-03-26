<?php

declare(strict_types=1);

namespace OCI\Dashboard\Controller;

use OCI\Dashboard\Service\DashboardService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/dashboard/consent-report — AJAX consent report data.
 *
 * Accepts: site_id, report_type (7days|30days|alltime|custom_range), date_range
 * Returns: JSON {accepted, rejected, partially_accepted}
 */
final class ConsentReportHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly DashboardService $dashboardService,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::error('Unauthorized', 401);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $siteId = (int) ($body['site_id'] ?? 0);
        $reportType = (string) ($body['report_type'] ?? '7days');
        $dateRange = (string) ($body['date_range'] ?? '');

        if ($siteId <= 0) {
            return ApiResponse::error('Invalid site ID', 422);
        }

        $report = $this->dashboardService->getConsentReport($siteId, $reportType, $dateRange);

        return ApiResponse::success($report->toArray());
    }
}
