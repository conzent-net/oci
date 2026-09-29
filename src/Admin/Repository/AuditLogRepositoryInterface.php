<?php

declare(strict_types=1);

namespace OCI\Admin\Repository;

interface AuditLogRepositoryInterface
{
    /**
     * Insert an audit log entry.
     *
     * @param array<string, mixed> $data
     */
    public function insert(array $data): void;

    /**
     * Fetch paginated audit log entries with optional filters.
     *
     * @param array<string, mixed> $filters  Keys: entity_type, action, user_id, date_from, date_to, search
     * @return list<array<string, mixed>>
     */
    public function findAll(array $filters = [], int $page = 1, int $perPage = 50): array;

    /**
     * Count total entries matching filters.
     *
     * @param array<string, mixed> $filters
     */
    public function countAll(array $filters = []): int;

    /**
     * Get distinct entity types for filter dropdown.
     *
     * @return list<string>
     */
    public function getDistinctEntityTypes(): array;

    /**
     * Get distinct actions for filter dropdown.
     *
     * @return list<string>
     */
    public function getDistinctActions(): array;
}
