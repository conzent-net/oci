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
 * POST /app/layouts/duplicate — Duplicate a system layout into a custom layout.
 */
final class LayoutDuplicateHandler implements RequestHandlerInterface
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

        $body = json_decode((string) $request->getBody(), true) ?? [];
        $baseLayoutKey = $body['base_layout_key'] ?? '';
        $name = trim($body['name'] ?? '');
        $siteId = (int) ($body['site_id'] ?? 0);

        if ($baseLayoutKey === '' || $name === '' || $siteId === 0) {
            return ApiResponse::error('Missing required fields: base_layout_key, name, site_id', 422);
        }

        // Verify ownership
        $userId = (int) $user['id'];
        $sites = $this->siteRepo->findAllByUser($userId);
        $siteIds = array_map(static fn(array $s): int => (int) $s['id'], $sites);

        if (!\in_array($siteId, $siteIds, true)) {
            return ApiResponse::error('Forbidden', 403);
        }

        $layoutId = $this->layoutService->duplicateLayout($siteId, $baseLayoutKey, $name);

        $this->auditLogService->log(
            userId: $userId,
            action: 'create',
            entityType: 'BannerLayout',
            entityId: $layoutId,
            newValues: ['site_id' => $siteId, 'name' => $name, 'base_layout_key' => $baseLayoutKey],
            ipAddress: $request->getServerParams()['REMOTE_ADDR'] ?? null,
            userAgent: $request->getHeaderLine('User-Agent') ?: null,
        );

        return ApiResponse::success([
            'id' => $layoutId,
            'redirect' => '/layouts/' . $layoutId . '/edit',
        ]);
    }
}
