<?php

declare(strict_types=1);

namespace OCI\Infrastructure\Database;

use Doctrine\DBAL\Connection;

/**
 * Lightweight migration base class using pure DBAL.
 *
 * No dependency on Doctrine Migrations bundle (avoids symfony/var-exporter issue).
 * Each migration implements up() and down() with raw SQL via $this->db.
 */
abstract class Migration
{
    public function __construct(
        protected readonly Connection $db,
    ) {}

    abstract public function getDescription(): string;

    abstract public function up(): void;

    abstract public function down(): void;

    /**
     * Execute a SQL statement.
     */
    protected function sql(string $sql): void
    {
        $this->db->executeStatement($sql);
    }

    /**
     * Check if a table exists in the current database.
     */
    protected function tableExists(string $table): bool
    {
        return (bool) $this->db->fetchOne(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$table],
        );
    }

    /**
     * Drop a table if it exists.
     */
    protected function dropIfExists(string $table): void
    {
        $this->sql("DROP TABLE IF EXISTS `{$table}`");
    }
}
