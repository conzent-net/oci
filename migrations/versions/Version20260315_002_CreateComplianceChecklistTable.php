<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Create table to store per-user compliance checklist progress.
 * One row per checked item — INSERT on check, DELETE on uncheck.
 */
final class Version20260315_002_CreateComplianceChecklistTable extends Migration
{
    public function getDescription(): string
    {
        return 'Create oci_user_checklist_items table for compliance checklist tracking';
    }

    public function up(): void
    {
        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_user_checklist_items` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `regulation_id` VARCHAR(50) NOT NULL COMMENT 'e.g. gdpr, ccpa, lgpd',
                `item_id` VARCHAR(50) NOT NULL COMMENT 'e.g. gdpr-001',
                `checked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_user_reg_item` (`user_id`, `regulation_id`, `item_id`),
                INDEX `idx_user_regulation` (`user_id`, `regulation_id`),
                CONSTRAINT `fk_checklist_user` FOREIGN KEY (`user_id`)
                    REFERENCES `oci_users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropIfExists('oci_user_checklist_items');
    }
}
