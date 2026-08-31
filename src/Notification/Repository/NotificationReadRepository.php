<?php

declare(strict_types=1);

namespace OCI\Notification\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class NotificationReadRepository implements NotificationReadRepositoryInterface
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    public function getReadSlugs(int $userId): array
    {
        return $this->db->fetchFirstColumn(
            'SELECT notification_slug FROM oci_notification_reads WHERE user_id = :userId',
            ['userId' => $userId],
            ['userId' => ParameterType::INTEGER],
        );
    }

    public function markRead(int $userId, string $slug): void
    {
        $this->db->executeStatement(
            'INSERT IGNORE INTO oci_notification_reads (user_id, notification_slug, read_at)
             VALUES (:userId, :slug, NOW())',
            ['userId' => $userId, 'slug' => $slug],
            ['userId' => ParameterType::INTEGER],
        );
    }

    public function markAllRead(int $userId, array $slugs): void
    {
        foreach ($slugs as $slug) {
            $this->markRead($userId, $slug);
        }
    }
}
