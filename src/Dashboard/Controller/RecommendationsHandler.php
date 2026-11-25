<?php

declare(strict_types=1);

namespace OCI\Dashboard\Controller;

use OCI\Dashboard\Service\DashboardService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Site\Service\SiteAccess;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /app/dashboard/recommendations — Return fresh recommendations as JSON.
 */
final class RecommendationsHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly DashboardService $dashboardService,
        private readonly SiteAccess $siteAccess,
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

        if (!$this->siteAccess->owns($user, $siteId)) {
            return ApiResponse::error('Site not found', 404);
        }

        $result = $this->dashboardService->getRecommendationsWithScore($user, $siteId);

        return ApiResponse::success($result);
    }
}
