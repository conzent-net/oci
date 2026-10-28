<?php

declare(strict_types=1);

namespace OCI\Scanning\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Scanning\Service\ScanService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/scans/schedule — Schedule a scan for later execution.
 *
 * Body: { site_id: int, frequency: "once"|"monthly", date?: "Y-m-d", time?: "HH:MM" }
 */
final class ScanScheduleHandler implements RequestHandlerInterface
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

        $frequency = (string) ($body['frequency'] ?? '');
        $date = !empty($body['date']) ? (string) $body['date'] : null;
        $time = !empty($body['time']) ? (string) $body['time'] . ':00' : null;

        try {
            $result = $this->scanService->scheduleScan(
                $siteId,
                (int) $user['id'],
                $frequency,
                $date,
                $time,
            );

            return ApiResponse::success($result);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }
}
