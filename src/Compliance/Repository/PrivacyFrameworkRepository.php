<?php

declare(strict_types=1);

namespace OCI\Compliance\Repository;

use Doctrine\DBAL\Connection;

final class PrivacyFrameworkRepository implements PrivacyFrameworkRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function getFrameworksForSite(int $siteId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT framework_id FROM oci_site_privacy_frameworks WHERE site_id = :siteId AND enabled = 1 ORDER BY framework_id',
            ['siteId' => $siteId],
        );

        return array_column($rows, 'framework_id');
    }

    public function setFrameworksForSite(int $siteId, array $frameworkIds): void
    {
        $this->db->beginTransaction();

        try {
            $this->db->delete('oci_site_privacy_frameworks', ['site_id' => $siteId]);

            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            foreach ($frameworkIds as $frameworkId) {
                $this->db->insert('oci_site_privacy_frameworks', [
                    'site_id' => $siteId,
                    'framework_id' => $frameworkId,
                    'enabled' => 1,
                    'created_at' => $now,
                ]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function countFrameworks(int $siteId): int
    {
        $count = $this->db->fetchOne(
            'SELECT COUNT(*) FROM oci_site_privacy_frameworks WHERE site_id = :siteId AND enabled = 1',
            ['siteId' => $siteId],
        );

        return (int) $count;
    }
}
