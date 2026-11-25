<?php

declare(strict_types=1);

namespace OCI\Admin\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class BackupRepository implements BackupRepositoryInterface
{
    private const TABLE = 'oci_backups';
    private const DEST_TABLE = 'oci_backup_destinations';

    public function __construct(private readonly Connection $db)
    {
    }

    public function start(string $filename, string $path, string $triggerType, bool $includesConfig): int
    {
        $this->db->insert(self::TABLE, [
            'filename' => $filename,
            'path' => $path,
            'trigger_type' => $triggerType,
            'status' => 'running',
            'includes_config' => $includesConfig ? 1 : 0,
            'started_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function finish(int $id, array $data): void
    {
        $data['status'] = 'success';
        $data['finished_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->db->update(self::TABLE, $data, ['id' => $id]);
    }

    public function fail(int $id, string $error): void
    {
        $this->db->update(self::TABLE, [
            'status' => 'failed',
            'error' => mb_substr($error, 0, 4000),
            'finished_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    public function find(int $id): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . self::TABLE . ' WHERE id = :id',
            ['id' => $id],
        );

        return $row === false ? null : $row;
    }

    public function latestSuccessful(): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . self::TABLE . " WHERE status = 'success' ORDER BY finished_at DESC LIMIT 1",
        );

        return $row === false ? null : $row;
    }

    public function recent(int $limit = 50, int $offset = 0): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT * FROM ' . self::TABLE . ' ORDER BY started_at DESC LIMIT :limit OFFSET :offset',
            ['limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );
    }

    public function countAll(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE);
    }

    public function successfulOldestFirst(): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT * FROM ' . self::TABLE . " WHERE status = 'success' ORDER BY finished_at ASC",
        );
    }

    public function delete(int $id): void
    {
        $this->db->delete(self::TABLE, ['id' => $id]);
    }

    /**
     * A run row still marked 'running' long after any real backup could still
     * be going is the fingerprint of a killed worker. Left alone it would show
     * as a permanently in-progress backup in the UI, which reads as "working"
     * — the precise illusion this whole feature exists to remove.
     */
    public function markStaleAsFailed(int $olderThanMinutes = 180): int
    {
        return (int) $this->db->executeStatement(
            'UPDATE ' . self::TABLE . " SET status = 'failed',
                    error = 'Interrupted — the process running this backup stopped before it finished.',
                    finished_at = NOW()
              WHERE status = 'running' AND started_at < (NOW() - INTERVAL :mins MINUTE)",
            ['mins' => $olderThanMinutes],
            ['mins' => ParameterType::INTEGER],
        );
    }

    public function recordDestination(int $backupId, string $destination, string $status, ?string $remotePath, ?int $bytesSent, ?int $durationMs, ?string $error): void
    {
        $this->db->executeStatement(
            'INSERT INTO ' . self::DEST_TABLE . ' (backup_id, destination, status, remote_path, bytes_sent, duration_ms, uploaded_at, error)
             VALUES (:backup_id, :destination, :status, :remote_path, :bytes_sent, :duration_ms, :uploaded_at, :error)
             ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                remote_path = VALUES(remote_path),
                bytes_sent = VALUES(bytes_sent),
                duration_ms = VALUES(duration_ms),
                uploaded_at = VALUES(uploaded_at),
                error = VALUES(error)',
            [
                'backup_id' => $backupId,
                'destination' => $destination,
                'status' => $status,
                'remote_path' => $remotePath,
                'bytes_sent' => $bytesSent,
                'duration_ms' => $durationMs,
                'uploaded_at' => $status === 'uploaded' ? (new \DateTimeImmutable())->format('Y-m-d H:i:s') : null,
                'error' => $error === null ? null : mb_substr($error, 0, 2000),
            ],
        );
    }

    public function destinationsFor(int $backupId): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT * FROM ' . self::DEST_TABLE . ' WHERE backup_id = :id ORDER BY destination',
            ['id' => $backupId],
        );
    }
}
