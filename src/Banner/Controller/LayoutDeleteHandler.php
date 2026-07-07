<?php

declare(strict_types=1);

namespace OCI\Banner\Controller;

use OCI\Admin\Service\AuditLogService;
use OCI\Banner\Service\LayoutService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Site\Repository\SiteRepositoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /app/layouts/{id} — Delete a custom layout.
 */
final class LayoutDeleteHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepositoryInterface $siteRepo,
        private readonly LayoutService $layoutService,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $layoutId = (int) ($request->getAttribute('id') ?? 0);
        $layout = $this->layoutService->getCustomLayout($layoutId);

        if ($layout === null) {
            return ApiResponse::error('Layout not found', 404);
        }

        // Verify ownership
        $userId = (int) $user['id'];
        $siteId = (int) $layout['site_id'];
        $sites = $this->siteRepo->findAllByUser($userId);
        $siteIds = array_map(static fn(array $s): int => (int) $s['id'], $sites);

        if (!\in_array($siteId, $siteIds, true)) {
            return ApiResponse::error('Forbidden', 403);
        }

        $this->layoutService->deleteCustomLayout($layoutId, $siteId);

        $this->auditLogService->log(
            userId: $userId,
            action: 'delete',
            entityType: 'BannerLayout',
            entityId: $layoutId,
            oldValues: ['site_id' => $siteId, 'name' => $layout['name'] ?? ''],
            ipAddress: $request->getServerParams()['REMOTE_ADDR'] ?? null,
            userAgent: $request->getHeaderLine('User-Agent') ?: null,
        );

        return ApiResponse::success(['message' => 'Layout deleted']);
    }
}
