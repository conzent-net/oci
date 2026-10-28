<?php

declare(strict_types=1);

namespace OCI\Agency\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use OCI\Agency\Service\AgencyScope;

/**
 * The membership table is the join, so scoping cannot be forgotten.
 *
 * See {@see AgencyScopeRepositoryInterface} for why this shape rather than
 * `WHERE user_id IN (...)`. One consequence worth stating here: there is
 * deliberately no helper that returns "this agency's client user ids", because
 * the moment such a helper exists somebody will use it to hand-build a query
 * and the structural guarantee is gone.
 */
final class AgencyScopeRepository implements AgencyScopeRepositoryInterface
{
    /**
     * The FROM and JOIN every site query starts from. Active links only.
     *
     * Deliberately stops before the WHERE so callers can add their own
     * conditions; {@see self::SITE_WHERE} carries the part none of them may
     * drop.
     */
    private const SITE_FROM = <<<'SQL'
        FROM oci_sites AS s
        INNER JOIN oci_agency_customers AS ac
            ON ac.customer_user_id = s.user_id
           AND ac.agency_id = :agencyId
           AND ac.date_to IS NULL
        INNER JOIN oci_users AS u
            ON u.id = s.user_id
        SQL;

    /**
     * A deleted site is not a site. An agency surface that listed them would
     * report compliance problems for things that no longer exist.
     */
    private const SITE_WHERE = ' WHERE s.deleted_at IS NULL';

    public function __construct(private readonly Connection $db)
    {
    }

    public function findAgencyByUserId(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $row = $this->db->fetchAssociative(
            'SELECT * FROM oci_agencies WHERE user_id = :userId LIMIT 1',
            ['userId' => $userId],
            ['userId' => ParameterType::INTEGER],
        );

        return $row !== false ? $row : null;
    }

    public function findSites(AgencyScope $scope, int $limit = 100, int $offset = 0): array
    {
        $sql = 'SELECT s.*, u.email AS client_email, u.first_name AS client_first_name, u.last_name AS client_last_name '
            . self::SITE_FROM
            . self::SITE_WHERE
            . ' ORDER BY u.email ASC, s.domain ASC'
            . ' LIMIT :limit OFFSET :offset';

        return $this->db->fetchAllAssociative($sql, [
            'agencyId' => $scope->agencyId(),
            'limit' => $limit,
            'offset' => $offset,
        ], [
            'agencyId' => ParameterType::INTEGER,
            'limit' => ParameterType::INTEGER,
            'offset' => ParameterType::INTEGER,
        ]);
    }

    public function countSites(AgencyScope $scope): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) ' . self::SITE_FROM . self::SITE_WHERE,
            ['agencyId' => $scope->agencyId()],
            ['agencyId' => ParameterType::INTEGER],
        );
    }

    public function findSite(AgencyScope $scope, int $siteId): ?array
    {
        $sql = 'SELECT s.*, u.email AS client_email '
            . self::SITE_FROM
            . self::SITE_WHERE
            . ' AND s.id = :siteId LIMIT 1';

        $row = $this->db->fetchAssociative($sql, [
            'agencyId' => $scope->agencyId(),
            'siteId' => $siteId,
        ], [
            'agencyId' => ParameterType::INTEGER,
            'siteId' => ParameterType::INTEGER,
        ]);

        return $row !== false ? $row : null;
    }

    public function findClients(AgencyScope $scope): array
    {
        $sql = <<<'SQL'
            SELECT u.id AS user_id,
                   u.email,
                   u.first_name,
                   u.last_name,
                   ac.date_from,
                   COUNT(s.id) AS site_count,
                   SUM(CASE WHEN s.status = 'active' THEN 1 ELSE 0 END) AS active_site_count,
                   SUM(CASE WHEN s.suspended_reason IS NOT NULL AND s.suspended_reason <> '' THEN 1 ELSE 0 END) AS suspended_site_count
            FROM oci_agency_customers AS ac
            INNER JOIN oci_users AS u ON u.id = ac.customer_user_id
            LEFT JOIN oci_sites AS s ON s.user_id = u.id AND s.deleted_at IS NULL
            WHERE ac.agency_id = :agencyId
              AND ac.date_to IS NULL
            GROUP BY u.id, u.email, u.first_name, u.last_name, ac.date_from
            ORDER BY u.email ASC
            SQL;

        return $this->db->fetchAllAssociative(
            $sql,
            ['agencyId' => $scope->agencyId()],
            ['agencyId' => ParameterType::INTEGER],
        );
    }

    public function findClient(AgencyScope $scope, int $customerUserId): ?array
    {
        $sql = <<<'SQL'
            SELECT u.id AS user_id, u.email, u.first_name, u.last_name, ac.date_from
            FROM oci_agency_customers AS ac
            INNER JOIN oci_users AS u ON u.id = ac.customer_user_id
            WHERE ac.agency_id = :agencyId
              AND ac.customer_user_id = :customerUserId
              AND ac.date_to IS NULL
            LIMIT 1
            SQL;

        $row = $this->db->fetchAssociative($sql, [
            'agencyId' => $scope->agencyId(),
            'customerUserId' => $customerUserId,
        ], [
            'agencyId' => ParameterType::INTEGER,
            'customerUserId' => ParameterType::INTEGER,
        ]);

        return $row !== false ? $row : null;
    }

    public function countActiveClients(AgencyScope $scope): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM oci_agency_customers WHERE agency_id = :agencyId AND date_to IS NULL',
            ['agencyId' => $scope->agencyId()],
            ['agencyId' => ParameterType::INTEGER],
        );
    }

    public function findSiteIds(AgencyScope $scope): array
    {
        /** @var array<int, int|string> $ids */
        $ids = $this->db->fetchFirstColumn(
            'SELECT s.id ' . self::SITE_FROM . self::SITE_WHERE . ' ORDER BY s.id',
            ['agencyId' => $scope->agencyId()],
            ['agencyId' => ParameterType::INTEGER],
        );

        return array_map(static fn (int|string $id): int => (int) $id, $ids);
    }
}
