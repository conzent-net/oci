<?php

declare(strict_types=1);

namespace OCI\Site\Controller;

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
 * Mirrors legacy: action.php → delete_website
 */
final class SiteDeleteHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepositoryInterface $siteRepository,
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

        $this->siteRepository->softDelete($siteId);

        return ApiResponse::success(['message' => 'Site deleted']);
    }
}
