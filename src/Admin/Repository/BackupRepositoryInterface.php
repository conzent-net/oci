<?php

declare(strict_types=1);

namespace OCI\Admin\Repository;

interface BackupRepositoryInterface
{
    /**
     * Open a run row before any work starts, so a crash still leaves evidence.
     *
     * @return int the new backup id
     */
    public function start(string $filename, string $path, string $triggerType, bool $includesConfig): int;

    /** @param array<string, mixed> $data */
    public function finish(int $id, array $data): void;

    public function fail(int $id, string $error): void;

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array;

    /** @return array<string, mixed>|null */
    public function latestSuccessful(): ?array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 50, int $offset = 0): array;

    public function countAll(): int;

    /**
     * Successful backups, oldest first, for retention pruning.
     *
     * @return array<int, array<string, mixed>>
     */
    public function successfulOldestFirst(): array;

    public function delete(int $id): void;

    /** Any run left 'running' by a process that is no longer alive. */
    public function markStaleAsFailed(int $olderThanMinutes = 180): int;

    public function recordDestination(int $backupId, string $destination, string $status, ?string $remotePath, ?int $bytesSent, ?int $durationMs, ?string $error): void;

    /** @return array<int, array<string, mixed>> */
    public function destinationsFor(int $backupId): array;
}
