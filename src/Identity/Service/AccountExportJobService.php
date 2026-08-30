<?php

declare(strict_types=1);

namespace OCI\Identity\Service;

use Doctrine\DBAL\Connection;
use OCI\Admin\Service\AuditLogService;
use Predis\Client as RedisClient;
use Psr\Log\LoggerInterface;

/**
 * Self-serve account export: queue, worker step, and status record.
 *
 * The generation runs in the queue worker, never in the HTTP request, so an
 * account with millions of consent rows cannot time out a page. Status lives
 * in oci_configuration (scope=user); the finished zip in var/exports, served
 * only to the owning session by the download handler. Open core.
 */
final class AccountExportJobService
{
    private const QUEUE_KEY = 'oci:account_export:queue';
    private const CONFIG_KEY = 'account_export';

    public function __construct(
        private readonly RedisClient $redis,
        private readonly Connection $db,
        private readonly AccountExportService $exportService,
        private readonly AuditLogService $auditLog,
        private readonly LoggerInterface $logger,
        private readonly string $basePath = '',
    ) {}

    /**
     * @return array<string, mixed> The new status record
     */
    public function request(int $userId): array
    {
        $current = $this->status($userId);
        if (($current['status'] ?? '') === 'pending') {
            return $current;
        }

        $status = [
            'status' => 'pending',
            'requested_at' => date('c'),
            'finished_at' => null,
            'size' => null,
            'error' => null,
        ];
        $this->saveStatus($userId, $status);
        $this->redis->lpush(self::QUEUE_KEY, [(string) $userId]);

        return $status;
    }

    /**
     * One worker step. Returns true when a job was processed.
     */
    public function processNext(): bool
    {
        $userId = (int) ($this->redis->rpop(self::QUEUE_KEY) ?? 0);
        if ($userId <= 0) {
            return false;
        }

        try {
            $result = $this->exportService->export($userId, $this->exportDir());

            // One export per user: replace the previous zip.
            $previous = $this->status($userId)['path'] ?? null;

            $this->saveStatus($userId, [
                'status' => 'ready',
                'requested_at' => $this->status($userId)['requested_at'] ?? date('c'),
                'finished_at' => date('c'),
                'path' => $result['path'],
                'size' => is_file($result['path']) ? filesize($result['path']) : null,
                'error' => null,
            ]);

            if (\is_string($previous) && $previous !== $result['path'] && is_file($previous)) {
                @unlink($previous);
            }

            $this->auditLog->log(
                userId: $userId,
                action: 'export',
                entityType: 'Account',
                entityId: $userId,
                newValues: ['sites' => $result['sites'], 'tables' => \count($result['tables'])],
            );
        } catch (\Throwable $e) {
            $this->logger->error('Account export failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            $this->saveStatus($userId, [
                'status' => 'failed',
                'requested_at' => $this->status($userId)['requested_at'] ?? date('c'),
                'finished_at' => date('c'),
                'path' => null,
                'size' => null,
                'error' => 'Export failed — please try again or contact support.',
            ]);
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function status(int $userId): array
    {
        $raw = $this->db->fetchOne(
            "SELECT config_value FROM oci_configuration WHERE scope = 'user' AND scope_id = :uid AND config_key = :key",
            ['uid' => $userId, 'key' => self::CONFIG_KEY],
        );

        if ($raw === false || $raw === null) {
            return ['status' => 'none'];
        }

        $decoded = json_decode((string) $raw, true);

        return \is_array($decoded) ? $decoded : ['status' => 'none'];
    }

    /**
     * The zip path for a ready export, verified on disk — null otherwise.
     */
    public function readyPath(int $userId): ?string
    {
        $status = $this->status($userId);
        $path = $status['path'] ?? null;

        if (($status['status'] ?? '') !== 'ready' || !\is_string($path) || !is_file($path)) {
            return null;
        }

        return $path;
    }

    /**
     * @param array<string, mixed> $status
     */
    private function saveStatus(int $userId, array $status): void
    {
        $this->db->executeStatement(
            "INSERT INTO oci_configuration (scope, scope_id, config_key, config_value)
             VALUES ('user', :uid, :key, :value)
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)",
            ['uid' => $userId, 'key' => self::CONFIG_KEY, 'value' => json_encode($status, JSON_UNESCAPED_SLASHES)],
        );
    }

    private function exportDir(): string
    {
        return rtrim($this->basePath !== '' ? $this->basePath : getcwd(), '/\\') . '/var/exports';
    }
}
