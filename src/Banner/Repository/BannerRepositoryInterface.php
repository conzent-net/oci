<?php

declare(strict_types=1);

namespace OCI\Banner\Repository;

interface BannerRepositoryInterface
{
    /**
     * Get all banner settings for a site, optionally filtered by consent type.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSiteBannerSettings(int $siteId, string $consentType = ''): array;

    /**
     * Get user banner content translations.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getUserBannerContent(int $siteId, int $languageId, string $cookieLaws = ''): array;

    /**
     * Get the website key for a site.
     */
    public function getWebsiteKeyBySiteId(int $siteId): string;

    /**
     * Update a site banner setting.
     *
     * @param array<string, mixed> $data
     */
    public function updateBannerSetting(int $bannerId, array $data): void;

    /**
     * Update a banner content translation value.
     *
     * @param array<string, mixed> $data
     */
    public function updateBannerContent(int $contentId, array $data): void;

    /**
     * Get the default GDPR banner template.
     *
     * @return array<string, mixed>|null
     */
    public function getDefaultBannerTemplate(): ?array;

    /**
     * Get all active banner templates.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllBannerTemplates(): array;

    /**
     * Create a site banner record with default settings.
     *
     * @param array<string, mixed> $data
     */
    public function createSiteBanner(array $data): int;

    /**
     * Copy default banner field translations to a site banner.
     */
    public function copyDefaultBannerTranslations(int $siteBannerId, int $templateId, int $languageId): void;

    /**
     * Get all banner fields grouped by category for a template.
     *
     * @return array<int, array{category: string, category_key: string, fields: array<int, array<string, mixed>>}>
     */
    public function getBannerFieldsGrouped(int $templateId): array;

    /**
     * Get site banner field translation values for a language.
     *
     * @return array<int, string> field_id => value
     */
    public function getSiteBannerFieldValues(int $siteBannerId, int $languageId): array;

    /**
     * Get default field values (from template translations) for a language.
     *
     * @return array<int, string> field_id => default label
     */
    public function getDefaultFieldValues(int $templateId, int $languageId): array;

    /**
     * Insert or update a site banner field translation.
     */
    public function upsertFieldTranslation(int $siteBannerId, int $fieldId, int $languageId, string $value): void;
}
