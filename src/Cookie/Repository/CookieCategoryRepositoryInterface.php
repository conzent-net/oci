<?php

declare(strict_types=1);

namespace OCI\Cookie\Repository;

interface CookieCategoryRepositoryInterface
{
    /**
     * Get all default cookie categories with translations for a language.
     * Falls back to language ID 1 (English) if no translations exist for the given language.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDefaultCategories(int $languageId): array;

    /**
     * Copy a global cookie category to a site, including the translation.
     */
    public function copyCategoryToSite(
        int $siteId,
        int $categoryId,
        int $languageId,
        string $name,
        string $description,
    ): void;

    /**
     * Get site categories with translations.
     *
     * @return list<array<string, mixed>>
     */
    public function getSiteCategories(int $siteId, int $languageId = 1): array;

    /**
     * Get a single site category by ID.
     *
     * @return array<string, mixed>|null
     */
    public function findSiteCategory(int $id): ?array;

    /**
     * Update a site category's sort order or default consent.
     *
     * @param array<string, mixed> $data
     */
    public function updateSiteCategory(int $id, array $data): void;

    /**
     * Update or create a site category translation.
     */
    public function upsertSiteCategoryTranslation(int $siteCategoryId, int $languageId, string $name, string $description): void;

    /**
     * Remove a site category (and its translations via CASCADE).
     */
    public function deleteSiteCategory(int $id): void;

    /**
     * Count cookies in each site category.
     *
     * @return array<int, int> category_id => count
     */
    public function countCookiesPerCategory(int $siteId): array;

    /**
     * Get all global categories (for the "add category" dropdown).
     *
     * @return list<array<string, mixed>>
     */
    public function getAllGlobalCategories(int $languageId = 1): array;
}
