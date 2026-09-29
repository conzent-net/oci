<?php

declare(strict_types=1);

namespace OCI\Notification\Repository;

interface NotificationReadRepositoryInterface
{
    /**
     * Get all read notification slugs for a user.
     *
     * @return list<string>
     */
    public function getReadSlugs(int $userId): array;

    /**
     * Mark a single notification as read (idempotent).
     */
    public function markRead(int $userId, string $slug): void;

    /**
     * Mark multiple notifications as read.
     *
     * @param list<string> $slugs
     */
    public function markAllRead(int $userId, array $slugs): void;
}
