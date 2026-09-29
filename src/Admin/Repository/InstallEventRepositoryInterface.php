<?php

declare(strict_types=1);

namespace OCI\Admin\Repository;

interface InstallEventRepositoryInterface
{
    /**
     * Record an install or update ping.
     *
     * @param array<string, mixed> $data  Keys: event, ip_hash, country, version
     */
    public function insert(array $data): void;

    /**
     * Get aggregate counts for the admin dashboard.
     *
     * @return array{total_installs: int, total_updates: int, installs_30d: int, updates_30d: int}
     */
    public function getStats(): array;

    /**
     * Daily event counts for the last N days.
     *
     * @return list<array{date: string, installs: int, updates: int}>
     */
    public function getDailyStats(int $days = 30): array;
}
