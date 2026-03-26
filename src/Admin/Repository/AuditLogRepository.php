<?php

declare(strict_types=1);

namespace OCI\Admin\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class AuditLogRepository implements AuditLogRepositoryInterface
{
    private const TABLE = 'oci_audit_log';

    public function __construct(private readonly Connection $db)
    {
    }

    public function insert(array $data): void
    {
        $this->db->insert(self::TABLE, $data);
    }

    public function findAll(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $qb = $this->db->createQueryBuilder()
            ->select('a.*', 'u.email AS user_email')
            ->from(self::TABLE, 'a')
            ->leftJoin('a', 'oci_users', 'u', 'a.user_id = u.id')
            ->orderBy('a.created_at', 'DESC');

        $this->applyFilters($qb, $filters);

        $offset = ($page - 1) * $perPage;
        $qb->setFirstResult($offset)
            ->setMaxResults($perPage);

        return $qb->executeQuery()->fetchAllAssociative();
    }

    public function countAll(array $filters = []): int
    {
        $qb = $this->db->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(self::TABLE, 'a')
            ->leftJoin('a', 'oci_users', 'u', 'a.user_id = u.id');

        $this->applyFilters($qb, $filters);

        return (int) $qb->executeQuery()->fetchOne();
    }

    public function getDistinctEntityTypes(): array
    {
        return $this->db->fetchFirstColumn(
            'SELECT DISTINCT entity_type FROM ' . self::TABLE . ' ORDER BY entity_type',
        );
    }

    public function getDistinctActions(): array
    {
        return $this->db->fetchFirstColumn(
            'SELECT DISTINCT action FROM ' . self::TABLE . ' ORDER BY action',
        );
    }

    private function applyFilters(\Doctrine\DBAL\Query\QueryBuilder $qb, array $filters): void
    {
        if (!empty($filters['entity_type'])) {
            $qb->andWhere('a.entity_type = :entity_type')
                ->setParameter('entity_type', $filters['entity_type']);
        }

        if (!empty($filters['action'])) {
            $qb->andWhere('a.action = :action')
                ->setParameter('action', $filters['action']);
        }

        if (!empty($filters['user_id'])) {
            $qb->andWhere('a.user_id = :user_id')
                ->setParameter('user_id', (int) $filters['user_id'], ParameterType::INTEGER);
        }

        if (!empty($filters['date_from'])) {
            $qb->andWhere('a.created_at >= :date_from')
                ->setParameter('date_from', $filters['date_from'] . ' 00:00:00');
        }

        if (!empty($filters['date_to'])) {
            $qb->andWhere('a.created_at <= :date_to')
                ->setParameter('date_to', $filters['date_to'] . ' 23:59:59');
        }

        if (!empty($filters['search'])) {
            $qb->andWhere('(u.email LIKE :search OR a.entity_type LIKE :search OR a.action LIKE :search)')
                ->setParameter('search', '%' . $filters['search'] . '%');
        }
    }
}
