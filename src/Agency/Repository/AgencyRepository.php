<?php

declare(strict_types=1);

namespace OCI\Agency\Repository;

use Doctrine\DBAL\Connection;

final class AgencyRepository implements AgencyRepositoryInterface
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    public function findByUserId(int $userId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM oci_agencies WHERE user_id = :userId AND is_active = 1',
            ['userId' => $userId],
        );

        return $row !== false ? $row : null;
    }

    public function findAgencyForCustomer(int $customerUserId): ?array
    {
        $sql = <<<'SQL'
            SELECT u.*
            FROM oci_users AS u
            INNER JOIN oci_agency_customers AS ac ON u.id = ac.customer_user_id
            INNER JOIN oci_agencies AS a ON a.id = ac.agency_id
            WHERE ac.customer_user_id = :customerId
            LIMIT 1
        SQL;

        $row = $this->db->fetchAssociative($sql, ['customerId' => $customerUserId]);

        return $row !== false ? $row : null;
    }

    public function getMonthlyCommissions(int $agencyUserId): array
    {
        $sql = <<<'SQL'
            SELECT
                SUM(ac.amount) AS total_commission,
                DATE_FORMAT(ac.created_at, '%m-%Y') AS date_month
            FROM oci_agency_commissions AS ac
            INNER JOIN oci_agencies AS ag ON ac.agency_id = ag.id
            WHERE ag.user_id = :userId
              AND ac.created_at > DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY date_month
            ORDER BY date_month ASC
        SQL;

        /** @var array<int, array{date_month: string, total_commission: string}> */
        return $this->db->fetchAllAssociative($sql, ['userId' => $agencyUserId]);
    }

    public function getMonthlyCustomers(int $agencyUserId): array
    {
        $sql = <<<'SQL'
            SELECT
                COUNT(ac.customer_user_id) AS total_customers,
                DATE_FORMAT(ac.date_from, '%m-%Y') AS date_month
            FROM oci_agency_customers AS ac
            INNER JOIN oci_agencies AS ag ON ac.agency_id = ag.id
            WHERE ag.user_id = :userId
              AND ac.date_from > DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY date_month
            ORDER BY date_month ASC
        SQL;

        /** @var array<int, array{date_month: string, total_customers: int}> */
        return $this->db->fetchAllAssociative($sql, ['userId' => $agencyUserId]);
    }

    public function getPayoutLast30Days(int $agencyUserId): float
    {
        $sql = <<<'SQL'
            SELECT COALESCE(SUM(ac.amount), 0) AS total_commission
            FROM oci_agency_commissions AS ac
            INNER JOIN oci_agencies AS ag ON ac.agency_id = ag.id
            WHERE ac.status = 'paid'
              AND ag.user_id = :userId
              AND ac.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        SQL;

        $result = $this->db->fetchOne($sql, ['userId' => $agencyUserId]);

        return (float) ($result ?? 0);
    }

    public function getCustomers(int $agencyUserId): array
    {
        $sql = <<<'SQL'
            SELECT u.id, u.email, u.first_name, u.last_name, u.is_active, u.last_login_at, u.created_at,
                   ac.date_from
            FROM oci_users AS u
            INNER JOIN oci_agency_customers AS ac ON u.id = ac.customer_user_id
            INNER JOIN oci_agencies AS a ON a.id = ac.agency_id
            WHERE a.user_id = :userId
            ORDER BY ac.date_from DESC
        SQL;

        return $this->db->fetchAllAssociative($sql, ['userId' => $agencyUserId]);
    }

    public function isCustomer(int $agencyUserId, int $customerUserId): bool
    {
        $sql = <<<'SQL'
            SELECT 1
            FROM oci_agency_customers AS ac
            INNER JOIN oci_agencies AS a ON a.id = ac.agency_id
            WHERE a.user_id = :agencyUserId AND ac.customer_user_id = :customerId
            LIMIT 1
        SQL;

        return $this->db->fetchOne($sql, [
            'agencyUserId' => $agencyUserId,
            'customerId' => $customerUserId,
        ]) !== false;
    }

    public function addCustomer(int $agencyUserId, int $customerUserId): void
    {
        $agency = $this->findByUserId($agencyUserId);
        if ($agency === null) {
            return;
        }

        $this->db->insert('oci_agency_customers', [
            'agency_id' => $agency['id'],
            'customer_user_id' => $customerUserId,
            'date_from' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function removeCustomer(int $agencyUserId, int $customerUserId): void
    {
        $agency = $this->findByUserId($agencyUserId);
        if ($agency === null) {
            return;
        }

        $this->db->delete('oci_agency_customers', [
            'agency_id' => $agency['id'],
            'customer_user_id' => $customerUserId,
        ]);
    }

    public function createInvite(int $agencyUserId, int $targetUserId, string $token): void
    {
        $agency = $this->findByUserId($agencyUserId);
        if ($agency === null) {
            return;
        }

        $this->db->insert('oci_agency_invites', [
            'agency_id' => $agency['id'],
            'target_user_id' => $targetUserId,
            'token' => hash('sha256', $token),
            'status' => 'pending',
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'expires_at' => (new \DateTimeImmutable())->modify('+7 days')->format('Y-m-d H:i:s'),
        ]);
    }

    public function findInviteByToken(string $token): ?array
    {
        $hashedToken = hash('sha256', $token);
        $row = $this->db->fetchAssociative(
            'SELECT ai.*, a.user_id AS agency_user_id, a.name AS agency_name
             FROM oci_agency_invites AS ai
             INNER JOIN oci_agencies AS a ON a.id = ai.agency_id
             WHERE ai.token = :token AND ai.status = :status AND ai.expires_at > NOW()',
            ['token' => $hashedToken, 'status' => 'pending'],
        );

        return $row !== false ? $row : null;
    }

    public function acceptInvite(string $token): void
    {
        $invite = $this->findInviteByToken($token);
        if ($invite === null) {
            return;
        }

        $hashedToken = hash('sha256', $token);

        // Mark as accepted
        $this->db->update('oci_agency_invites', ['status' => 'accepted'], ['token' => $hashedToken]);

        // Add customer relationship
        $this->db->insert('oci_agency_customers', [
            'agency_id' => $invite['agency_id'],
            'customer_user_id' => $invite['target_user_id'],
            'date_from' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function declineInvite(string $token): void
    {
        $hashedToken = hash('sha256', $token);
        $this->db->update('oci_agency_invites', ['status' => 'declined'], ['token' => $hashedToken]);
    }

    public function getPendingInvitesForUser(int $userId): array
    {
        $sql = <<<'SQL'
            SELECT ai.*, a.name AS agency_name, u.email AS agency_email
            FROM oci_agency_invites AS ai
            INNER JOIN oci_agencies AS a ON a.id = ai.agency_id
            INNER JOIN oci_users AS u ON u.id = a.user_id
            WHERE ai.target_user_id = :userId AND ai.status = 'pending' AND ai.expires_at > NOW()
            ORDER BY ai.created_at DESC
        SQL;

        return $this->db->fetchAllAssociative($sql, ['userId' => $userId]);
    }

    public function getCustomerHealthData(int $agencyId): array
    {
        // Get all customers with their site health data in a single efficient query
        $sql = <<<'SQL'
            SELECT
                u.id AS customer_id,
                u.email,
                u.first_name,
                u.last_name,
                u.is_active AS customer_active,
                ac.date_from,
                COUNT(DISTINCT s.id) AS total_sites,
                COUNT(DISTINCT CASE WHEN s.status = 'active' AND s.deleted_at IS NULL THEN s.id END) AS active_sites,
                COUNT(DISTINCT CASE WHEN s.status != 'active' OR s.deleted_at IS NOT NULL THEN s.id END) AS inactive_sites,
                MAX(sc.completed_at) AS last_scan_date,
                COUNT(DISTINCT sb.id) AS banners_configured,
                COUNT(DISTINCT cp.id) AS cookie_policies,
                COUNT(DISTINCT pp.id) AS privacy_policies,
                COALESCE(SUM(beacon_counts.beacon_count), 0) AS total_beacons
            FROM oci_agency_customers AS ac
            INNER JOIN oci_users AS u ON u.id = ac.customer_user_id
            LEFT JOIN oci_sites AS s ON s.user_id = u.id AND s.deleted_at IS NULL
            LEFT JOIN oci_scans AS sc ON sc.site_id = s.id AND sc.scan_status = 'completed'
            LEFT JOIN oci_site_banners AS sb ON sb.site_id = s.id
            LEFT JOIN oci_cookie_policies AS cp ON cp.site_id = s.id
            LEFT JOIN oci_privacy_policies AS pp ON pp.site_id = s.id
            LEFT JOIN (
                SELECT b.site_id, COUNT(*) AS beacon_count
                FROM oci_beacons AS b
                GROUP BY b.site_id
            ) AS beacon_counts ON beacon_counts.site_id = s.id
            WHERE ac.agency_id = :agencyId
            GROUP BY u.id, u.email, u.first_name, u.last_name, u.is_active, ac.date_from
            ORDER BY ac.date_from DESC
        SQL;

        $customers = $this->db->fetchAllAssociative($sql, ['agencyId' => $agencyId]);

        // Compute aggregates
        $totalCustomers = \count($customers);
        $totalActiveSites = 0;
        $totalInactiveSites = 0;
        $sitesWithIssues = 0;

        foreach ($customers as &$customer) {
            $activeSites = (int) $customer['active_sites'];
            $totalActiveSites += $activeSites;
            $totalInactiveSites += (int) $customer['inactive_sites'];

            // A site has issues if: no banner configured, no policy, or has beacons (pre-consent trackers)
            $hasBanner = (int) $customer['banners_configured'] > 0;
            $hasPolicy = ((int) $customer['cookie_policies'] > 0) || ((int) $customer['privacy_policies'] > 0);
            $hasBeacons = (int) $customer['total_beacons'] > 0;

            $issues = [];
            if ($activeSites > 0 && !$hasBanner) {
                $issues[] = 'No banner';
            }
            if ($activeSites > 0 && !$hasPolicy) {
                $issues[] = 'No policy';
            }
            if ($hasBeacons) {
                $issues[] = 'Pre-consent trackers';
            }

            $customer['issues'] = $issues;
            $customer['health'] = empty($issues) ? 'healthy' : ($hasBeacons ? 'error' : 'warning');

            if (!empty($issues)) {
                $sitesWithIssues++;
            }
        }
        unset($customer);

        return [
            'customers' => $customers,
            'total_customers' => $totalCustomers,
            'active_sites' => $totalActiveSites,
            'inactive_sites' => $totalInactiveSites,
            'sites_with_issues' => $sitesWithIssues,
        ];
    }
}
