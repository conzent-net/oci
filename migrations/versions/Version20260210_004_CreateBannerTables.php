<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Banner domain: templates, layouts, fields, site banners, settings.
 */
final class Version20260210_004_CreateBannerTables extends Migration
{
    public function getDescription(): string
    {
        return 'Create banner template, layout, field, site banner tables';
    }

    public function up(): void
    {
        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_banner_templates` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `legacy_id` INT UNSIGNED NULL DEFAULT NULL,
                `banner_name` VARCHAR(200) NOT NULL,
                `banner_slug` VARCHAR(100) NOT NULL,
                `custom_css` TEXT NULL DEFAULT NULL,
                `is_default` TINYINT(1) NOT NULL DEFAULT 0,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `cookie_laws` VARCHAR(100) NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_oci_banner_slug` (`banner_slug`),
                INDEX `idx_oci_banner_legacy` (`legacy_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_banner_layouts` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `template_id` INT UNSIGNED NOT NULL,
                `layout_key` VARCHAR(60) NOT NULL,
                `layout_name` VARCHAR(200) NOT NULL,
                `position` VARCHAR(40) NOT NULL DEFAULT 'bottom' COMMENT 'bottom, top, center, left, right',
                `html_structure` TEXT NULL DEFAULT NULL,
                `default_css` TEXT NULL DEFAULT NULL,
                `sort_order` SMALLINT NOT NULL DEFAULT 0,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                PRIMARY KEY (`id`),
                INDEX `idx_oci_layout_tpl` (`template_id`),
                CONSTRAINT `fk_oci_layout_tpl` FOREIGN KEY (`template_id`) REFERENCES `oci_banner_templates` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_banner_field_categories` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `template_id` INT UNSIGNED NOT NULL,
                `category_key` VARCHAR(60) NOT NULL,
                `category_name` VARCHAR(200) NOT NULL,
                `sort_order` SMALLINT NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                INDEX `idx_oci_bfc_tpl` (`template_id`),
                CONSTRAINT `fk_oci_bfc_tpl` FOREIGN KEY (`template_id`) REFERENCES `oci_banner_templates` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_banner_fields` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `field_category_id` INT UNSIGNED NOT NULL,
                `field_key` VARCHAR(80) NOT NULL,
                `field_type` VARCHAR(30) NOT NULL DEFAULT 'text' COMMENT 'text, textarea, color, select, toggle',
                `default_value` TEXT NULL DEFAULT NULL,
                `options` TEXT NULL DEFAULT NULL COMMENT 'JSON for selects',
                `sort_order` SMALLINT NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                INDEX `idx_oci_bf_cat` (`field_category_id`),
                CONSTRAINT `fk_oci_bf_cat` FOREIGN KEY (`field_category_id`) REFERENCES `oci_banner_field_categories` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_banner_field_translations` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `field_id` INT UNSIGNED NOT NULL,
                `language_id` INT UNSIGNED NOT NULL,
                `label` VARCHAR(200) NOT NULL,
                `placeholder` VARCHAR(300) NULL DEFAULT NULL,
                `help_text` TEXT NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_oci_bft` (`field_id`, `language_id`),
                CONSTRAINT `fk_oci_bft_field` FOREIGN KEY (`field_id`) REFERENCES `oci_banner_fields` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_oci_bft_lang` FOREIGN KEY (`language_id`) REFERENCES `oci_languages` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_site_banners` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `site_id` INT UNSIGNED NOT NULL,
                `banner_template_id` INT UNSIGNED NULL DEFAULT NULL,
                `custom_css` TEXT NULL DEFAULT NULL,
                `consent_template` TEXT NULL DEFAULT NULL,
                `general_setting` LONGTEXT NULL DEFAULT NULL COMMENT 'JSON',
                `layout_setting` LONGTEXT NULL DEFAULT NULL COMMENT 'JSON',
                `content_setting` LONGTEXT NULL DEFAULT NULL COMMENT 'JSON',
                `color_setting` LONGTEXT NULL DEFAULT NULL COMMENT 'JSON',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_oci_sbanner_site` (`site_id`),
                CONSTRAINT `fk_oci_sbanner_site` FOREIGN KEY (`site_id`) REFERENCES `oci_sites` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_oci_sbanner_tpl` FOREIGN KEY (`banner_template_id`) REFERENCES `oci_banner_templates` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_site_banner_settings` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `site_banner_id` INT UNSIGNED NOT NULL,
                `setting_key` VARCHAR(100) NOT NULL,
                `setting_value` TEXT NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_oci_sbs` (`site_banner_id`, `setting_key`),
                CONSTRAINT `fk_oci_sbs_banner` FOREIGN KEY (`site_banner_id`) REFERENCES `oci_site_banners` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_site_banner_field_translations` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `site_banner_id` INT UNSIGNED NOT NULL,
                `field_id` INT UNSIGNED NOT NULL,
                `language_id` INT UNSIGNED NOT NULL,
                `value` TEXT NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_oci_sbft` (`site_banner_id`, `field_id`, `language_id`),
                CONSTRAINT `fk_oci_sbft_banner` FOREIGN KEY (`site_banner_id`) REFERENCES `oci_site_banners` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_oci_sbft_field` FOREIGN KEY (`field_id`) REFERENCES `oci_banner_fields` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_oci_sbft_lang` FOREIGN KEY (`language_id`) REFERENCES `oci_languages` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropIfExists('oci_site_banner_field_translations');
        $this->dropIfExists('oci_site_banner_settings');
        $this->dropIfExists('oci_site_banners');
        $this->dropIfExists('oci_banner_field_translations');
        $this->dropIfExists('oci_banner_fields');
        $this->dropIfExists('oci_banner_field_categories');
        $this->dropIfExists('oci_banner_layouts');
        $this->dropIfExists('oci_banner_templates');
    }
}
