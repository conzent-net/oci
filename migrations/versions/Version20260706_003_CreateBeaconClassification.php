<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Beacon classification: a Conzent-controlled global beacon reference database
 * (domain -> category) and a reclassification-request queue, mirroring the
 * cookie classification model. Beacons reuse oci_cookie_categories.
 */
final class Version20260706_003_CreateBeaconClassification extends Migration
{
    public function getDescription(): string
    {
        return 'Create oci_beacons_global and oci_beacon_reclass_requests';
    }

    public function up(): void
    {
        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_beacons_global` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `domain` VARCHAR(255) NOT NULL,
                `category_id` INT UNSIGNED NULL DEFAULT NULL,
                `platform` VARCHAR(200) NULL DEFAULT NULL,
                `description` TEXT NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_oci_beacon_global_domain` (`domain`),
                INDEX `idx_oci_beacon_global_cat` (`category_id`),
                CONSTRAINT `fk_oci_beacon_global_cat` FOREIGN KEY (`category_id`) REFERENCES `oci_cookie_categories` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_beacon_reclass_requests` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `site_id` INT UNSIGNED NOT NULL,
                `user_id` INT UNSIGNED NOT NULL,
                `beacon_domain` VARCHAR(255) NOT NULL,
                `current_category_slug` VARCHAR(60) NULL DEFAULT NULL COMMENT 'snapshot of the current classification',
                `requested_category_id` INT UNSIGNED NOT NULL,
                `reason` TEXT NULL DEFAULT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending, approved, rejected',
                `review_note` TEXT NULL DEFAULT NULL,
                `reviewed_by` INT UNSIGNED NULL DEFAULT NULL,
                `reviewed_at` DATETIME NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_oci_breclass_status` (`status`),
                INDEX `idx_oci_breclass_site` (`site_id`),
                CONSTRAINT `fk_oci_breclass_site` FOREIGN KEY (`site_id`) REFERENCES `oci_sites` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_oci_breclass_user` FOREIGN KEY (`user_id`) REFERENCES `oci_users` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_oci_breclass_cat` FOREIGN KEY (`requested_category_id`) REFERENCES `oci_cookie_categories` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropIfExists('oci_beacon_reclass_requests');
        $this->dropIfExists('oci_beacons_global');
    }
}
