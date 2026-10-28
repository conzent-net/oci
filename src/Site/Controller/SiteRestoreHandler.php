<?php

declare(strict_types=1);

namespace OCI\Site\Controller;

use OCI\Banner\Service\ScriptGenerationService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Site\Repository\SiteRepositoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/sites/{id}/restore — Restore a soft-deleted site.
 *
 * Sets status back to 'active' and clears deleted_at.
 * Regenerates the consent script so the banner works again.
 * Mirrors legacy: action.php → restore_website
 */
final class SiteRestoreHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepositoryInterface $siteRepository,
        private readonly ScriptGenerationService $scriptService,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $userId = (int) $user['id'];
        $siteId = (int) $request->getAttribute('id');

        if ($siteId <= 0) {
            return ApiResponse::error('Invalid site ID', 400);
        }

        if (!$this->siteRepository->belongsToUser($siteId, $userId)) {
            return ApiResponse::error('Site not found', 404);
        }

        $this->siteRepository->restore($siteId);

        // Regenerate the consent script now that the site is active again
        $this->scriptService->generate($siteId);

        return ApiResponse::success(['message' => 'Site restored']);
    }
}
