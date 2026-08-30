<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Create the oci_site_wizards table for tracking site setup wizard progress.
 */
final class Version20260211_001_CreateSiteWizardsTable extends Migration
{
    public function getDescription(): string
    {
        return 'Create oci_site_wizards table';
    }

    public function up(): void
    {
        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_site_wizards` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `site_id` INT UNSIGNED NOT NULL,
                `user_id` INT UNSIGNED NOT NULL,
                `languages` TEXT NULL DEFAULT NULL COMMENT 'JSON array of language codes',
                `banner_type` VARCHAR(30) NULL DEFAULT NULL,
                `ads_type` TINYINT(1) NOT NULL DEFAULT 0,
                `ad_options` TEXT NULL DEFAULT NULL COMMENT 'JSON',
                `status` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `last_step` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_oci_wizard_site` (`site_id`),
                INDEX `idx_oci_wizard_user` (`user_id`),
                CONSTRAINT `fk_oci_wizard_site` FOREIGN KEY (`site_id`) REFERENCES `oci_sites` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_oci_wizard_user` FOREIGN KEY (`user_id`) REFERENCES `oci_users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropIfExists('oci_site_wizards');
    }
}
