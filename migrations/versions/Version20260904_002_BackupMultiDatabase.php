<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Record which databases an archive actually contains.
 *
 * The first cut dumped only the database in DATABASE_URL. An install can carry
 * more than one — the OCI schema plus, for example, a v1 consent store reached
 * through LEGACY_DATABASE_URL — and a backup that silently covers one of them
 * is the same class of half-truth this feature exists to remove.
 *
 * Storing the list per archive matters because it is the only way the restore
 * screen can tell you what you are about to get back BEFORE you commit to it,
 * and the only way an archive taken on one server can be honest about itself
 * when it lands on another.
 */
final class Version20260904_002_BackupMultiDatabase extends Migration
{
    public function getDescription(): string
    {
        return 'Record the database list and archive format on oci_backups';
    }

    public function up(): void
    {
        $this->sql("
            ALTER TABLE oci_backups
                ADD COLUMN database_names TEXT NULL DEFAULT NULL COMMENT 'json' AFTER table_count,
                ADD COLUMN format TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER database_names
        ");
    }

    public function down(): void
    {
        $this->sql('ALTER TABLE oci_backups DROP COLUMN database_names, DROP COLUMN format');
    }
}
