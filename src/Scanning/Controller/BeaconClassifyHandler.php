<?php

declare(strict_types=1);

namespace OCI\Scanning\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Scanning\Service\BeaconClassificationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/beacons/classify — Classify an unclassified third-party beacon/script
 * into a category. Per-site and immediate.
 */
final class BeaconClassifyHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly BeaconClassificationService $beaconService,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $body = json_decode((string) $request->getBody(), true) ?? [];
        $beaconId = (int) ($body['beacon_id'] ?? 0);
        $categoryId = (int) ($body['category_id'] ?? 0);

        if ($beaconId === 0 || $categoryId === 0) {
            return ApiResponse::error('Beacon and category are required');
        }

        $result = $this->beaconService->classifyBeacon(
            (int) $user['id'],
            $beaconId,
            $categoryId,
            $request->getServerParams()['REMOTE_ADDR'] ?? null,
            $request->getHeaderLine('User-Agent') ?: null,
        );

        if (!$result['success']) {
            return ApiResponse::error($result['error']);
        }

        return ApiResponse::success();
    }
}
