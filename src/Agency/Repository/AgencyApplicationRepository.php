<?php

declare(strict_types=1);

namespace OCI\Agency\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class AgencyApplicationRepository implements AgencyApplicationRepositoryInterface
{
    /** Everything a caller may write. Anything else is set by the workflow. */
    private const WRITABLE = ['user_id', 'company_name', 'website', 'contact_email', 'client_count', 'pitch'];

    public function __construct(private readonly Connection $db)
    {
    }

    public function findPendingForUser(int $userId): ?array
    {
        $row = $this->db->fetchAssociative(
            "SELECT * FROM oci_agency_applications
             WHERE user_id = :userId AND status = 'pending'
             ORDER BY id DESC LIMIT 1",
            ['userId' => $userId],
            ['userId' => ParameterType::INTEGER],
        );

        return $row !== false ? $row : null;
    }

    public function findLatestForUser(int $userId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM oci_agency_applications
             WHERE user_id = :userId
             ORDER BY id DESC LIMIT 1',
            ['userId' => $userId],
            ['userId' => ParameterType::INTEGER],
        );

        return $row !== false ? $row : null;
    }

    public function create(array $data): int
    {
        // Whitelist rather than pass through. The status and review columns
        // decide whether somebody is an agency, and a form array that reached
        // this method with 'status' => 'approved' in it must not be able to
        // self-approve.
        $insert = array_intersect_key($data, array_flip(self::WRITABLE));
        $insert['status'] = 'pending';
        $insert['created_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $insert['updated_at'] = $insert['created_at'];

        $this->db->insert('oci_agency_applications', $insert);

        return (int) $this->db->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT a.*, u.email AS applicant_email, u.first_name AS applicant_first_name, u.last_name AS applicant_last_name
             FROM oci_agency_applications AS a
             INNER JOIN oci_users AS u ON u.id = a.user_id
             WHERE a.id = :id LIMIT 1',
            ['id' => $id],
            ['id' => ParameterType::INTEGER],
        );

        return $row !== false ? $row : null;
    }

    public function listByStatus(string $status, int $limit = 50, int $offset = 0): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT a.*, u.email AS applicant_email, u.first_name AS applicant_first_name, u.last_name AS applicant_last_name
             FROM oci_agency_applications AS a
             INNER JOIN oci_users AS u ON u.id = a.user_id
             WHERE a.status = :status
             ORDER BY a.created_at ASC
             LIMIT :limit OFFSET :offset',
            ['status' => $status, 'limit' => $limit, 'offset' => $offset],
            [
                'status' => ParameterType::STRING,
                'limit' => ParameterType::INTEGER,
                'offset' => ParameterType::INTEGER,
            ],
        );
    }

    public function countByStatus(string $status): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM oci_agency_applications WHERE status = :status',
            ['status' => $status],
        );
    }

    public function markReviewed(int $id, string $status, int $reviewerId, ?string $note): void
    {
        $this->db->update('oci_agency_applications', [
            'status' => $status,
            'review_note' => $note,
            'reviewed_by' => $reviewerId,
            'reviewed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    public function withdraw(int $id, int $userId): bool
    {
        // The user_id and status conditions are the authorization: a request
        // that is not yours, or is already decided, matches zero rows.
        return $this->db->executeStatement(
            "UPDATE oci_agency_applications
             SET status = 'withdrawn'
             WHERE id = :id AND user_id = :userId AND status = 'pending'",
            ['id' => $id, 'userId' => $userId],
            ['id' => ParameterType::INTEGER, 'userId' => ParameterType::INTEGER],
        ) > 0;
    }
}
