<?php

declare(strict_types=1);

namespace OCI\Admin\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class InstallEventRepository implements InstallEventRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function insert(array $data): void
    {
        $this->db->insert('oci_install_events', [
            'event' => $data['event'],
            'ip_hash' => $data['ip_hash'] ?? null,
            'country' => $data['country'] ?? null,
            'version' => $data['version'] ?? null,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function getStats(): array
    {
        $row = $this->db->fetchAssociative("
            SELECT
                SUM(event = 'install') AS total_installs,
                SUM(event = 'update') AS total_updates,
                SUM(event = 'install' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS installs_30d,
                SUM(event = 'update' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS updates_30d
            FROM oci_install_events
        ");

        return [
            'total_installs' => (int) ($row['total_installs'] ?? 0),
            'total_updates' => (int) ($row['total_updates'] ?? 0),
            'installs_30d' => (int) ($row['installs_30d'] ?? 0),
            'updates_30d' => (int) ($row['updates_30d'] ?? 0),
        ];
    }

    public function getDailyStats(int $days = 30): array
    {
        return $this->db->fetchAllAssociative("
            SELECT
                DATE(created_at) AS date,
                SUM(event = 'install') AS installs,
                SUM(event = 'update') AS updates
            FROM oci_install_events
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ", ['days' => $days], ['days' => ParameterType::INTEGER]);
    }
}
