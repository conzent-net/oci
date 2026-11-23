<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Site domain: sites, associated domains, site URLs.
 */
final class Version20260210_002_CreateSitesTable extends Migration
{
    public function getDescription(): string
    {
        return 'Create oci_sites, oci_associated_sites, oci_site_urls tables';
    }

    public function up(): void
    {
        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_sites` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `legacy_id` INT UNSIGNED NULL DEFAULT NULL,
                `user_id` INT UNSIGNED NOT NULL,
                `site_name` VARCHAR(200) NULL DEFAULT NULL,
                `domain` VARCHAR(255) NOT NULL,
                `website_key` VARCHAR(40) NOT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active, inactive, suspended',
                `setup_status` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `consent_log_enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `consent_sharing_enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `gcm_enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `tag_fire_enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `cross_domain_enabled` TINYINT(1) NOT NULL DEFAULT 0,
                `block_iframe` TINYINT(1) NOT NULL DEFAULT 0,
                `debug_mode` TINYINT(1) NOT NULL DEFAULT 0,
                `display_banner_type` VARCHAR(30) NOT NULL DEFAULT 'gdpr' COMMENT 'gdpr, ccpa, gdpr_ccpa',
                `banner_delay_ms` INT UNSIGNED NOT NULL DEFAULT 2000,
                `include_all_languages` TINYINT(1) NOT NULL DEFAULT 1,
                `privacy_policy_url` VARCHAR(500) NULL DEFAULT NULL,
                `other_domains` TEXT NULL DEFAULT NULL COMMENT 'JSON array of additional domains',
                `disable_on_pages` TEXT NULL DEFAULT NULL COMMENT 'JSON array of page paths',
                `compliant_status` VARCHAR(30) NULL DEFAULT NULL,
                `gcm_config_status` TEXT NULL DEFAULT NULL,
                `template_applied` VARCHAR(60) NULL DEFAULT NULL,
                `site_logo` VARCHAR(500) NULL DEFAULT NULL,
                `icon_logo` VARCHAR(500) NULL DEFAULT NULL,
                `renew_user_consent_at` DATETIME NULL DEFAULT NULL,
                `last_banner_load_at` DATETIME NULL DEFAULT NULL,
                `site_updated` TINYINT(1) NOT NULL DEFAULT 0,
                `created_by` INT UNSIGNED NOT NULL,
                `deleted_at` DATETIME NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_oci_sites_key` (`website_key`),
                INDEX `idx_oci_sites_legacy` (`legacy_id`),
                INDEX `idx_oci_sites_user` (`user_id`),
                INDEX `idx_oci_sites_domain` (`domain`),
                INDEX `idx_oci_sites_status` (`status`),
                INDEX `idx_oci_sites_deleted` (`deleted_at`),
                CONSTRAINT `fk_oci_site_user` FOREIGN KEY (`user_id`) REFERENCES `oci_users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_associated_sites` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `site_id` INT UNSIGNED NOT NULL,
                `domain` VARCHAR(255) NOT NULL,
                `privacy_policy_url` VARCHAR(500) NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_oci_assoc_site` (`site_id`),
                CONSTRAINT `fk_oci_assoc_site` FOREIGN KEY (`site_id`) REFERENCES `oci_sites` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_site_urls` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `site_id` INT UNSIGNED NOT NULL,
                `url` VARCHAR(2048) NOT NULL,
                `title` VARCHAR(500) NULL DEFAULT NULL,
                `last_scanned_at` DATETIME NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_oci_site_urls_site` (`site_id`),
                CONSTRAINT `fk_oci_site_url` FOREIGN KEY (`site_id`) REFERENCES `oci_sites` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropIfExists('oci_site_urls');
        $this->dropIfExists('oci_associated_sites');
        $this->dropIfExists('oci_sites');
    }
}
