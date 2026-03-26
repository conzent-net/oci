<?php

declare(strict_types=1);

namespace OCI\Cookie\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class CookieCategoryRepository implements CookieCategoryRepositoryInterface
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    public function getDefaultCategories(int $languageId): array
    {
        // Get categories with translations for the requested language
        // Mirrors legacy GdprCookie::getDefaultCategories()
        $sql = <<<'SQL'
            SELECT
                c.id,
                c.slug,
                c.type,
                c.sort_order,
                c.default_consent,
                ct.name,
                ct.description,
                ct.language_id AS lang_id
            FROM oci_cookie_categories AS c
            INNER JOIN oci_cookie_category_translations AS ct ON c.id = ct.category_id
            WHERE c.is_active = 1
              AND ct.language_id = :langId
            ORDER BY c.sort_order ASC, c.id ASC
        SQL;

        $rows = $this->db->fetchAllAssociative($sql, ['langId' => $languageId]);

        // Fallback to English (language_id = 1) if no translations found for requested language
        if ($rows === [] && $languageId !== 1) {
            $rows = $this->db->fetchAllAssociative($sql, ['langId' => 1]);
        }

        return $rows;
    }

    public function copyCategoryToSite(
        int $siteId,
        int $categoryId,
        int $languageId,
        string $name,
        string $description,
    ): void {
        // Check if site already has this category
        $existing = $this->db->fetchOne(
            'SELECT id FROM oci_site_cookie_categories WHERE site_id = :siteId AND category_id = :catId',
            ['siteId' => $siteId, 'catId' => $categoryId],
        );

        if ($existing !== false && $existing !== null) {
            $siteCategoryId = (int) $existing;

            // Check if translation exists for this language
            $translationExists = $this->db->fetchOne(
                'SELECT COUNT(*) FROM oci_site_cookie_category_translations WHERE site_cookie_category_id = :sccId AND language_id = :langId',
                ['sccId' => $siteCategoryId, 'langId' => $languageId],
            );

            if ($translationExists !== false && (int) $translationExists > 0) {
                return;
            }

            // Add the missing translation
            $this->db->insert('oci_site_cookie_category_translations', [
                'site_cookie_category_id' => $siteCategoryId,
                'language_id' => $languageId,
                'name' => $name,
                'description' => $description,
            ]);

            return;
        }

        // Create the site category
        $this->db->insert('oci_site_cookie_categories', [
            'site_id' => $siteId,
            'category_id' => $categoryId,
            'sort_order' => 0,
        ]);

        $siteCategoryId = (int) $this->db->lastInsertId();

        // Create the translation
        $this->db->insert('oci_site_cookie_category_translations', [
            'site_cookie_category_id' => $siteCategoryId,
            'language_id' => $languageId,
            'name' => $name,
            'description' => $description,
        ]);
    }

    public function getSiteCategories(int $siteId, int $languageId = 1): array
    {
        $sql = <<<'SQL'
            SELECT
                scc.id,
                scc.site_id,
                scc.category_id,
                scc.sort_order,
                scc.default_consent,
                scc.custom_slug,
                cc.slug,
                cc.type,
                scct.name,
                scct.description
            FROM oci_site_cookie_categories scc
            INNER JOIN oci_cookie_categories cc ON scc.category_id = cc.id
            LEFT JOIN oci_site_cookie_category_translations scct
                ON scc.id = scct.site_cookie_category_id AND scct.language_id = :langId
            WHERE scc.site_id = :siteId
            ORDER BY scc.sort_order ASC, cc.sort_order ASC
        SQL;

        $rows = $this->db->fetchAllAssociative($sql, [
            'siteId' => $siteId,
            'langId' => $languageId,
        ]);

        // Fallback: if no translations found for this language, try English
        if ($languageId !== 1) {
            foreach ($rows as &$row) {
                if ($row['name'] === null) {
                    $fallback = $this->db->fetchAssociative(
                        'SELECT name, description FROM oci_site_cookie_category_translations WHERE site_cookie_category_id = :id AND language_id = 1',
                        ['id' => $row['id']],
                    );
                    if ($fallback) {
                        $row['name'] = $fallback['name'];
                        $row['description'] = $fallback['description'];
                    }
                }
            }
            unset($row);
        }

        return $rows;
    }

    public function findSiteCategory(int $id): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT scc.*, cc.slug, cc.type, scct.name, scct.description
             FROM oci_site_cookie_categories scc
             INNER JOIN oci_cookie_categories cc ON scc.category_id = cc.id
             LEFT JOIN oci_site_cookie_category_translations scct
                 ON scc.id = scct.site_cookie_category_id AND scct.language_id = 1
             WHERE scc.id = :id',
            ['id' => $id],
        );

        return $row ?: null;
    }

    public function updateSiteCategory(int $id, array $data): void
    {
        $fields = [];
        foreach (['sort_order', 'default_consent', 'custom_slug'] as $col) {
            if (array_key_exists($col, $data)) {
                $fields[$col] = $data[$col];
            }
        }

        if ($fields !== []) {
            $this->db->update('oci_site_cookie_categories', $fields, ['id' => $id]);
        }
    }

    public function upsertSiteCategoryTranslation(int $siteCategoryId, int $languageId, string $name, string $description): void
    {
        $existing = $this->db->fetchOne(
            'SELECT id FROM oci_site_cookie_category_translations WHERE site_cookie_category_id = :sccId AND language_id = :langId',
            ['sccId' => $siteCategoryId, 'langId' => $languageId],
        );

        if ($existing !== false && $existing !== null) {
            $this->db->update('oci_site_cookie_category_translations', [
                'name' => $name,
                'description' => $description,
            ], ['id' => (int) $existing]);
        } else {
            $this->db->insert('oci_site_cookie_category_translations', [
                'site_cookie_category_id' => $siteCategoryId,
                'language_id' => $languageId,
                'name' => $name,
                'description' => $description,
            ]);
        }
    }

    public function deleteSiteCategory(int $id): void
    {
        // Unlink cookies from this category first (set to null)
        $siteCategory = $this->findSiteCategory($id);
        if ($siteCategory !== null) {
            $this->db->executeStatement(
                'UPDATE oci_site_cookies SET category_id = NULL WHERE site_id = :siteId AND category_id = :catId',
                ['siteId' => $siteCategory['site_id'], 'catId' => $siteCategory['category_id']],
            );
        }

        $this->db->delete('oci_site_cookie_categories', ['id' => $id]);
    }

    public function countCookiesPerCategory(int $siteId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT sc.category_id, COUNT(*) AS cnt
             FROM oci_site_cookies sc
             WHERE sc.site_id = :siteId AND sc.category_id IS NOT NULL
             GROUP BY sc.category_id',
            ['siteId' => $siteId],
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['category_id']] = (int) $row['cnt'];
        }

        return $result;
    }

    public function getAllGlobalCategories(int $languageId = 1): array
    {
        $sql = <<<'SQL'
            SELECT c.id, c.slug, c.type, c.sort_order, c.default_consent, ct.name, ct.description
            FROM oci_cookie_categories c
            LEFT JOIN oci_cookie_category_translations ct ON c.id = ct.category_id AND ct.language_id = :langId
            WHERE c.is_active = 1
            ORDER BY c.sort_order ASC, c.id ASC
        SQL;

        return $this->db->fetchAllAssociative($sql, ['langId' => $languageId]);
    }
}
