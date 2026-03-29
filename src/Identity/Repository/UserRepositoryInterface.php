<?php

declare(strict_types=1);

namespace OCI\Identity\Repository;

interface UserRepositoryInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAll(?string $role = null, ?string $search = null, bool $includeDeleted = false, int $limit = 50, int $offset = 0): array;

    public function countAll(?string $role = null, ?string $search = null, bool $includeDeleted = false): int;

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int;

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void;

    public function softDelete(int $id): void;

    public function restore(int $id): void;

    public function destroy(int $id): void;

    public function updateRole(int $id, string $role): void;

    public function setActive(int $id, bool $active): void;

    public function resetLoginAttempts(int $id): void;

    /**
     * Get active sessions for a user.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getUserSessions(int $userId): array;

    /**
     * Destroy all sessions for a user.
     */
    public function destroyUserSessions(int $userId): void;

    /**
     * @return array<string, mixed>|null
     */
    public function getUserCompany(int $userId): ?array;

    /**
     * @param array<string, mixed> $data
     */
    public function upsertUserCompany(int $userId, array $data): void;

    /**
     * Count users by role.
     *
     * @return array<string, int>
     */
    public function countByRole(): array;
}
