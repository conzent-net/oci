<?php

declare(strict_types=1);

namespace OCI\Banner\Repository;

use Doctrine\DBAL\Connection;

final class BannerRepository implements BannerRepositoryInterface
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    public function getSiteBannerSettings(int $siteId, string $consentType = ''): array
    {
        $qb = $this->db->createQueryBuilder()
            ->select('*')
            ->from('oci_site_banners')
            ->where('site_id = :siteId')
            ->setParameter('siteId', $siteId);

        if ($consentType !== '') {
            // The OCI schema stores banner type in the consent_template or general_setting JSON
            // For now, return all banners — the service layer filters by type
        }

        return $qb->fetchAllAssociative();
    }

    public function getUserBannerContent(int $siteId, int $languageId, string $cookieLaws = ''): array
    {
        $sql = <<<'SQL'
            SELECT sbft.*, bf.field_key AS field_name, sbft.value AS u_field_value
            FROM oci_site_banner_field_translations AS sbft
            INNER JOIN oci_banner_fields AS bf ON bf.id = sbft.field_id
            INNER JOIN oci_site_banners AS sb ON sb.id = sbft.site_banner_id
            WHERE sb.site_id = :siteId
              AND sbft.language_id = :langId
        SQL;

        $params = [
            'siteId' => $siteId,
            'langId' => $languageId,
        ];

        /** @var array<int, array<string, mixed>> */
        return $this->db->fetchAllAssociative($sql, $params);
    }

    public function getWebsiteKeyBySiteId(int $siteId): string
    {
        $key = $this->db->fetchOne(
            'SELECT website_key FROM oci_sites WHERE id = :id',
            ['id' => $siteId],
        );

        return $key !== false ? (string) $key : '';
    }

    public function updateBannerSetting(int $bannerId, array $data): void
    {
        $data['updated_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->db->update('oci_site_banners', $data, ['id' => $bannerId]);
    }

    public function updateBannerContent(int $contentId, array $data): void
    {
        $this->db->update('oci_site_banner_field_translations', $data, ['id' => $contentId]);
    }

    public function getDefaultBannerTemplate(): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM oci_banner_templates WHERE is_default = 1 AND is_active = 1 LIMIT 1',
        );

        if ($row !== false) {
            return $row;
        }

        // Fallback: get any active template
        $fallback = $this->db->fetchAssociative(
            'SELECT * FROM oci_banner_templates WHERE is_active = 1 ORDER BY id ASC LIMIT 1',
        );

        return $fallback !== false ? $fallback : null;
    }

    public function getAllBannerTemplates(): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT * FROM oci_banner_templates WHERE is_active = 1 ORDER BY id ASC',
        );
    }

    public function createSiteBanner(array $data): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $data['created_at'] = $data['created_at'] ?? $now;
        $data['updated_at'] = $data['updated_at'] ?? $now;

        $this->db->insert('oci_site_banners', $data);

        return (int) $this->db->lastInsertId();
    }

    public function copyDefaultBannerTranslations(int $siteBannerId, int $templateId, int $languageId): void
    {
        // Get all fields for this template and copy their default translations
        $sql = <<<'SQL'
            SELECT bf.id AS field_id, bft.label AS default_value
            FROM oci_banner_fields AS bf
            INNER JOIN oci_banner_field_categories AS bfc ON bfc.id = bf.field_category_id
            LEFT JOIN oci_banner_field_translations AS bft ON bft.field_id = bf.id AND bft.language_id = :langId
            WHERE bfc.template_id = :templateId
        SQL;

        $rows = $this->db->fetchAllAssociative($sql, [
            'templateId' => $templateId,
            'langId' => $languageId,
        ]);

        foreach ($rows as $row) {
            $this->db->insert('oci_site_banner_field_translations', [
                'site_banner_id' => $siteBannerId,
                'field_id' => (int) $row['field_id'],
                'language_id' => $languageId,
                'value' => $row['default_value'] ?? '',
            ]);
        }
    }

    public function getBannerFieldsGrouped(int $templateId): array
    {
        $sql = <<<'SQL'
            SELECT bfc.id AS cat_id, bfc.category_key, bfc.category_name,
                   bf.id AS field_id, bf.field_key, bf.field_type, bf.default_value, bf.sort_order AS field_order
            FROM oci_banner_field_categories AS bfc
            INNER JOIN oci_banner_fields AS bf ON bf.field_category_id = bfc.id
            WHERE bfc.template_id = :templateId
            ORDER BY bfc.sort_order ASC, bf.sort_order ASC
        SQL;

        $rows = $this->db->fetchAllAssociative($sql, ['templateId' => $templateId]);

        $grouped = [];
        foreach ($rows as $row) {
            $catId = (int) $row['cat_id'];
            if (!isset($grouped[$catId])) {
                $grouped[$catId] = [
                    'category' => $row['category_name'],
                    'category_key' => $row['category_key'],
                    'fields' => [],
                ];
            }
            $grouped[$catId]['fields'][] = [
                'id' => (int) $row['field_id'],
                'field_key' => $row['field_key'],
                'field_type' => $row['field_type'],
                'default_value' => $row['default_value'] ?? '',
            ];
        }

        return array_values($grouped);
    }

    public function getSiteBannerFieldValues(int $siteBannerId, int $languageId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT field_id, value FROM oci_site_banner_field_translations WHERE site_banner_id = :sbId AND language_id = :langId',
            ['sbId' => $siteBannerId, 'langId' => $languageId],
        );

        $values = [];
        foreach ($rows as $row) {
            $values[(int) $row['field_id']] = (string) ($row['value'] ?? '');
        }

        return $values;
    }

    public function getDefaultFieldValues(int $templateId, int $languageId): array
    {
        $sql = <<<'SQL'
            SELECT bf.id AS field_id, COALESCE(bft.label, bf.default_value, '') AS default_value
            FROM oci_banner_fields AS bf
            INNER JOIN oci_banner_field_categories AS bfc ON bfc.id = bf.field_category_id
            LEFT JOIN oci_banner_field_translations AS bft ON bft.field_id = bf.id AND bft.language_id = :langId
            WHERE bfc.template_id = :templateId
        SQL;

        $rows = $this->db->fetchAllAssociative($sql, [
            'templateId' => $templateId,
            'langId' => $languageId,
        ]);

        $values = [];
        foreach ($rows as $row) {
            $values[(int) $row['field_id']] = (string) $row['default_value'];
        }

        return $values;
    }

    public function upsertFieldTranslation(int $siteBannerId, int $fieldId, int $languageId, string $value): void
    {
        $existing = $this->db->fetchOne(
            'SELECT id FROM oci_site_banner_field_translations WHERE site_banner_id = :sbId AND field_id = :fId AND language_id = :lId',
            ['sbId' => $siteBannerId, 'fId' => $fieldId, 'lId' => $languageId],
        );

        if ($existing !== false) {
            $this->db->update('oci_site_banner_field_translations', ['value' => $value], ['id' => (int) $existing]);
        } else {
            $this->db->insert('oci_site_banner_field_translations', [
                'site_banner_id' => $siteBannerId,
                'field_id' => $fieldId,
                'language_id' => $languageId,
                'value' => $value,
            ]);
        }
    }
}
