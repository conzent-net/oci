<?php

declare(strict_types=1);

namespace OCI\Site\Repository;

use Doctrine\DBAL\Connection;

final class LanguageRepository implements LanguageRepositoryInterface
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    public function getDefaultLanguage(int $siteId): ?array
    {
        $sql = <<<'SQL'
            SELECT sl.language_id AS lang_id, l.lang_code AS lang_code
            FROM oci_site_languages AS sl
            INNER JOIN oci_languages AS l ON l.id = sl.language_id
            WHERE sl.site_id = :siteId AND sl.is_default = 1
            LIMIT 1
        SQL;

        $row = $this->db->fetchAssociative($sql, ['siteId' => $siteId]);

        if ($row === false) {
            // Fallback: get any language for the site
            $fallback = $this->db->fetchAssociative(
                'SELECT sl.language_id AS lang_id, l.lang_code AS lang_code FROM oci_site_languages AS sl INNER JOIN oci_languages AS l ON l.id = sl.language_id WHERE sl.site_id = :siteId LIMIT 1',
                ['siteId' => $siteId],
            );

            return $fallback !== false ? ['lang_id' => (int) $fallback['lang_id'], 'lang_code' => (string) $fallback['lang_code']] : null;
        }

        return ['lang_id' => (int) $row['lang_id'], 'lang_code' => (string) $row['lang_code']];
    }

    public function getSystemDefaultLanguage(): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM oci_languages WHERE is_default = 1 LIMIT 1',
        );

        return $row !== false ? $row : null;
    }

    public function findLanguageById(int $languageId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM oci_languages WHERE id = :id',
            ['id' => $languageId],
        );

        return $row !== false ? $row : null;
    }

    public function addSiteLanguage(int $siteId, int $languageId, bool $isDefault): void
    {
        // Check if already exists
        $exists = $this->db->fetchOne(
            'SELECT COUNT(*) FROM oci_site_languages WHERE site_id = :siteId AND language_id = :langId',
            ['siteId' => $siteId, 'langId' => $languageId],
        );

        if ($exists !== false && (int) $exists > 0) {
            return;
        }

        $this->db->insert('oci_site_languages', [
            'site_id' => $siteId,
            'language_id' => $languageId,
            'is_default' => $isDefault ? 1 : 0,
        ]);
    }

    public function getAllLanguages(): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT * FROM oci_languages ORDER BY lang_name ASC',
        );
    }

    public function getSiteLanguages(int $siteId): array
    {
        $sql = <<<'SQL'
            SELECT l.*, sl.is_default
            FROM oci_site_languages AS sl
            INNER JOIN oci_languages AS l ON l.id = sl.language_id
            WHERE sl.site_id = :siteId
            ORDER BY sl.is_default DESC, l.lang_name ASC
        SQL;

        return $this->db->fetchAllAssociative($sql, ['siteId' => $siteId]);
    }

    public function removeSiteLanguage(int $siteId, int $languageId): void
    {
        $this->db->delete('oci_site_languages', [
            'site_id' => $siteId,
            'language_id' => $languageId,
        ]);
    }

    public function setDefaultLanguage(int $siteId, int $languageId): void
    {
        $this->db->executeStatement(
            'UPDATE oci_site_languages SET is_default = 0 WHERE site_id = :siteId',
            ['siteId' => $siteId],
        );

        $this->db->executeStatement(
            'UPDATE oci_site_languages SET is_default = 1 WHERE site_id = :siteId AND language_id = :langId',
            ['siteId' => $siteId, 'langId' => $languageId],
        );
    }

    public function countSiteLanguages(int $siteId): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM oci_site_languages WHERE site_id = :siteId',
            ['siteId' => $siteId],
        );
    }
}
