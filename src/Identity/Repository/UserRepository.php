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

    /**
     * One canonical subscription row per user.
     *
     * A user can hold several rows (the legacy migrator imports one per site),
     * so pick the same one the rest of the app treats as "the" subscription:
     * live statuses first, then most recent.
     */
    private const CANONICAL_SUBSCRIPTION_SQL = <<<'SQL'
        SELECT id, user_id, plan_key, billing_cycle, quantity, status, is_lifetime,
               stripe_subscription_id, stripe_customer_id, current_period_end,
               trial_end, cancel_requested_at, created_at,
               ROW_NUMBER() OVER (
                   PARTITION BY user_id
                   ORDER BY CASE status
                                WHEN 'active'   THEN 0
                                WHEN 'trialing' THEN 1
                                WHEN 'past_due' THEN 2
                                ELSE 3
                            END,
                            created_at DESC, id DESC
               ) AS rn
        FROM oci_subscriptions
        SQL;

    /** Site counts per user, excluding soft-deleted sites. */
    private const SITE_COUNTS_SQL = <<<'SQL'
        SELECT user_id,
               COUNT(*) AS total_sites,
               SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_sites
        FROM oci_sites
        WHERE deleted_at IS NULL
        GROUP BY user_id
        SQL;

    public function findAll(?string $role = null, ?string $search = null, bool $includeDeleted = false, int $limit = 50, int $offset = 0, ?string $subscriptionStatus = null): array
    {
        [$where, $params, $types] = $this->buildUserFilters($role, $search, $includeDeleted, $subscriptionStatus);

        $canonical = self::CANONICAL_SUBSCRIPTION_SQL;
        $siteCounts = self::SITE_COUNTS_SQL;

        $params['lim'] = $limit;
        $params['off'] = $offset;
        $types['lim'] = ParameterType::INTEGER;
        $types['off'] = ParameterType::INTEGER;

        // last_payment_* = the most recent ACTUAL payment (Stripe invoice total,
        // net of coupons), which can differ from the plan list price. NULL means
        // no payment was ever recorded for this subscription.
        return $this->db->fetchAllAssociative(
            "SELECT u.*,
                    s.id                     AS sub_id,
                    s.plan_key               AS sub_plan_key,
                    s.billing_cycle          AS sub_billing_cycle,
                    s.quantity               AS sub_quantity,
                    s.status                 AS sub_status,
                    s.is_lifetime            AS sub_is_lifetime,
                    s.stripe_subscription_id AS sub_stripe_id,
                    s.current_period_end     AS sub_period_end,
                    s.trial_end              AS sub_trial_end,
                    s.cancel_requested_at    AS sub_cancel_requested_at,
                    COALESCE(sc.total_sites, 0)  AS total_sites,
                    COALESCE(sc.active_sites, 0) AS active_sites,
                    (SELECT t.amount FROM oci_transactions t
                     WHERE t.subscription_id = s.id AND t.type = 'payment'
                     ORDER BY t.created_at DESC, t.id DESC LIMIT 1) AS last_payment_amount,
                    (SELECT t.currency FROM oci_transactions t
                     WHERE t.subscription_id = s.id AND t.type = 'payment'
                     ORDER BY t.created_at DESC, t.id DESC LIMIT 1) AS last_payment_currency
             FROM oci_users u
             LEFT JOIN ({$canonical}) s ON s.user_id = u.id AND s.rn = 1
             LEFT JOIN ({$siteCounts}) sc ON sc.user_id = u.id
             {$where}
             ORDER BY u.created_at DESC
             LIMIT :lim OFFSET :off",
            $params,
            $types,
        );
    }

    public function countAll(?string $role = null, ?string $search = null, bool $includeDeleted = false, ?string $subscriptionStatus = null): int
    {
        [$where, $params, $types] = $this->buildUserFilters($role, $search, $includeDeleted, $subscriptionStatus);

        // The subscription join is only needed when filtering on it.
        $join = $subscriptionStatus !== null
            ? 'LEFT JOIN (' . self::CANONICAL_SUBSCRIPTION_SQL . ') s ON s.user_id = u.id AND s.rn = 1'
            : '';

        return (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM oci_users u {$join} {$where}",
            $params,
            $types,
        );
    }

    public function countBySubscriptionStatus(bool $includeDeleted = false): array
    {
        $canonical = self::CANONICAL_SUBSCRIPTION_SQL;
        $where = $includeDeleted ? '' : 'WHERE u.deleted_at IS NULL';

        $rows = $this->db->fetchAllAssociative(
            "SELECT COALESCE(s.status, 'none') AS status, COUNT(*) AS c
             FROM oci_users u
             LEFT JOIN ({$canonical}) s ON s.user_id = u.id AND s.rn = 1
             {$where}
             GROUP BY COALESCE(s.status, 'none')",
        );

        $counts = [
            'active' => 0,
            'trialing' => 0,
            'past_due' => 0,
            'cancelled' => 0,
            'expired' => 0,
            'none' => 0,
        ];

        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['c'];
        }

        return $counts;
    }

    /**
     * Shared WHERE clause for the user list and its count.
     *
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function buildUserFilters(?string $role, ?string $search, bool $includeDeleted, ?string $subscriptionStatus): array
    {
        $where = [];
        $params = [];
        $types = [];

        if (!$includeDeleted) {
            $where[] = 'u.deleted_at IS NULL';
        }

        if ($role !== null && $role !== '') {
            $where[] = 'u.role = :role';
            $params['role'] = $role;
        }

        if ($search !== null && $search !== '') {
            $where[] = '(u.email LIKE :search OR u.username LIKE :search OR u.first_name LIKE :search OR u.last_name LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        if ($subscriptionStatus !== null && $subscriptionStatus !== '') {
            if ($subscriptionStatus === 'none') {
                $where[] = 's.id IS NULL';
            } elseif ($subscriptionStatus === 'paying') {
                $where[] = "s.status IN ('active', 'trialing')";
            } else {
                $where[] = 's.status = :subStatus';
                $params['subStatus'] = $subscriptionStatus;
            }
        }

        return [
            $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '',
            $params,
            $types,
        ];
    }

    public function create(array $data): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        if (isset($data['password']) && !str_starts_with((string) $data['password'], '$2')) {
            $data['password'] = password_hash($data['password'], PASSWORD_BCRYPT);
        }

        $this->db->insert('oci_users', $data);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $data['updated_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        if (isset($data['password']) && $data['password'] !== '') {
            if (!str_starts_with((string) $data['password'], '$2')) {
                $data['password'] = password_hash($data['password'], PASSWORD_BCRYPT);
            }
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
