<?php

declare(strict_types=1);

namespace OCI\Banner\Controller;

use OCI\Admin\Service\AuditLogService;
use OCI\Banner\Repository\BannerRepositoryInterface;
use OCI\Banner\Service\BannerContent;
use OCI\Banner\Service\ScriptGenerationService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Site\Service\SiteAccess;
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
        private readonly SiteAccess $siteAccess,
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
        if (!$this->siteAccess->owns($user, $siteId)) {
            return ApiResponse::json(['success' => false, 'error' => 'Not found'], 404);
        }

        // The banner is addressed by its own id, so it must be one of this
        // site's banners: owning a site says nothing about the id posted.
        $siteBanners = $this->bannerRepo->getSiteBannerSettings($siteId);
        if (!\in_array($siteBannerId, array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $siteBanners), true)) {
            return ApiResponse::json(['success' => false, 'error' => 'Not found'], 404);
        }

        $written = [$siteBannerId => $fields];

        // Saving to every banner of the site: the GDPR and CCPA templates are
        // separate field sets, so the same text is matched by field key, not by
        // id. A text this banner's template does not have is skipped rather
        // than invented — most fields belong to one law only.
        if (($body['apply_to'] ?? 'row') === 'all') {
            $written = $this->fanOutByFieldKey($siteBanners, $siteBannerId, $fields);
        }

        foreach ($written as $bannerId => $bannerFields) {
            foreach ($bannerFields as $fieldId => $value) {
                $this->bannerRepo->upsertFieldTranslation(
                    (int) $bannerId,
                    (int) $fieldId,
                    $languageId,
                    (string) $value,
                );
            }
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

    /**
     * The same texts for every banner of the site, addressed by each
     * template's own field ids.
     *
     * @param list<array<string, mixed>> $siteBanners the site's banner rows
     * @param array<int|string, mixed> $fields field id → value, as posted
     * @return array<int, array<int, mixed>> banner id → its own field id → value
     */
    private function fanOutByFieldKey(array $siteBanners, int $postedBannerId, array $fields): array
    {
        $templateOf = [];
        foreach ($siteBanners as $row) {
            $templateOf[(int) ($row['id'] ?? 0)] = (int) ($row['banner_template_id'] ?? 0);
        }

        $keyOf = $this->fieldKeys($templateOf[$postedBannerId] ?? 0);
        $out = [];

        foreach ($templateOf as $bannerId => $templateId) {
            if ($bannerId === 0) {
                continue;
            }
            if ($bannerId === $postedBannerId) {
                $out[$bannerId] = $fields;
                continue;
            }

            $mapped = BannerContent::mapFieldsByKey($fields, $keyOf, $this->fieldKeys($templateId));
            if ($mapped !== []) {
                $out[$bannerId] = $mapped;
            }
        }

        return $out === [] ? [$postedBannerId => $fields] : $out;
    }

    /**
     * field id → field key for one banner template.
     *
     * @return array<int, string>
     */
    private function fieldKeys(int $templateId): array
    {
        if ($templateId === 0) {
            return [];
        }

        $keys = [];
        foreach ($this->bannerRepo->getBannerFieldsGrouped($templateId) as $group) {
            foreach ($group['fields'] ?? [] as $field) {
                $keys[(int) $field['id']] = (string) $field['field_key'];
            }
        }

        return $keys;
    }
}
