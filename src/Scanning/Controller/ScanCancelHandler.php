<?php

declare(strict_types=1);

namespace OCI\Scanning\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Scanning\Service\ScanService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/scans/{id}/cancel — Cancel an in-progress scan.
 */
final class ScanCancelHandler implements RequestHandlerInterface
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

        $scanId = (int) ($request->getAttribute('id') ?? 0);
        if ($scanId <= 0) {
            return ApiResponse::error('Invalid scan ID', 422);
        }

        try {
            $this->scanService->cancelScan($scanId, (int) $user['id']);

            return ApiResponse::success(['message' => 'Scan cancelled']);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 404);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }
}
