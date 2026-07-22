<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Create tables for the Report domain:
 * - oci_reports: Generated report instances (immutable once created)
 * - oci_report_schedules: Controls when the next report should be generated
 */
final class Version20260314_001_CreateReportTables extends Migration
{
    public function getDescription(): string
    {
        return 'Create report and report schedule tables';
    }

    public function up(): void
    {
        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_reports` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `site_id` INT UNSIGNED NOT NULL,
                `user_id` INT UNSIGNED NOT NULL,
                `report_type` VARCHAR(30) NOT NULL COMMENT 'consent, scan, full',
                `title` VARCHAR(255) NOT NULL,
                `period_start` DATE NOT NULL,
                `period_end` DATE NOT NULL,
                `report_data` LONGTEXT NULL COMMENT 'json',
                `report_html` LONGTEXT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'generated' COMMENT 'generated, sent, failed',
                `last_sent_at` DATETIME NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_report_site` (`site_id`),
                INDEX `idx_report_user` (`user_id`),
                INDEX `idx_report_type` (`report_type`),
                CONSTRAINT `fk_report_site` FOREIGN KEY (`site_id`)
                    REFERENCES `oci_sites` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_report_user` FOREIGN KEY (`user_id`)
                    REFERENCES `oci_users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_report_schedules` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `site_id` INT UNSIGNED NOT NULL,
                `user_id` INT UNSIGNED NOT NULL,
                `report_type` VARCHAR(30) NOT NULL DEFAULT 'full',
                `frequency` VARCHAR(20) NOT NULL DEFAULT 'monthly',
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `next_run_at` DATETIME NOT NULL,
                `last_run_at` DATETIME NULL,
                `email_to` VARCHAR(255) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_schedule_site_type` (`site_id`, `report_type`),
                INDEX `idx_schedule_due` (`is_active`, `next_run_at`),
                CONSTRAINT `fk_schedule_site` FOREIGN KEY (`site_id`)
                    REFERENCES `oci_sites` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_schedule_user` FOREIGN KEY (`user_id`)
                    REFERENCES `oci_users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropIfExists('oci_report_schedules');
        $this->dropIfExists('oci_reports');
    }
}
