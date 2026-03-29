<?php

declare(strict_types=1);

namespace OCI\Site\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Site\Service\MatomoApiService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /app/matomo/containers?matomo_site_id=X — List Matomo TM containers (JSON).
 *
 * Requires valid Matomo credentials in the session (set by MatomoValidateHandler).
 */
final class MatomoContainersHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly MatomoApiService $matomoApi,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $matomoUrl = $_SESSION['matomo_url'] ?? '';
        $matomoToken = $_SESSION['matomo_token'] ?? '';

        if ($matomoUrl === '' || $matomoToken === '') {
            return ApiResponse::error('Matomo session not found. Please validate credentials first.', 401);
        }

        $params = $request->getQueryParams();
        $matomoSiteId = (int) ($params['matomo_site_id'] ?? 0);

        if ($matomoSiteId <= 0) {
            return ApiResponse::error('matomo_site_id is required.', 422);
        }

        $containers = $this->matomoApi->listContainers($matomoUrl, $matomoToken, $matomoSiteId);

        return ApiResponse::success(['containers' => $containers]);
    }
}
