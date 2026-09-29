<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Backup run history and per-destination delivery state.
 *
 * Written after a restore drill on 2026-09-04 found three independent silent
 * backup failures, none visible from inside the product: a deploy script that
 * swallowed migration errors, a host backup script whose fatal file-rsync ran
 * before the database dumps, and a freshness monitor watching a heartbeat file
 * that nothing had ever written.
 *
 * The common cause was that every guarantee lived outside the app. These two
 * tables move it inside: oci_backups records every ATTEMPT — failures included,
 * which is the whole point — and oci_backup_destinations records what actually
 * arrived where, so the UI can say "this backup exists in three places" from
 * evidence rather than from assuming a write succeeded.
 */
final class Version20260904_001_CreateBackupTables extends Migration
{
    public function getDescription(): string
    {
        return 'Create oci_backups and oci_backup_destinations for in-app backup history';
    }

    public function up(): void
    {
        $this->sql("
            CREATE TABLE IF NOT EXISTS oci_backups (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                filename VARCHAR(255) NOT NULL,
                path VARCHAR(500) NOT NULL,
                trigger_type ENUM('scheduled','manual','pre_restore') NOT NULL DEFAULT 'manual',
                status ENUM('running','success','failed') NOT NULL DEFAULT 'running',
                includes_config TINYINT(1) NOT NULL DEFAULT 0,
                size_bytes BIGINT UNSIGNED NULL DEFAULT NULL,
                sha256 CHAR(64) NULL DEFAULT NULL,
                table_count INT UNSIGNED NULL DEFAULT NULL,
                migration_count INT UNSIGNED NULL DEFAULT NULL,
                started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                finished_at DATETIME NULL DEFAULT NULL,
                error TEXT NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_status_created (status, created_at),
                KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS oci_backup_destinations (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                backup_id INT UNSIGNED NOT NULL,
                destination ENUM('local','sftp','s3') NOT NULL,
                status ENUM('pending','uploaded','failed') NOT NULL DEFAULT 'pending',
                remote_path VARCHAR(500) NULL DEFAULT NULL,
                bytes_sent BIGINT UNSIGNED NULL DEFAULT NULL,
                duration_ms INT UNSIGNED NULL DEFAULT NULL,
                uploaded_at DATETIME NULL DEFAULT NULL,
                error TEXT NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_backup_destination (backup_id, destination),
                CONSTRAINT fk_backup_destination_backup
                    FOREIGN KEY (backup_id) REFERENCES oci_backups (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->sql('DROP TABLE IF EXISTS oci_backup_destinations');
        $this->sql('DROP TABLE IF EXISTS oci_backups');
    }
}
