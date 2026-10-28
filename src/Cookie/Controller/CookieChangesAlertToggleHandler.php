<?php

declare(strict_types=1);

namespace OCI\Cookie\Controller;

use OCI\Cookie\Service\CookieRegisterDiffService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Site\Repository\SiteRepositoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/cookies/changes-alerts — Toggle the register change alert mail
 * for a site. Body: {site_id, enabled: 0|1}.
 */
final class CookieChangesAlertToggleHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly CookieRegisterDiffService $diffService,
        private readonly SiteRepositoryInterface $siteRepo,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $body = $request->getParsedBody();
        $siteId = (int) ($body['site_id'] ?? 0);

        if ($siteId <= 0 || !$this->siteRepo->belongsToUser($siteId, (int) $user['id'])) {
            return ApiResponse::error('Site not found', 404);
        }

        $enabled = (bool) (int) ($body['enabled'] ?? 1);
        $this->diffService->setAlertsEnabled($siteId, $enabled);

        return ApiResponse::success(['enabled' => $enabled]);
    }
}
