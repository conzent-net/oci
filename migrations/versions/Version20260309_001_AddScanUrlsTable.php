<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Add oci_scan_urls table for per-URL tracking during multi-page scans.
 *
 * The legacy system overloaded cookies_scan_report for this purpose.
 * This table cleanly tracks each URL's scan progress independently.
 */
final class Version20260309_001_AddScanUrlsTable extends Migration
{
    public function getDescription(): string
    {
        return 'Add oci_scan_urls table for per-URL scan tracking';
    }

    public function up(): void
    {
        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_scan_urls` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `scan_id` INT UNSIGNED NOT NULL,
                `url` VARCHAR(2048) NOT NULL,
                `status` VARCHAR(30) NOT NULL DEFAULT 'pending'
                    COMMENT 'pending, scanning, completed, failed',
                `cookies_found` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `beacons_found` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `error_message` VARCHAR(500) NULL DEFAULT NULL,
                `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `scanned_at` DATETIME NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_oci_scanurl_scan` (`scan_id`),
                INDEX `idx_oci_scanurl_status` (`status`),
                CONSTRAINT `fk_oci_scanurl_scan` FOREIGN KEY (`scan_id`)
                    REFERENCES `oci_scans` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Add server_id FK to oci_scans for cleaner server tracking
        $this->sql("
            ALTER TABLE `oci_scans`
                ADD COLUMN `server_id` INT UNSIGNED NULL DEFAULT NULL AFTER `scan_location`,
                ADD COLUMN `callback_received` TINYINT(1) NOT NULL DEFAULT 0 AFTER `report_sent`,
                ADD INDEX `idx_oci_scan_server` (`server_id`)
        ");
    }

    public function down(): void
    {
        $this->sql("ALTER TABLE `oci_scans` DROP COLUMN `server_id`, DROP COLUMN `callback_received`, DROP INDEX `idx_oci_scan_server`");
        $this->dropIfExists('oci_scan_urls');
    }
}
