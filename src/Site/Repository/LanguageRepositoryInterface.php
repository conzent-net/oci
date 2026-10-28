<?php

declare(strict_types=1);

namespace OCI\Site\Repository;

interface LanguageRepositoryInterface
{
    /**
     * Get the default language for a site.
     *
     * @return array{lang_id: int, lang_code: string}|null
     */
    public function getDefaultLanguage(int $siteId): ?array;

    /**
     * Get the system default language (from oci_languages where is_default=1).
     *
     * @return array<string, mixed>|null
     */
    public function getSystemDefaultLanguage(): ?array;

    /**
     * Find a language by ID from the global languages table.
     *
     * @return array<string, mixed>|null
     */
    public function findLanguageById(int $languageId): ?array;

    /**
     * Add a language to a site's language list.
     */
    public function addSiteLanguage(int $siteId, int $languageId, bool $isDefault): void;

    /**
     * Get all available system languages.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllLanguages(): array;

    /**
     * Get all languages configured for a site.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSiteLanguages(int $siteId): array;

    /**
     * Remove a language from a site.
     */
    public function removeSiteLanguage(int $siteId, int $languageId): void;

    /**
     * Set a language as the default for a site (unsets the current default).
     */
    public function setDefaultLanguage(int $siteId, int $languageId): void;

    /**
     * Count languages for a site.
     */
    public function countSiteLanguages(int $siteId): int;
}
