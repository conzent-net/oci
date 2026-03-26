<?php

declare(strict_types=1);

namespace OCI\Identity\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class UserRepository implements UserRepositoryInterface
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    public function findById(int $id): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM oci_users WHERE id = :id',
            ['id' => $id],
        );

        return $row !== false ? $row : null;
    }

    public function findByEmail(string $email): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM oci_users WHERE email = :email AND deleted_at IS NULL',
            ['email' => $email],
        );

        return $row !== false ? $row : null;
    }

    public function findAll(?string $role = null, ?string $search = null, bool $includeDeleted = false, int $limit = 50, int $offset = 0): array
    {
        $qb = $this->db->createQueryBuilder()
            ->select('*')
            ->from('oci_users')
            ->orderBy('created_at', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if (!$includeDeleted) {
            $qb->andWhere('deleted_at IS NULL');
        }

        if ($role !== null) {
            $qb->andWhere('role = :role')->setParameter('role', $role);
        }

        if ($search !== null && $search !== '') {
            $qb->andWhere('(email LIKE :search OR username LIKE :search OR first_name LIKE :search OR last_name LIKE :search)')
                ->setParameter('search', '%' . $search . '%');
        }

        return $qb->executeQuery()->fetchAllAssociative();
    }

    public function countAll(?string $role = null, ?string $search = null, bool $includeDeleted = false): int
    {
        $qb = $this->db->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('oci_users');

        if (!$includeDeleted) {
            $qb->andWhere('deleted_at IS NULL');
        }

        if ($role !== null) {
            $qb->andWhere('role = :role')->setParameter('role', $role);
        }

        if ($search !== null && $search !== '') {
            $qb->andWhere('(email LIKE :search OR username LIKE :search OR first_name LIKE :search OR last_name LIKE :search)')
                ->setParameter('search', '%' . $search . '%');
        }

        return (int) $qb->executeQuery()->fetchOne();
    }

    public function create(array $data): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        if (isset($data['password'])) {
            $data['password'] = password_hash($data['password'], PASSWORD_BCRYPT);
        }

        $this->db->insert('oci_users', $data);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $data['updated_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        if (isset($data['password']) && $data['password'] !== '') {
            $data['password'] = password_hash($data['password'], PASSWORD_BCRYPT);
        } else {
            unset($data['password']);
        }

        $this->db->update('oci_users', $data, ['id' => $id]);
    }

    public function softDelete(int $id): void
    {
        $this->db->update('oci_users', [
            'deleted_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'is_active' => 0,
        ], ['id' => $id]);
    }

    public function restore(int $id): void
    {
        $this->db->update('oci_users', [
            'deleted_at' => null,
            'is_active' => 1,
        ], ['id' => $id]);
    }

    public function destroy(int $id): void
    {
        $this->db->delete('oci_users', ['id' => $id]);
    }

    public function updateRole(int $id, string $role): void
    {
        $this->db->update('oci_users', [
            'role' => $role,
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $this->db->update('oci_users', [
            'is_active' => $active ? 1 : 0,
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    public function resetLoginAttempts(int $id): void
    {
        $this->db->update('oci_users', [
            'login_attempts' => 0,
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    public function getUserSessions(int $userId): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT * FROM oci_user_sessions WHERE user_id = :uid ORDER BY created_at DESC',
            ['uid' => $userId],
        );
    }

    public function destroyUserSessions(int $userId): void
    {
        $this->db->delete('oci_user_sessions', ['user_id' => $userId]);
    }

    public function getUserCompany(int $userId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM oci_user_companies WHERE user_id = :uid',
            ['uid' => $userId],
        );

        return $row !== false ? $row : null;
    }

    public function upsertUserCompany(int $userId, array $data): void
    {
        $existing = $this->getUserCompany($userId);
        $data['updated_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        if ($existing !== null) {
            $this->db->update('oci_user_companies', $data, ['user_id' => $userId]);
        } else {
            $data['user_id'] = $userId;
            $data['created_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $this->db->insert('oci_user_companies', $data);
        }
    }

    public function countByRole(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT role, COUNT(*) AS cnt FROM oci_users WHERE deleted_at IS NULL GROUP BY role',
        );

        $counts = ['admin' => 0, 'customer' => 0, 'agency' => 0];
        foreach ($rows as $row) {
            $counts[$row['role']] = (int) $row['cnt'];
        }

        return $counts;
    }
}
