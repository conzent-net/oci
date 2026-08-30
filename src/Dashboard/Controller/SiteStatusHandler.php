<?php

declare(strict_types=1);

namespace OCI\Dashboard\Controller;

use OCI\Dashboard\Service\ComplianceCheckService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/dashboard/site-status — Toggle site active/inactive status.
 *
 * Accepts: site_id, status (active|inactive)
 * Returns: JSON {status (compliance), site_status}
 */
final class SiteStatusHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly ComplianceCheckService $complianceService,
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
        $status = (string) ($body['status'] ?? '');

        if ($siteId <= 0) {
            return ApiResponse::error('Invalid site ID', 422);
        }

        if (!\in_array($status, ['active', 'inactive'], true)) {
            return ApiResponse::error('Invalid status', 422);
        }

        $result = $this->complianceService->toggleSiteStatus($siteId, $status);

        return ApiResponse::success($result);
    }
}
