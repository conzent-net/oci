<?php

declare(strict_types=1);

namespace OCI\Compliance\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class ChecklistRepository implements ChecklistRepositoryInterface
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    public function getCheckedItems(int $userId, string $regulationId): array
    {
        return $this->db->fetchFirstColumn(
            'SELECT item_id FROM oci_user_checklist_items WHERE user_id = :userId AND regulation_id = :regId',
            ['userId' => $userId, 'regId' => $regulationId],
            ['userId' => ParameterType::INTEGER],
        );
    }

    public function checkItem(int $userId, string $regulationId, string $itemId): bool
    {
        // INSERT IGNORE to handle duplicate gracefully
        $affected = $this->db->executeStatement(
            'INSERT IGNORE INTO oci_user_checklist_items (user_id, regulation_id, item_id, checked_at)
             VALUES (:userId, :regId, :itemId, NOW())',
            ['userId' => $userId, 'regId' => $regulationId, 'itemId' => $itemId],
            ['userId' => ParameterType::INTEGER],
        );

        return $affected > 0;
    }

    public function uncheckItem(int $userId, string $regulationId, string $itemId): bool
    {
        $affected = $this->db->executeStatement(
            'DELETE FROM oci_user_checklist_items WHERE user_id = :userId AND regulation_id = :regId AND item_id = :itemId',
            ['userId' => $userId, 'regId' => $regulationId, 'itemId' => $itemId],
            ['userId' => ParameterType::INTEGER],
        );

        return $affected > 0;
    }

    public function getCheckedCountsByRegulation(int $userId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT regulation_id, COUNT(*) AS cnt FROM oci_user_checklist_items WHERE user_id = :userId GROUP BY regulation_id',
            ['userId' => $userId],
            ['userId' => ParameterType::INTEGER],
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['regulation_id']] = (int) $row['cnt'];
        }

        return $counts;
    }

    public function isChecked(int $userId, string $regulationId, string $itemId): bool
    {
        $result = $this->db->fetchOne(
            'SELECT 1 FROM oci_user_checklist_items WHERE user_id = :userId AND regulation_id = :regId AND item_id = :itemId',
            ['userId' => $userId, 'regId' => $regulationId, 'itemId' => $itemId],
            ['userId' => ParameterType::INTEGER],
        );

        return $result !== false;
    }
}
