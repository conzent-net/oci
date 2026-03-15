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
     * Drop a table if it exists.
     */
    protected function dropIfExists(string $table): void
    {
        $this->sql("DROP TABLE IF EXISTS `{$table}`");
    }
}
