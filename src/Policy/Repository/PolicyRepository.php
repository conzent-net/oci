<?php

declare(strict_types=1);

namespace OCI\Policy\Repository;

use Doctrine\DBAL\Connection;

final class PolicyRepository implements PolicyRepositoryInterface
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    // ── Cookie policies (per-site) ──────────────────────────

    public function getCookiePolicy(int $siteId, int $languageId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM oci_cookie_policies WHERE site_id = :siteId AND language_id = :langId',
            ['siteId' => $siteId, 'langId' => $languageId],
        );

        return $row !== false ? $row : null;
    }

    public function saveCookiePolicy(int $siteId, int $languageId, array $data): int
    {
        $existing = $this->getCookiePolicy($siteId, $languageId);

        if ($existing !== null) {
            $this->db->update('oci_cookie_policies', $data, ['id' => $existing['id']]);
            return (int) $existing['id'];
        }

        $data['site_id'] = $siteId;
        $data['language_id'] = $languageId;
        $this->db->insert('oci_cookie_policies', $data);

        return (int) $this->db->lastInsertId();
    }

    // ── Privacy policies (per-site) ─────────────────────────

    public function getPrivacyPolicy(int $siteId, int $languageId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM oci_privacy_policies WHERE site_id = :siteId AND language_id = :langId',
            ['siteId' => $siteId, 'langId' => $languageId],
        );

        return $row !== false ? $row : null;
    }

    public function savePrivacyPolicy(int $siteId, int $languageId, array $data): int
    {
        $existing = $this->getPrivacyPolicy($siteId, $languageId);

        if ($existing !== null) {
            $this->db->update('oci_privacy_policies', $data, ['id' => $existing['id']]);
            return (int) $existing['id'];
        }

        $data['site_id'] = $siteId;
        $data['language_id'] = $languageId;
        $this->db->insert('oci_privacy_policies', $data);

        return (int) $this->db->lastInsertId();
    }

    // ── Cookie policy templates ─────────────────────────────

    public function getCookiePolicyTemplates(int $userId): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT * FROM oci_cookie_policy_templates WHERE user_id = :userId OR is_default = 1 ORDER BY is_default DESC, template_name ASC',
            ['userId' => $userId],
        );
    }

    public function getCookiePolicyTemplate(int $id): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM oci_cookie_policy_templates WHERE id = :id',
            ['id' => $id],
        );

        return $row !== false ? $row : null;
    }

    public function saveCookiePolicyTemplate(int $userId, array $data): int
    {
        $data['user_id'] = $userId;
        $this->db->insert('oci_cookie_policy_templates', $data);

        return (int) $this->db->lastInsertId();
    }

    public function updateCookiePolicyTemplate(int $id, array $data): void
    {
        $this->db->update('oci_cookie_policy_templates', $data, ['id' => $id]);
    }

    public function deleteCookiePolicyTemplate(int $id): void
    {
        $this->db->delete('oci_cookie_policy_templates', ['id' => $id]);
    }

    // ── Privacy policy templates ────────────────────────────

    public function getPrivacyPolicyTemplates(int $userId): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT * FROM oci_privacy_policy_templates WHERE user_id = :userId OR is_default = 1 ORDER BY is_default DESC, template_name ASC',
            ['userId' => $userId],
        );
    }

    public function getPrivacyPolicyTemplate(int $id): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM oci_privacy_policy_templates WHERE id = :id',
            ['id' => $id],
        );

        return $row !== false ? $row : null;
    }

    public function savePrivacyPolicyTemplate(int $userId, array $data): int
    {
        $data['user_id'] = $userId;
        $this->db->insert('oci_privacy_policy_templates', $data);

        return (int) $this->db->lastInsertId();
    }

    public function updatePrivacyPolicyTemplate(int $id, array $data): void
    {
        $this->db->update('oci_privacy_policy_templates', $data, ['id' => $id]);
    }

    public function deletePrivacyPolicyTemplate(int $id): void
    {
        $this->db->delete('oci_privacy_policy_templates', ['id' => $id]);
    }

    // ── System defaults ─────────────────────────────────────

    public function getDefaultCookiePolicyTemplate(int $languageId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM oci_cookie_policy_templates WHERE is_default = 1 AND language_id = :langId LIMIT 1',
            ['langId' => $languageId],
        );

        return $row !== false ? $row : null;
    }

    public function getDefaultPrivacyPolicyTemplate(int $languageId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM oci_privacy_policy_templates WHERE is_default = 1 AND language_id = :langId LIMIT 1',
            ['langId' => $languageId],
        );

        return $row !== false ? $row : null;
    }
}
