<?php

declare(strict_types=1);

namespace OCI\Agency\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class SettingTemplateRepository implements SettingTemplateRepositoryInterface
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function listForAgency(int $agencyId): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT * FROM oci_setting_templates
             WHERE agency_id = :agencyId OR agency_id IS NULL
             ORDER BY agency_id IS NULL DESC, name ASC',
            ['agencyId' => $agencyId],
            ['agencyId' => ParameterType::INTEGER],
        );
    }

    public function findForAgency(int $templateId, int $agencyId): ?array
    {
        // The ownership condition is the authorization. A template belonging to
        // another agency matches zero rows, which the caller sees as "no such
        // template" — deliberately indistinguishable from a bad id, so an
        // agency cannot probe for the existence of a competitor's templates.
        $row = $this->db->fetchAssociative(
            'SELECT * FROM oci_setting_templates
             WHERE id = :id AND (agency_id = :agencyId OR agency_id IS NULL)
             LIMIT 1',
            ['id' => $templateId, 'agencyId' => $agencyId],
            ['id' => ParameterType::INTEGER, 'agencyId' => ParameterType::INTEGER],
        );

        return $row !== false ? $row : null;
    }

    public function create(int $agencyId, string $name, ?string $description, array $payload, int $createdBy): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->db->insert('oci_setting_templates', [
            'agency_id' => $agencyId,
            'name' => $name,
            'description' => $description,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'created_by' => $createdBy,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $templateId, int $agencyId, string $name, ?string $description, array $payload): bool
    {
        // agency_id in the WHERE, and NOT NULL: a system template is never
        // editable by an agency, however it got hold of the id.
        return $this->db->executeStatement(
            'UPDATE oci_setting_templates
             SET name = :name, description = :description, payload = :payload
             WHERE id = :id AND agency_id = :agencyId',
            [
                'name' => $name,
                'description' => $description,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'id' => $templateId,
                'agencyId' => $agencyId,
            ],
        ) > 0;
    }

    public function delete(int $templateId, int $agencyId): bool
    {
        return $this->db->executeStatement(
            'DELETE FROM oci_setting_templates WHERE id = :id AND agency_id = :agencyId',
            ['id' => $templateId, 'agencyId' => $agencyId],
            ['id' => ParameterType::INTEGER, 'agencyId' => ParameterType::INTEGER],
        ) > 0;
    }

    public function recordApplication(
        int $siteId,
        int $templateId,
        string $fingerprint,
        array $previousSettings,
        int $appliedBy,
    ): void {
        $this->db->executeStatement(
            'INSERT INTO oci_site_template_links
                (site_id, template_id, applied_fingerprint, previous_settings, applied_by, applied_at)
             VALUES (:siteId, :templateId, :fingerprint, :previous, :appliedBy, :now)
             ON DUPLICATE KEY UPDATE
                template_id = VALUES(template_id),
                applied_fingerprint = VALUES(applied_fingerprint),
                previous_settings = VALUES(previous_settings),
                applied_by = VALUES(applied_by),
                applied_at = VALUES(applied_at)',
            [
                'siteId' => $siteId,
                'templateId' => $templateId,
                'fingerprint' => $fingerprint,
                'previous' => json_encode($previousSettings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'appliedBy' => $appliedBy,
                'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );
    }

    public function findLink(int $siteId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM oci_site_template_links WHERE site_id = :siteId LIMIT 1',
            ['siteId' => $siteId],
            ['siteId' => ParameterType::INTEGER],
        );

        return $row !== false ? $row : null;
    }

    public function findLinks(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        // The payload rides along because drift is measured on the keys the
        // template governs, and only the payload knows which those are.
        $rows = $this->db->fetchAllAssociative(
            'SELECT l.*, t.name AS template_name, t.payload AS template_payload
             FROM oci_site_template_links AS l
             LEFT JOIN oci_setting_templates AS t ON t.id = l.template_id
             WHERE l.site_id IN (:siteIds)',
            ['siteIds' => array_map('intval', $siteIds)],
            ['siteIds' => ArrayParameterType::INTEGER],
        );

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['site_id']] = $row;
        }

        return $byId;
    }

    public function deleteLink(int $siteId): void
    {
        $this->db->executeStatement(
            'DELETE FROM oci_site_template_links WHERE site_id = :siteId',
            ['siteId' => $siteId],
            ['siteId' => ParameterType::INTEGER],
        );
    }

    public function siteCountsForAgency(int $agencyId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT l.template_id, COUNT(*) AS n
             FROM oci_site_template_links AS l
             INNER JOIN oci_setting_templates AS t ON t.id = l.template_id
             INNER JOIN oci_sites AS s ON s.id = l.site_id AND s.deleted_at IS NULL
             WHERE t.agency_id = :agencyId OR t.agency_id IS NULL
             GROUP BY l.template_id',
            ['agencyId' => $agencyId],
            ['agencyId' => ParameterType::INTEGER],
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['template_id']] = (int) $row['n'];
        }

        return $counts;
    }
}
