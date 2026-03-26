<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Cookie domain: languages, categories, global cookies, site cookies, block providers.
 */
final class Version20260210_003_CreateCookieTables extends Migration
{
    public function getDescription(): string
    {
        return 'Create language, cookie category, global/site cookie, and block-provider tables';
    }

    public function up(): void
    {
        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_languages` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `lang_code` VARCHAR(10) NOT NULL,
                `lang_name` VARCHAR(60) NOT NULL,
                `is_default` TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_oci_languages_code` (`lang_code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_cookie_categories` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `legacy_id` INT UNSIGNED NULL DEFAULT NULL,
                `slug` VARCHAR(60) NOT NULL,
                `type` TINYINT UNSIGNED NOT NULL DEFAULT 2 COMMENT '1=strictly-necessary, 2=functional, 3=analytics, 4=marketing',
                `sort_order` SMALLINT NOT NULL DEFAULT 0,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `default_consent` VARCHAR(20) NULL DEFAULT NULL COMMENT 'accepted, rejected, null=ask',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_oci_cat_slug` (`slug`),
                INDEX `idx_oci_cat_legacy` (`legacy_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_cookie_category_translations` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `category_id` INT UNSIGNED NOT NULL,
                `language_id` INT UNSIGNED NOT NULL,
                `name` VARCHAR(200) NOT NULL,
                `description` TEXT NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_oci_cat_trans` (`category_id`, `language_id`),
                CONSTRAINT `fk_oci_cattrans_cat` FOREIGN KEY (`category_id`) REFERENCES `oci_cookie_categories` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_oci_cattrans_lang` FOREIGN KEY (`language_id`) REFERENCES `oci_languages` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_cookies_global` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `legacy_id` INT UNSIGNED NULL DEFAULT NULL,
                `platform` VARCHAR(200) NULL DEFAULT NULL,
                `category_id` INT UNSIGNED NULL DEFAULT NULL,
                `cookie_name` VARCHAR(255) NOT NULL,
                `cookie_id` VARCHAR(100) NULL DEFAULT NULL,
                `domain` VARCHAR(255) NULL DEFAULT NULL,
                `description` TEXT NULL DEFAULT NULL,
                `expiry_duration` VARCHAR(100) NULL DEFAULT NULL,
                `data_controller` VARCHAR(255) NULL DEFAULT NULL,
                `privacy_url` VARCHAR(500) NULL DEFAULT NULL,
                `wildcard_match` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_oci_gcookie_legacy` (`legacy_id`),
                INDEX `idx_oci_gcookie_name` (`cookie_name`),
                INDEX `idx_oci_gcookie_cat` (`category_id`),
                CONSTRAINT `fk_oci_gcookie_cat` FOREIGN KEY (`category_id`) REFERENCES `oci_cookie_categories` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_cookies_global_translations` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `cookie_id` INT UNSIGNED NOT NULL,
                `language_id` INT UNSIGNED NOT NULL,
                `description` TEXT NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_oci_gcookie_trans` (`cookie_id`, `language_id`),
                CONSTRAINT `fk_oci_gcooktrans_cookie` FOREIGN KEY (`cookie_id`) REFERENCES `oci_cookies_global` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_oci_gcooktrans_lang` FOREIGN KEY (`language_id`) REFERENCES `oci_languages` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_site_cookies` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `site_id` INT UNSIGNED NOT NULL,
                `category_id` INT UNSIGNED NULL DEFAULT NULL,
                `cookie_name` VARCHAR(255) NOT NULL,
                `cookie_domain` VARCHAR(255) NULL DEFAULT NULL,
                `default_duration` VARCHAR(100) NULL DEFAULT NULL,
                `script_url_pattern` VARCHAR(500) NULL DEFAULT NULL,
                `from_scan` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_oci_scookie_site` (`site_id`),
                INDEX `idx_oci_scookie_cat` (`category_id`),
                INDEX `idx_oci_scookie_name` (`cookie_name`),
                CONSTRAINT `fk_oci_scookie_site` FOREIGN KEY (`site_id`) REFERENCES `oci_sites` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_oci_scookie_cat` FOREIGN KEY (`category_id`) REFERENCES `oci_cookie_categories` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_site_cookie_translations` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `site_cookie_id` INT UNSIGNED NOT NULL,
                `language_id` INT UNSIGNED NOT NULL,
                `description` TEXT NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_oci_scookie_trans` (`site_cookie_id`, `language_id`),
                CONSTRAINT `fk_oci_scooktrans_cookie` FOREIGN KEY (`site_cookie_id`) REFERENCES `oci_site_cookies` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_oci_scooktrans_lang` FOREIGN KEY (`language_id`) REFERENCES `oci_languages` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_site_cookie_categories` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `site_id` INT UNSIGNED NOT NULL,
                `category_id` INT UNSIGNED NOT NULL,
                `sort_order` SMALLINT NOT NULL DEFAULT 0,
                `default_consent` VARCHAR(20) NULL DEFAULT NULL,
                `custom_slug` VARCHAR(60) NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_oci_sitecat` (`site_id`, `category_id`),
                CONSTRAINT `fk_oci_sitecat_site` FOREIGN KEY (`site_id`) REFERENCES `oci_sites` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_oci_sitecat_cat` FOREIGN KEY (`category_id`) REFERENCES `oci_cookie_categories` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_site_cookie_category_translations` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `site_cookie_category_id` INT UNSIGNED NOT NULL,
                `language_id` INT UNSIGNED NOT NULL,
                `name` VARCHAR(200) NOT NULL,
                `description` TEXT NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_oci_sitecattrans` (`site_cookie_category_id`, `language_id`),
                CONSTRAINT `fk_oci_sitecattrans_scc` FOREIGN KEY (`site_cookie_category_id`) REFERENCES `oci_site_cookie_categories` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_oci_sitecattrans_lang` FOREIGN KEY (`language_id`) REFERENCES `oci_languages` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_site_languages` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `site_id` INT UNSIGNED NOT NULL,
                `language_id` INT UNSIGNED NOT NULL,
                `is_default` TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_oci_sitelang` (`site_id`, `language_id`),
                CONSTRAINT `fk_oci_sitelang_site` FOREIGN KEY (`site_id`) REFERENCES `oci_sites` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_oci_sitelang_lang` FOREIGN KEY (`language_id`) REFERENCES `oci_languages` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_block_providers` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `provider_name` VARCHAR(200) NOT NULL,
                `provider_url` VARCHAR(500) NULL DEFAULT NULL,
                `description` TEXT NULL DEFAULT NULL,
                `default_action` VARCHAR(20) NOT NULL DEFAULT 'block',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_oci_block_provider` (`provider_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_site_block_providers` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `site_id` INT UNSIGNED NOT NULL,
                `provider_id` INT UNSIGNED NOT NULL,
                `action` VARCHAR(20) NOT NULL DEFAULT 'block',
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_oci_site_block` (`site_id`, `provider_id`),
                CONSTRAINT `fk_oci_siteblock_site` FOREIGN KEY (`site_id`) REFERENCES `oci_sites` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_oci_siteblock_prov` FOREIGN KEY (`provider_id`) REFERENCES `oci_block_providers` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropIfExists('oci_site_block_providers');
        $this->dropIfExists('oci_block_providers');
        $this->dropIfExists('oci_site_languages');
        $this->dropIfExists('oci_site_cookie_category_translations');
        $this->dropIfExists('oci_site_cookie_categories');
        $this->dropIfExists('oci_site_cookie_translations');
        $this->dropIfExists('oci_site_cookies');
        $this->dropIfExists('oci_cookies_global_translations');
        $this->dropIfExists('oci_cookies_global');
        $this->dropIfExists('oci_cookie_category_translations');
        $this->dropIfExists('oci_cookie_categories');
        $this->dropIfExists('oci_languages');
    }
}
