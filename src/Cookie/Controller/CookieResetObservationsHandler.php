<?php

declare(strict_types=1);

namespace OCI\Cookie\Controller;

use Doctrine\DBAL\Connection;
use OCI\Dashboard\Service\DashboardService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/cookies/reset-observations — Clear all beacon observation data for a site.
 */
final class CookieResetObservationsHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Connection $db,
        private readonly DashboardService $dashboardService,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $resolved = $this->dashboardService->resolveSiteId($user, $request->getCookieParams());
        if (isset($resolved['redirect'])) {
            return ApiResponse::error('No site selected');
        }

        $siteId = (int) $resolved['siteId'];

        $deleted = $this->db->executeStatement(
            'DELETE FROM oci_cookie_observations WHERE site_id = :siteId',
            ['siteId' => $siteId],
        );

        return ApiResponse::success(['deleted' => $deleted]);
    }
}
