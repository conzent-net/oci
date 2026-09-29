<?php

declare(strict_types=1);

namespace OCI\Scanning\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Scanning\Service\BeaconReclassificationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/beacons/reclassify-request — Request that a Conzent-classified beacon
 * be moved to a different category. Reviewed by staff; the requesting site is
 * unchanged until approved.
 */
final class BeaconReclassRequestHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly BeaconReclassificationService $reclassService,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $body = json_decode((string) $request->getBody(), true) ?? [];
        $siteId = (int) ($body['site_id'] ?? 0);
        $beaconDomain = trim((string) ($body['beacon_domain'] ?? ''));
        $currentSlug = isset($body['current_category_slug']) ? trim((string) $body['current_category_slug']) : null;
        $requestedCategoryId = (int) ($body['requested_category_id'] ?? 0);
        $reason = isset($body['reason']) ? trim((string) $body['reason']) : null;

        if ($siteId === 0 || $beaconDomain === '' || $requestedCategoryId === 0) {
            return ApiResponse::error('Site, beacon domain and target category are required');
        }

        $result = $this->reclassService->submitRequest(
            (int) $user['id'],
            $siteId,
            $beaconDomain,
            $currentSlug !== '' ? $currentSlug : null,
            $requestedCategoryId,
            $reason !== '' ? $reason : null,
            $request->getServerParams()['REMOTE_ADDR'] ?? null,
            $request->getHeaderLine('User-Agent') ?: null,
        );

        if (!$result['success']) {
            return ApiResponse::error($result['error']);
        }

        return ApiResponse::success();
    }
}
