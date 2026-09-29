<?php

declare(strict_types=1);

namespace OCI\Agency\Repository;

use Doctrine\DBAL\Connection;

final class AgencyRepository implements AgencyRepositoryInterface
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    public function findOrCreateByUserId(int $userId, string $email): array
    {
        $agency = $this->findByUserId($userId);
        if ($agency !== null) {
            return $agency;
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->db->insert('oci_agencies', [
            'user_id' => $userId,
            'name' => $email,
            'contact_email' => $email,
            'agency_type' => 'agency',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findByUserId($userId);
    }

    public function findAll(): array
    {
        $sql = <<<'SQL'
            SELECT a.*, u.email AS owner_email, u.first_name AS owner_first_name, u.last_name AS owner_last_name,
                   (SELECT COUNT(*) FROM oci_agency_customers AS ac WHERE ac.agency_id = a.id AND ac.date_to IS NULL) AS customer_count
            FROM oci_agencies AS a
            INNER JOIN oci_users AS u ON u.id = a.user_id
            ORDER BY a.created_at DESC
        SQL;

        return $this->db->fetchAllAssociative($sql);
    }

    public function findByUserId(int $userId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM oci_agencies WHERE user_id = :userId AND is_active = 1',
            ['userId' => $userId],
        );

        return $row !== false ? $row : null;
    }

    /**
     * The agency that manages this customer — the AGENCY's owning user row.
     *
     * This joined `u.id = ac.customer_user_id`, which returns the customer's
     * own user row rather than the agency's. Every caller then treated
     * `$row['id']` as an agency user id when it was the customer's, so:
     * revoking an agency silently did nothing, the single-agency-per-customer
     * guard compared a customer id against an agency id and never fired, and
     * script regeneration ran against the wrong account.
     *
     * Nothing failed loudly, because both ids are small integers and every use
     * was "look up a row by this id" — which succeeded, on the wrong row. This
     * is exactly the trap {@see \OCI\Agency\Service\AgencyScope} exists to make
     * structurally impossible for new code; here it had been live in old code
     * all along.
     *
     * `agency_row_id` and `agency_name` are aliased on deliberately, so callers
     * that need the agency's own record do not have to guess which of the two
     * id spaces they are holding.
     */
    public function findAgencyForCustomer(int $customerUserId): ?array
    {
        $sql = <<<'SQL'
            SELECT u.*, a.id AS agency_row_id, a.name AS agency_name, a.status AS agency_status
            FROM oci_agency_customers AS ac
            INNER JOIN oci_agencies AS a ON a.id = ac.agency_id
            INNER JOIN oci_users AS u ON u.id = a.user_id
            WHERE ac.customer_user_id = :customerId
              AND ac.date_to IS NULL
            LIMIT 1
        SQL;

        $row = $this->db->fetchAssociative($sql, ['customerId' => $customerUserId]);

        return $row !== false ? $row : null;
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

    public function getCustomers(int $agencyUserId): array
    {
        $sql = <<<'SQL'
            SELECT u.id, u.email, u.first_name, u.last_name, u.is_active, u.last_login_at, u.created_at,
                   ac.date_from
            FROM oci_users AS u
            INNER JOIN oci_agency_customers AS ac ON u.id = ac.customer_user_id
            INNER JOIN oci_agencies AS a ON a.id = ac.agency_id
            WHERE a.user_id = :userId
              AND ac.date_to IS NULL
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
              AND ac.date_to IS NULL
            LIMIT 1
        SQL;

        return $this->db->fetchOne($sql, [
            'agencyUserId' => $agencyUserId,
            'customerId' => $customerUserId,
        ]) !== false;
    }

    /**
     * Link a client.
     *
     * Nothing stopped two agencies both holding a live link to the same
     * account, which meant both could read that client's sites and consent
     * records — legacy's one-agency-per-customer rule was never ported. It is
     * now enforced twice: here, with a readable message, and by the unique
     * index on `oci_agency_customers.active_customer` for the race this check
     * cannot see.
     *
     * @throws \DomainException if the account is already managed by someone else
     */
    public function addCustomer(int $agencyUserId, int $customerUserId): void
    {
        $agency = $this->findByUserId($agencyUserId);
        if ($agency === null) {
            return;
        }

        $this->linkCustomer((int) $agency['id'], $customerUserId);
    }

    /**
     * Start one agency-customer link, enforcing single ownership.
     *
     * Every link is its own row with its own `date_from` and `date_to`. A
     * client that leaves and comes back gets a NEW row, so the months in
     * between stay unmanaged in the usage export and the months before stay
     * managed — the previous version revived the old row and reset its
     * `date_from`, which erased the earlier period from every report.
     *
     * Takes the agency ROW id, not the agency's user id. The two are both small
     * integers and confusing them silently addresses another tenant rather than
     * failing, which is why every caller passes an id it read from a row.
     *
     * @throws \DomainException if another agency currently manages the account
     */
    private function linkCustomer(int $agencyId, int $customerUserId): void
    {
        $holder = $this->db->fetchOne(
            'SELECT agency_id FROM oci_agency_customers
             WHERE customer_user_id = :customerId AND date_to IS NULL
             LIMIT 1',
            ['customerId' => $customerUserId],
        );

        if ($holder !== false && (int) $holder === $agencyId) {
            // Already linked to this agency. Nothing to do, and certainly not
            // a second active row.
            return;
        }

        if ($holder !== false) {
            throw new \DomainException(
                'That account is already managed by another agency. It has to be released before it can be added.',
            );
        }

        try {
            $this->db->insert('oci_agency_customers', [
                'agency_id' => $agencyId,
                'customer_user_id' => $customerUserId,
                'date_from' => (new \DateTimeImmutable())->format('Y-m-d'),
                'date_to' => null,
            ]);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            // Two requests raced past the check above. The index caught the
            // second; report it the same way the check would have.
            throw new \DomainException(
                'That account is already managed by another agency. It has to be released before it can be added.',
            );
        }
    }

    /**
     * End the relationship without destroying the record of it.
     *
     * This used to DELETE the row. It cannot any more: `date_to IS NULL` is now
     * what "currently managed" means across scoping, branding entitlement and
     * the compliance dashboard, and the per-client usage export has to be able
     * to answer "was this client ours in March" after the relationship ends.
     * Deleting threw that away and made every historical report silently wrong.
     *
     * Re-linking later starts a new row. See {@see self::linkCustomer()}.
     */
    public function removeCustomer(int $agencyUserId, int $customerUserId): void
    {
        $agency = $this->findByUserId($agencyUserId);
        if ($agency === null) {
            return;
        }

        $this->db->executeStatement(
            'UPDATE oci_agency_customers
             SET date_to = :today
             WHERE agency_id = :agencyId AND customer_user_id = :customerId AND date_to IS NULL',
            [
                'today' => (new \DateTimeImmutable())->format('Y-m-d'),
                'agencyId' => $agency['id'],
                'customerId' => $customerUserId,
            ],
        );
    }

    /**
     * Invite somebody, whether or not they already have a Conzent account.
     *
     * `$targetUserId` is null when the address has no account yet. That is the
     * normal case and used to be impossible: an invite required an existing
     * user, so an agency could only invite clients who had already signed up
     * for Conzent themselves — backwards, since the agency is the one bringing
     * the client. The email is always recorded, so the invite can be matched
     * to the account when one appears.
     */
    public function createInvite(int $agencyUserId, ?int $targetUserId, string $token, string $email = ''): void
    {
        $agency = $this->findByUserId($agencyUserId);
        if ($agency === null) {
            return;
        }

        $this->db->insert('oci_agency_invites', [
            'agency_id' => $agency['id'],
            'target_user_id' => $targetUserId,
            'email' => $email !== '' ? mb_strtolower($email) : null,
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

    /**
     * Accept a token invite on behalf of a specific account.
     *
     * `$acceptingUserId` is required for invites sent to an address that had no
     * account: the row's `target_user_id` is NULL, so without being told who is
     * accepting there is nobody to link. The CALLER must already have verified
     * the invite belongs to this person — see InviteResponseHandler.
     */
    public function acceptInvite(string $token, int $acceptingUserId = 0): void
    {
        $invite = $this->findInviteByToken($token);
        if ($invite === null) {
            return;
        }

        $customerId = $acceptingUserId > 0
            ? $acceptingUserId
            : (int) ($invite['target_user_id'] ?? 0);

        if ($customerId <= 0) {
            return;
        }

        $hashedToken = hash('sha256', $token);

        // Mark as accepted, and bind it to whoever actually claimed it.
        $this->db->update(
            'oci_agency_invites',
            ['status' => 'accepted', 'target_user_id' => $customerId],
            ['token' => $hashedToken],
        );

        // Same rules as addCustomer(): refuse an account that another agency
        // currently holds. An invite must not be a way around that.
        $this->linkCustomer((int) $invite['agency_id'], $customerId);
    }

    public function declineInvite(string $token): void
    {
        $hashedToken = hash('sha256', $token);
        $this->db->update('oci_agency_invites', ['status' => 'declined'], ['token' => $hashedToken]);
    }

    /**
     * Accept an invite addressed either to this account or to its email.
     *
     * `$email` carries the accepting user's own address so an invite sent
     * before they had an account can be claimed. It is matched, never trusted
     * as an identifier: the row must already name that address, so this cannot
     * be used to claim somebody else's invite by asking for it.
     */
    public function acceptInviteById(int $inviteId, int $userId, string $email = ''): bool
    {
        $invite = $this->db->fetchAssociative(
            "SELECT * FROM oci_agency_invites
             WHERE id = :id
               AND (target_user_id = :userId OR (:email <> '' AND email = :email))
               AND status = 'pending'
               AND expires_at > NOW()",
            ['id' => $inviteId, 'userId' => $userId, 'email' => mb_strtolower($email)],
        );

        if ($invite === false) {
            return false;
        }

        // Bind the invite to the account that actually claimed it, so the
        // history says who accepted rather than only which address was asked.
        $this->db->update(
            'oci_agency_invites',
            ['status' => 'accepted', 'target_user_id' => $userId],
            ['id' => $inviteId],
        );

        // Same rules as addCustomer(): refuse an account that another agency
        // currently holds. An invite must not be a way around that.
        $this->linkCustomer((int) $invite['agency_id'], $userId);

        return true;
    }

    public function declineInviteById(int $inviteId, int $userId): bool
    {
        $affected = $this->db->executeStatement(
            'UPDATE oci_agency_invites SET status = :status WHERE id = :id AND target_user_id = :userId AND status = :pending AND expires_at > NOW()',
            ['status' => 'declined', 'id' => $inviteId, 'userId' => $userId, 'pending' => 'pending'],
        );

        return $affected > 0;
    }

    /**
     * Pending invites for a user — matched by id OR by their email address.
     *
     * The email match is what makes an invite sent before signup work: the
     * agency invites an address, the person signs up later, and the invite is
     * waiting for them. Matching only on `target_user_id` would strand every
     * such invite permanently, which is the whole reason invites needed an
     * account to exist first.
     */
    public function getPendingInvitesForUser(int $userId, string $email = ''): array
    {
        $sql = <<<'SQL'
            SELECT ai.*, a.name AS agency_name, u.email AS agency_email
            FROM oci_agency_invites AS ai
            INNER JOIN oci_agencies AS a ON a.id = ai.agency_id
            INNER JOIN oci_users AS u ON u.id = a.user_id
            WHERE (ai.target_user_id = :userId OR (:email <> '' AND ai.email = :email))
              AND ai.status = 'pending'
              AND ai.expires_at > NOW()
            ORDER BY ai.created_at DESC
        SQL;

        return $this->db->fetchAllAssociative($sql, [
            'userId' => $userId,
            'email' => mb_strtolower($email),
        ]);
    }

    /**
     * The agency's own outstanding invites, including those sent to an address
     * with no account yet.
     *
     * The user join is LEFT, not INNER: an invite to somebody who has not
     * signed up has a NULL `target_user_id`, and an inner join dropped every
     * one of those. The agency sent the invite, saw nothing in its pending
     * list, and had no way to withdraw it.
     */
    public function getPendingInvitesByAgency(int $agencyUserId): array
    {
        $sql = <<<'SQL'
            SELECT ai.id, ai.status, ai.created_at, ai.expires_at,
                   COALESCE(u.email, ai.email) AS target_email,
                   COALESCE(u.first_name, '') AS target_first_name,
                   COALESCE(u.last_name, '') AS target_last_name,
                   ai.target_user_id IS NULL AS needs_account
            FROM oci_agency_invites AS ai
            INNER JOIN oci_agencies AS a ON a.id = ai.agency_id
            LEFT JOIN oci_users AS u ON u.id = ai.target_user_id
            WHERE a.user_id = :userId AND ai.status = 'pending' AND ai.expires_at > NOW()
            ORDER BY ai.created_at DESC
        SQL;

        return $this->db->fetchAllAssociative($sql, ['userId' => $agencyUserId]);
    }

    public function withdrawInvite(int $inviteId, int $agencyUserId): bool
    {
        $affected = $this->db->executeStatement(
            'UPDATE oci_agency_invites SET status = :status
             WHERE id = :id AND status = :pending AND expires_at > NOW()
               AND agency_id IN (SELECT id FROM oci_agencies WHERE user_id = :userId)',
            ['status' => 'withdrawn', 'id' => $inviteId, 'pending' => 'pending', 'userId' => $agencyUserId],
        );

        return $affected > 0;
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
              AND ac.date_to IS NULL
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
