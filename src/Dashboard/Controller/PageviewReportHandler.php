<?php

declare(strict_types=1);

namespace OCI\Dashboard\Controller;

use OCI\Dashboard\Service\DashboardService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/dashboard/pageview-report — AJAX pageview report data.
 *
 * Accepts: site_id, report_type (7days|30days|alltime|custom_range), date_range
 * Returns: JSON [{date, views}]
 */
final class PageviewReportHandler implements RequestHandlerInterface
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

        $data = $this->dashboardService->getPageviewReport($siteId, $reportType, $dateRange);

        return ApiResponse::success(['pageviews' => array_values($data)]);
    }
}
