<?php

declare(strict_types=1);

namespace OCI\Cookie\Controller;

use OCI\Cookie\Service\CookieService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Scanning\Repository\ScanRepositoryInterface;
use OCI\Site\Service\SiteAccess;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/cookies/import — Import cookies from a scan.
 */
final class CookieImportHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly CookieService $cookieService,
        private readonly SiteAccess $siteAccess,
        private readonly ScanRepositoryInterface $scanRepo,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $body = json_decode((string) $request->getBody(), true) ?? [];
        $siteId = (int) ($body['site_id'] ?? 0);
        $scanId = (int) ($body['scan_id'] ?? 0);

        if ($siteId === 0 || $scanId === 0) {
            return ApiResponse::error('Site ID and Scan ID are required');
        }

        if (!$this->siteAccess->owns($user, $siteId)) {
            return ApiResponse::error('Site not found', 404);
        }

        // The scan must be this site's; anyone else's reads as missing.
        $scan = $this->scanRepo->findById($scanId);
        if ($scan === null || (int) ($scan['site_id'] ?? 0) !== $siteId) {
            return ApiResponse::error('Scan not found', 404);
        }

        $result = $this->cookieService->importFromScan((int) $user['id'], $siteId, $scanId);

        if (!$result['success']) {
            return ApiResponse::error($result['error']);
        }

        return ApiResponse::success(['imported' => $result['imported']]);
    }
}
