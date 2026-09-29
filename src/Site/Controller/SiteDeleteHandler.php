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
 * DELETE /app/sites/{id} — Soft-delete a site.
 *
 * Sets status to 'deleted' and populates deleted_at.
 * The site can be restored later via SiteRestoreHandler.
 * Also deletes the generated script files so the banner stops working.
 * Mirrors legacy: action.php → delete_website
 */
final class SiteDeleteHandler implements RequestHandlerInterface
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

        // Get website key before soft-deleting so we can remove script files
        $websiteKey = $this->siteRepository->getWebsiteKey($siteId);

        $this->siteRepository->softDelete($siteId);

        if ($websiteKey !== null) {
            $this->scriptService->deleteScriptFiles($websiteKey);
        }

        return ApiResponse::success(['message' => 'Site deleted']);
    }
}
