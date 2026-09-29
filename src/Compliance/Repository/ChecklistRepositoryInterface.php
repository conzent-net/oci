<?php

declare(strict_types=1);

namespace OCI\Compliance\Repository;

interface ChecklistRepositoryInterface
{
    /**
     * Get all checked item IDs for a user + regulation.
     *
     * @return array<int, string>  e.g. ['gdpr-001', 'gdpr-003']
     */
    public function getCheckedItems(int $userId, string $regulationId): array;

    /**
     * Mark an item as checked (insert row).
     * Returns true if a new row was inserted.
     */
    public function checkItem(int $userId, string $regulationId, string $itemId): bool;

    /**
     * Mark an item as unchecked (delete row).
     * Returns true if a row was deleted.
     */
    public function uncheckItem(int $userId, string $regulationId, string $itemId): bool;

    /**
     * Get checked item counts grouped by regulation for a user.
     *
     * @return array<string, int>  e.g. ['gdpr' => 5, 'ccpa' => 3]
     */
    public function getCheckedCountsByRegulation(int $userId): array;

    /**
     * Check if a specific item is checked.
     */
    public function isChecked(int $userId, string $regulationId, string $itemId): bool;
}
