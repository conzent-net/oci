<?php

declare(strict_types=1);

namespace OCI\Cookie\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class CookieReclassRequestRepository implements CookieReclassRequestRepositoryInterface
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    public function create(array $data): int
    {
        $this->db->insert('oci_cookie_reclass_requests', [
            'site_id' => $data['site_id'],
            'user_id' => $data['user_id'],
            'cookie_name' => $data['cookie_name'],
            'cookie_domain' => $data['cookie_domain'] ?? null,
            'current_category_slug' => $data['current_category_slug'] ?? null,
            'requested_category_id' => $data['requested_category_id'],
            'reason' => $data['reason'] ?? null,
            'status' => 'pending',
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT r.*, s.domain AS site_domain,
                    cc.slug AS requested_slug, cct.name AS requested_name
             FROM oci_cookie_reclass_requests r
             LEFT JOIN oci_sites s ON r.site_id = s.id
             LEFT JOIN oci_cookie_categories cc ON r.requested_category_id = cc.id
             LEFT JOIN oci_cookie_category_translations cct ON cc.id = cct.category_id AND cct.language_id = 1
             WHERE r.id = :id',
            ['id' => $id],
        );

        return $row ?: null;
    }

    public function findByStatus(?string $status, int $page = 1, int $perPage = 50): array
    {
        $qb = $this->db->createQueryBuilder()
            ->select(
                'r.*',
                's.domain AS site_domain',
                'u.email AS user_email',
                'cc.slug AS requested_slug',
                'cct.name AS requested_name',
            )
            ->from('oci_cookie_reclass_requests', 'r')
            ->leftJoin('r', 'oci_sites', 's', 'r.site_id = s.id')
            ->leftJoin('r', 'oci_users', 'u', 'r.user_id = u.id')
            ->leftJoin('r', 'oci_cookie_categories', 'cc', 'r.requested_category_id = cc.id')
            ->leftJoin('cc', 'oci_cookie_category_translations', 'cct', 'cc.id = cct.category_id AND cct.language_id = 1');

        if ($status !== null && $status !== '') {
            $qb->where('r.status = :status')->setParameter('status', $status);
        }

        $countQb = clone $qb;
        $countQb->select('COUNT(*)');
        $total = (int) $countQb->executeQuery()->fetchOne();

        $offset = ($page - 1) * $perPage;
        $qb->orderBy('r.created_at', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage);

        return [
            'items' => $qb->executeQuery()->fetchAllAssociative(),
            'total' => $total,
        ];
    }

    public function existsPending(int $siteId, string $cookieName): bool
    {
        $id = $this->db->fetchOne(
            "SELECT id FROM oci_cookie_reclass_requests
             WHERE site_id = :siteId AND cookie_name = :name AND status = 'pending'
             LIMIT 1",
            ['siteId' => $siteId, 'name' => $cookieName],
        );

        return $id !== false && $id !== null;
    }

    public function updateStatus(int $id, string $status, int $reviewedBy, ?string $note = null): void
    {
        $this->db->update(
            'oci_cookie_reclass_requests',
            [
                'status' => $status,
                'reviewed_by' => $reviewedBy,
                'review_note' => $note,
                'reviewed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
            ['id' => $id],
        );
    }

    public function countPending(): int
    {
        return (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM oci_cookie_reclass_requests WHERE status = 'pending'",
        );
    }

    public function getPendingNamesForSite(int $siteId): array
    {
        $rows = $this->db->fetchFirstColumn(
            "SELECT cookie_name FROM oci_cookie_reclass_requests
             WHERE site_id = :siteId AND status = 'pending'",
            ['siteId' => $siteId],
            ['siteId' => ParameterType::INTEGER],
        );

        return array_map('strval', $rows);
    }
}
