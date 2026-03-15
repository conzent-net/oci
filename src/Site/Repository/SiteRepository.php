<?php

declare(strict_types=1);

namespace OCI\Site\Repository;

use Doctrine\DBAL\Connection;

final class SiteRepository implements SiteRepositoryInterface
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    public function findById(int $id): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM oci_sites WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id],
        );

        return $row !== false ? $row : null;
    }

    public function findAllByUser(int $userId, ?string $status = null, bool $includeDeleted = false): array
    {
        $qb = $this->db->createQueryBuilder()
            ->select('*')
            ->from('oci_sites')
            ->where('user_id = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('domain', 'ASC');

        if (!$includeDeleted) {
            $qb->andWhere('deleted_at IS NULL');
        }

        if ($status !== null) {
            $qb->andWhere('status = :status')
                ->setParameter('status', $status);
        }

        return $qb->fetchAllAssociative();
    }

    public function findMainWebsite(int $userId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM oci_sites WHERE user_id = :userId AND status = :status AND deleted_at IS NULL ORDER BY id ASC LIMIT 1',
            ['userId' => $userId, 'status' => 'active'],
        );

        return $row !== false ? $row : null;
    }

    public function updateStatus(int $siteId, string $status): void
    {
        $this->db->update('oci_sites', [
            'status' => $status,
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], ['id' => $siteId]);
    }

    public function updateCompliantStatus(int $siteId, string $status): void
    {
        $this->db->update('oci_sites', [
            'compliant_status' => $status,
            'site_updated' => 0,
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], ['id' => $siteId]);
    }

    public function getCompliantStatus(int $siteId): ?string
    {
        $result = $this->db->fetchOne(
            'SELECT compliant_status FROM oci_sites WHERE id = :id',
            ['id' => $siteId],
        );

        return $result !== false ? (string) $result : null;
    }

    public function getWizard(int $siteId, int $userId): ?array
    {
        // Site wizards don't have an oci_ table yet — query legacy table during migration
        // For now we check if the legacy table 'site_wizards' exists, otherwise return null
        try {
            $row = $this->db->fetchAssociative(
                'SELECT * FROM site_wizards WHERE site_id = :siteId AND user_id = :userId',
                ['siteId' => $siteId, 'userId' => $userId],
            );

            return $row !== false ? $row : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function updateSiteSettings(int $siteId, array $data): void
    {
        $data['updated_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->db->update('oci_sites', $data, ['id' => $siteId]);
    }

    public function create(array $data): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $data['created_at'] = $data['created_at'] ?? $now;
        $data['updated_at'] = $data['updated_at'] ?? $now;

        $this->db->insert('oci_sites', $data);

        return (int) $this->db->lastInsertId();
    }

    public function domainExists(string $domain): bool
    {
        $result = $this->db->fetchOne(
            'SELECT COUNT(*) FROM oci_sites WHERE domain = :domain AND deleted_at IS NULL',
            ['domain' => $domain],
        );

        return $result !== false && (int) $result > 0;
    }

    public function countByUser(int $userId): int
    {
        $result = $this->db->fetchOne(
            'SELECT COUNT(*) FROM oci_sites WHERE user_id = :userId AND deleted_at IS NULL',
            ['userId' => $userId],
        );

        return $result !== false ? (int) $result : 0;
    }

    public function generateWebsiteKey(): string
    {
        // Mirror legacy: 24-char hex string from random data
        return substr(md5((string) random_int(0, 0xffff) . (string) (random_int(0, 0x0fff) | 0x4000) . (string) (random_int(0, 0x3fff) | 0x8000)), 0, 24);
    }

    public function saveWizard(array $data): void
    {
        $this->db->insert('oci_site_wizards', $data);
    }

    public function updateWizard(int $siteId, array $data): void
    {
        $this->db->update('oci_site_wizards', $data, ['site_id' => $siteId]);
    }

    public function softDelete(int $siteId): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->db->update('oci_sites', [
            'status' => 'deleted',
            'deleted_at' => $now,
            'updated_at' => $now,
        ], ['id' => $siteId]);
    }

    public function restore(int $siteId): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->db->update('oci_sites', [
            'status' => 'active',
            'deleted_at' => null,
            'updated_at' => $now,
        ], ['id' => $siteId]);
    }

    public function destroy(int $siteId): void
    {
        $this->db->beginTransaction();
        try {
            // Remove related data first
            $this->db->delete('oci_site_languages', ['site_id' => $siteId]);
            $this->db->delete('oci_site_wizards', ['site_id' => $siteId]);

            // Remove banner-related data
            $bannerIds = $this->db->fetchFirstColumn(
                'SELECT id FROM oci_site_banners WHERE site_id = :siteId',
                ['siteId' => $siteId],
            );
            if ($bannerIds !== []) {
                $this->db->executeStatement(
                    'DELETE FROM oci_site_banner_field_translations WHERE site_banner_id IN (' . implode(',', array_map('intval', $bannerIds)) . ')',
                );
                $this->db->delete('oci_site_banners', ['site_id' => $siteId]);
            }

            // Remove the site itself
            $this->db->delete('oci_sites', ['id' => $siteId]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function belongsToUser(int $siteId, int $userId): bool
    {
        $result = $this->db->fetchOne(
            'SELECT COUNT(*) FROM oci_sites WHERE id = :id AND user_id = :userId',
            ['id' => $siteId, 'userId' => $userId],
        );

        return $result !== false && (int) $result > 0;
    }
}
