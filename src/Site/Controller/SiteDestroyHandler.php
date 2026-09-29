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
 * POST /app/sites/{id}/destroy — Permanently remove a site.
 *
 * Deletes the site record and all associated data (banners, translations,
 * languages, wizards). Also removes generated script files.
 * This action cannot be undone.
 * Mirrors legacy: action.php → destroy_website
 */
final class SiteDestroyHandler implements RequestHandlerInterface
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

        // Get website key before destroying so we can remove script files
        $websiteKey = $this->siteRepository->getWebsiteKey($siteId);

        $this->siteRepository->destroy($siteId);

        if ($websiteKey !== null) {
            $this->scriptService->deleteScriptFiles($websiteKey);
        }

        return ApiResponse::success(['message' => 'Site permanently removed']);
    }
}
