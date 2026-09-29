<?php

declare(strict_types=1);

namespace OCI\Banner\Controller;

use OCI\Banner\Service\ScriptGenerationService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Site\Repository\SiteRepositoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/banners/purge — Force-regenerate script and purge caches for one site.
 */
final class BannerPurgeCacheHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly ScriptGenerationService $scriptService,
        private readonly SiteRepositoryInterface $siteRepo,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $siteId = (int) ($body['site_id'] ?? 0);

        if ($siteId <= 0) {
            return ApiResponse::error('site_id is required', 422);
        }

        $userId = (int) $user['id'];
        if (!$this->siteRepo->belongsToUser($siteId, $userId)) {
            return ApiResponse::error('Site not found', 404);
        }

        try {
            $this->scriptService->generate($siteId);

            return ApiResponse::success(['message' => 'Script regenerated and caches purged']);
        } catch (\Throwable $e) {
            return ApiResponse::error('Purge failed: ' . $e->getMessage(), 500);
        }
    }
}
