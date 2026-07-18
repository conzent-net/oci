<?php

declare(strict_types=1);

namespace OCI\Banner\Controller;

use OCI\Admin\Service\AuditLogService;
use OCI\Banner\Repository\BannerRepositoryInterface;
use OCI\Banner\Service\ScriptGenerationService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Site\Repository\SiteRepositoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/banners/content — Save banner content translations.
 *
 * Accepts JSON: { site_banner_id, language_id, fields: { field_id: value, ... } }
 */
final class BannerContentUpdateHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepositoryInterface $siteRepo,
        private readonly BannerRepositoryInterface $bannerRepo,
        private readonly ScriptGenerationService $scriptService,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, mixed>|null $user */
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $siteBannerId = (int) ($body['site_banner_id'] ?? 0);
        $languageId = (int) ($body['language_id'] ?? 0);
        $fields = (array) ($body['fields'] ?? []);
        $siteId = (int) ($body['site_id'] ?? 0);

        if ($siteBannerId === 0 || $languageId === 0 || $siteId === 0) {
            return ApiResponse::json(['success' => false, 'error' => 'Missing required fields'], 422);
        }

        $userId = (int) $user['id'];
        if (!$this->siteRepo->belongsToUser($siteId, $userId)) {
            return ApiResponse::json(['success' => false, 'error' => 'Forbidden'], 403);
        }

        foreach ($fields as $fieldId => $value) {
            $this->bannerRepo->upsertFieldTranslation(
                $siteBannerId,
                (int) $fieldId,
                $languageId,
                (string) $value,
            );
        }

        // Regenerate the consent script after content change
        $this->scriptService->generate($siteId);

        $this->auditLogService->log(
            userId: $userId,
            action: 'update',
            entityType: 'BannerContent',
            entityId: $siteBannerId,
            newValues: ['language_id' => $languageId, 'field_count' => \count($fields), 'site_id' => $siteId],
            ipAddress: $request->getServerParams()['REMOTE_ADDR'] ?? null,
            userAgent: $request->getHeaderLine('User-Agent') ?: null,
        );

        return ApiResponse::json(['success' => true]);
    }
}
