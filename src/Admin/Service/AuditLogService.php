<?php

declare(strict_types=1);

namespace OCI\Admin\Service;

use OCI\Admin\Repository\AuditLogRepositoryInterface;
use Psr\Log\LoggerInterface;

final class AuditLogService
{
    public function __construct(
        private readonly AuditLogRepositoryInterface $repo,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Record an audit log entry.
     *
     * @param array<string, mixed>|null $oldValues Previous values (null for create)
     * @param array<string, mixed>|null $newValues New values (null for delete)
     */
    public function log(
        ?int $userId,
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): void {
        try {
            $this->repo->insert([
                'user_id' => $userId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'old_values' => $oldValues !== null ? json_encode($oldValues, JSON_UNESCAPED_SLASHES) : null,
                'new_values' => $newValues !== null ? json_encode($newValues, JSON_UNESCAPED_SLASHES) : null,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 500) : null,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Audit logging should never break the main flow
            $this->logger->error('Failed to write audit log', [
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Fetch paginated audit log entries with optional filters.
     *
     * @param array<string, mixed> $filters
     * @return array{items: list<array<string, mixed>>, total: int, page: int, perPage: int}
     */
    public function list(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        return [
            'items' => $this->repo->findAll($filters, $page, $perPage),
            'total' => $this->repo->countAll($filters),
            'page' => $page,
            'perPage' => $perPage,
        ];
    }

    /**
     * @return list<string>
     */
    public function getEntityTypes(): array
    {
        return $this->repo->getDistinctEntityTypes();
    }

    /**
     * @return list<string>
     */
    public function getActions(): array
    {
        return $this->repo->getDistinctActions();
    }
}
