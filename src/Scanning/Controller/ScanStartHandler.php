<?php

declare(strict_types=1);

namespace OCI\Scanning\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Scanning\Service\ScanService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/scans/start — Start a new cookie scan.
 *
 * Body: { site_id: int, scan_type?: "full"|"custom", urls?: string[] }
 */
final class ScanStartHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly ScanService $scanService,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $body = (array) $request->getParsedBody();
        $siteId = (int) ($body['site_id'] ?? 0);

        if ($siteId <= 0) {
            return ApiResponse::error('site_id is required', 422);
        }

        $scanType = (string) ($body['scan_type'] ?? 'full');
        $includeUrls = (array) ($body['urls'] ?? []);
        $excludeUrls = (array) ($body['exclude_urls'] ?? []);

        try {
            $result = $this->scanService->initiateScan(
                $siteId,
                (int) $user['id'],
                $scanType,
                $includeUrls,
                $excludeUrls,
            );

            return ApiResponse::success($result);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 404);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }
}
