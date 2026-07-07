<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Infrastructure: configuration, audit log, code snippets, mail log, legacy migration log.
 */
final class Version20260210_010_CreateInfrastructureTables extends Migration
{
    public function getDescription(): string
    {
        return 'Create configuration, audit_log, code_snippets, mail_log, legacy_migration_log tables';
    }

    public function up(): void
    {
        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_configuration` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `scope` VARCHAR(40) NOT NULL DEFAULT 'global' COMMENT 'global, site, user',
                `scope_id` INT UNSIGNED NULL DEFAULT NULL,
                `config_key` VARCHAR(100) NOT NULL,
                `config_value` TEXT NULL DEFAULT NULL,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_oci_config` (`scope`, `scope_id`, `config_key`),
                INDEX `idx_oci_config_key` (`config_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_audit_log` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NULL DEFAULT NULL,
                `action` VARCHAR(100) NOT NULL,
                `entity_type` VARCHAR(60) NOT NULL,
                `entity_id` INT UNSIGNED NULL DEFAULT NULL,
                `old_values` JSON NULL DEFAULT NULL,
                `new_values` JSON NULL DEFAULT NULL,
                `ip_address` VARCHAR(45) NULL DEFAULT NULL,
                `user_agent` VARCHAR(500) NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_oci_audit_user` (`user_id`),
                INDEX `idx_oci_audit_entity` (`entity_type`, `entity_id`),
                INDEX `idx_oci_audit_action` (`action`),
                INDEX `idx_oci_audit_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_code_snippets` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `site_id` INT UNSIGNED NOT NULL,
                `snippet_type` VARCHAR(30) NOT NULL DEFAULT 'header' COMMENT 'header, body_open, body_close',
                `code` TEXT NOT NULL,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `sort_order` SMALLINT NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_oci_snippet_site` (`site_id`),
                CONSTRAINT `fk_oci_snippet_site` FOREIGN KEY (`site_id`) REFERENCES `oci_sites` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_mail_log` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NULL DEFAULT NULL,
                `to_email` VARCHAR(255) NOT NULL,
                `subject` VARCHAR(500) NOT NULL,
                `template` VARCHAR(100) NULL DEFAULT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'sent',
                `error_message` TEXT NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_oci_mail_user` (`user_id`),
                INDEX `idx_oci_mail_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_legacy_migration_log` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `migration_name` VARCHAR(100) NOT NULL,
                `batch` INT UNSIGNED NOT NULL DEFAULT 1,
                `legacy_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `oci_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `skipped_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `error_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `errors` TEXT NULL DEFAULT NULL,
                `status` VARCHAR(30) NOT NULL DEFAULT 'completed',
                `started_at` DATETIME NOT NULL,
                `completed_at` DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                INDEX `idx_oci_miglog_name` (`migration_name`),
                INDEX `idx_oci_miglog_batch` (`batch`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropIfExists('oci_legacy_migration_log');
        $this->dropIfExists('oci_mail_log');
        $this->dropIfExists('oci_code_snippets');
        $this->dropIfExists('oci_audit_log');
        $this->dropIfExists('oci_configuration');
    }
}
