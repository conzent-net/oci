<?php

declare(strict_types=1);

namespace OCI\Cookie\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class CookieRegisterRepository implements CookieRegisterRepositoryInterface
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    public function saveSnapshot(int $siteId, ?int $scanId, array $entries): int
    {
        $this->db->insert('oci_cookie_register_snapshots', [
            'site_id' => $siteId,
            'scan_id' => $scanId,
            'entries' => json_encode($entries, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'entry_count' => \count($entries),
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function getLatestSnapshot(int $siteId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT id, entries FROM oci_cookie_register_snapshots
             WHERE site_id = :siteId ORDER BY id DESC LIMIT 1',
            ['siteId' => $siteId],
            ['siteId' => ParameterType::INTEGER],
        );

        if ($row === false) {
            return null;
        }

        $entries = json_decode((string) $row['entries'], true);

        return [
            'id' => (int) $row['id'],
            'entries' => \is_array($entries) ? $entries : [],
        ];
    }

    public function insertChanges(int $siteId, int $snapshotId, ?int $scanId, array $changes): array
    {
        $ids = [];
        foreach ($changes as $change) {
            $this->db->insert('oci_cookie_register_changes', [
                'site_id' => $siteId,
                'snapshot_id' => $snapshotId,
                'scan_id' => $scanId,
                'change_type' => $change['change_type'],
                'entry_type' => $change['entry_type'],
                'name' => mb_substr((string) $change['name'], 0, 500),
                'domain' => mb_substr((string) ($change['domain'] ?? ''), 0, 300),
                'old_value' => isset($change['old_value'])
                    ? json_encode($change['old_value'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
                    : null,
                'new_value' => isset($change['new_value'])
                    ? json_encode($change['new_value'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
                    : null,
            ]);
            $ids[] = (int) $this->db->lastInsertId();
        }

        return $ids;
    }

    public function getChanges(int $siteId, int $page, int $perPage, ?string $entryType = null): array
    {
        $qb = $this->db->createQueryBuilder()
            ->select('*')
            ->from('oci_cookie_register_changes')
            ->where('site_id = :siteId')
            ->setParameter('siteId', $siteId, ParameterType::INTEGER)
            ->orderBy('id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        $count = $this->db->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('oci_cookie_register_changes')
            ->where('site_id = :siteId')
            ->setParameter('siteId', $siteId, ParameterType::INTEGER);

        if ($entryType !== null && $entryType !== '') {
            $qb->andWhere('entry_type = :entryType')->setParameter('entryType', $entryType);
            $count->andWhere('entry_type = :entryType')->setParameter('entryType', $entryType);
        }

        return [
            'items' => $qb->executeQuery()->fetchAllAssociative(),
            'total' => (int) $count->executeQuery()->fetchOne(),
        ];
    }

    public function getChangesSince(int $siteId, string $sinceDate, int $limit = 200): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT * FROM oci_cookie_register_changes
             WHERE site_id = :siteId AND created_at >= :since
             ORDER BY id DESC
             LIMIT :lim',
            ['siteId' => $siteId, 'since' => $sinceDate, 'lim' => $limit],
            ['siteId' => ParameterType::INTEGER, 'lim' => ParameterType::INTEGER],
        );
    }

    public function markNotified(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $this->db->executeStatement(
            'UPDATE oci_cookie_register_changes
             SET notified_at = NOW()
             WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')',
        );
    }
}
