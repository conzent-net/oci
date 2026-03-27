<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Consent domain: consents, consent categories, consent cookies, pageviews, geolocation.
 */
final class Version20260210_005_CreateConsentTables extends Migration
{
    public function getDescription(): string
    {
        return 'Create consent, consent_categories, consent_cookies, pageviews, geolocation tables';
    }

    public function up(): void
    {
        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_consents` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `legacy_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                `site_id` INT UNSIGNED NOT NULL,
                `consent_session` VARCHAR(64) NOT NULL DEFAULT '',
                `consented_domain` VARCHAR(255) NOT NULL DEFAULT '',
                `ip_address` VARCHAR(45) NOT NULL DEFAULT '0.0.0.0',
                `country` VARCHAR(5) NULL DEFAULT NULL,
                `consent_status` VARCHAR(40) NOT NULL DEFAULT 'unknown',
                `language` VARCHAR(10) NULL DEFAULT NULL,
                `tcf_data` TEXT NULL DEFAULT NULL,
                `gacm_data` TEXT NULL DEFAULT NULL,
                `consent_date` DATETIME NOT NULL,
                `last_renewed_at` DATETIME NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_oci_consent_legacy` (`legacy_id`),
                INDEX `idx_oci_consent_site` (`site_id`),
                INDEX `idx_oci_consent_session` (`consent_session`),
                INDEX `idx_oci_consent_date` (`consent_date`),
                CONSTRAINT `fk_oci_consent_site` FOREIGN KEY (`site_id`) REFERENCES `oci_sites` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_consent_categories` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `consent_id` BIGINT UNSIGNED NOT NULL,
                `category_slug` VARCHAR(60) NOT NULL,
                `consent_status` VARCHAR(20) NOT NULL DEFAULT 'unknown',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_oci_ccat_consent` (`consent_id`),
                CONSTRAINT `fk_oci_ccat_consent` FOREIGN KEY (`consent_id`) REFERENCES `oci_consents` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_consent_cookies` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `consent_id` BIGINT UNSIGNED NOT NULL,
                `cookie_name` VARCHAR(255) NOT NULL,
                `cookie_domain` VARCHAR(255) NULL DEFAULT NULL,
                `consent_status` VARCHAR(20) NOT NULL DEFAULT 'unknown',
                PRIMARY KEY (`id`),
                INDEX `idx_oci_ccookie_consent` (`consent_id`),
                CONSTRAINT `fk_oci_ccookie_consent` FOREIGN KEY (`consent_id`) REFERENCES `oci_consents` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_site_pageviews` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `site_id` INT UNSIGNED NOT NULL,
                `period_date` DATE NOT NULL,
                `pageview_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `unique_visitors` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_oci_pv` (`site_id`, `period_date`),
                CONSTRAINT `fk_oci_pv_site` FOREIGN KEY (`site_id`) REFERENCES `oci_sites` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_ip_geolocation` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `ip_address` VARCHAR(45) NOT NULL,
                `country_code` VARCHAR(5) NULL DEFAULT NULL,
                `region` VARCHAR(100) NULL DEFAULT NULL,
                `looked_up_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_oci_geo_ip` (`ip_address`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropIfExists('oci_ip_geolocation');
        $this->dropIfExists('oci_site_pageviews');
        $this->dropIfExists('oci_consent_cookies');
        $this->dropIfExists('oci_consent_categories');
        $this->dropIfExists('oci_consents');
    }
}
