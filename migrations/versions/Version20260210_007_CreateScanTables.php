<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Scanning domain: scans, scan cookies, reports, servers, beacons.
 */
final class Version20260210_007_CreateScanTables extends Migration
{
    public function getDescription(): string
    {
        return 'Create scan, scan_cookies, scan_reports, scan_servers, beacon tables';
    }

    public function up(): void
    {
        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_scans` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `legacy_id` INT UNSIGNED NULL DEFAULT NULL,
                `site_id` INT UNSIGNED NOT NULL,
                `scan_type` VARCHAR(30) NULL DEFAULT NULL,
                `scan_status` VARCHAR(30) NOT NULL DEFAULT 'pending',
                `setup_status` VARCHAR(30) NULL DEFAULT NULL,
                `is_scheduled` TINYINT(1) NOT NULL DEFAULT 0,
                `frequency` VARCHAR(30) NULL DEFAULT NULL,
                `schedule_date` DATE NULL DEFAULT NULL,
                `schedule_time` TIME NULL DEFAULT NULL,
                `request_path` VARCHAR(500) NULL DEFAULT NULL,
                `result_path` VARCHAR(500) NULL DEFAULT NULL,
                `firstparty_url` VARCHAR(500) NULL DEFAULT NULL,
                `include_urls` TEXT NULL DEFAULT NULL,
                `exclude_urls` TEXT NULL DEFAULT NULL,
                `total_categories` INT UNSIGNED NOT NULL DEFAULT 0,
                `total_cookies` INT UNSIGNED NOT NULL DEFAULT 0,
                `total_pages` INT UNSIGNED NOT NULL DEFAULT 0,
                `total_scripts` INT UNSIGNED NOT NULL DEFAULT 0,
                `scan_location` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `is_first_scan` TINYINT(1) NOT NULL DEFAULT 0,
                `is_monthly_scan` TINYINT(1) NOT NULL DEFAULT 0,
                `report_sent` TINYINT(1) NOT NULL DEFAULT 0,
                `scan_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `started_at` DATETIME NULL DEFAULT NULL,
                `completed_at` DATETIME NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_oci_scan_legacy` (`legacy_id`),
                INDEX `idx_oci_scan_site` (`site_id`),
                INDEX `idx_oci_scan_status` (`scan_status`),
                INDEX `idx_oci_scan_created` (`created_at`),
                CONSTRAINT `fk_oci_scan_site` FOREIGN KEY (`site_id`) REFERENCES `oci_sites` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_scan_cookies` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `scan_id` INT UNSIGNED NOT NULL,
                `cookie_name` VARCHAR(255) NOT NULL,
                `cookie_domain` VARCHAR(255) NULL DEFAULT NULL,
                `category_slug` VARCHAR(60) NULL DEFAULT NULL,
                `expiry_duration` VARCHAR(100) NULL DEFAULT NULL,
                `http_only` TINYINT(1) NOT NULL DEFAULT 0,
                `secure` TINYINT(1) NOT NULL DEFAULT 0,
                `same_site` VARCHAR(20) NULL DEFAULT NULL,
                `found_on_url` VARCHAR(2048) NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                INDEX `idx_oci_scancook_scan` (`scan_id`),
                CONSTRAINT `fk_oci_scancook_scan` FOREIGN KEY (`scan_id`) REFERENCES `oci_scans` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_scan_reports` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `scan_id` INT UNSIGNED NOT NULL,
                `report_type` VARCHAR(30) NOT NULL DEFAULT 'full',
                `report_data` LONGTEXT NULL DEFAULT NULL COMMENT 'JSON',
                `file_path` VARCHAR(500) NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_oci_scanrpt_scan` (`scan_id`),
                CONSTRAINT `fk_oci_scanrpt_scan` FOREIGN KEY (`scan_id`) REFERENCES `oci_scans` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_scan_servers` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `server_name` VARCHAR(100) NOT NULL,
                `server_url` VARCHAR(500) NOT NULL,
                `api_key` VARCHAR(255) NULL DEFAULT NULL,
                `region` VARCHAR(40) NULL DEFAULT NULL,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `max_concurrent` SMALLINT UNSIGNED NOT NULL DEFAULT 5,
                `current_load` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `last_heartbeat_at` DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_beacons` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `site_id` INT UNSIGNED NOT NULL,
                `beacon_url` VARCHAR(2048) NOT NULL,
                `beacon_type` VARCHAR(30) NULL DEFAULT NULL,
                `last_seen_at` DATETIME NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_oci_beacon_site` (`site_id`),
                CONSTRAINT `fk_oci_beacon_site` FOREIGN KEY (`site_id`) REFERENCES `oci_sites` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_beacon_scans` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `beacon_id` INT UNSIGNED NOT NULL,
                `scan_id` INT UNSIGNED NOT NULL,
                `found_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_oci_bscan_beacon` (`beacon_id`),
                INDEX `idx_oci_bscan_scan` (`scan_id`),
                CONSTRAINT `fk_oci_bscan_beacon` FOREIGN KEY (`beacon_id`) REFERENCES `oci_beacons` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_oci_bscan_scan` FOREIGN KEY (`scan_id`) REFERENCES `oci_scans` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropIfExists('oci_beacon_scans');
        $this->dropIfExists('oci_beacons');
        $this->dropIfExists('oci_scan_servers');
        $this->dropIfExists('oci_scan_reports');
        $this->dropIfExists('oci_scan_cookies');
        $this->dropIfExists('oci_scans');
    }
}
