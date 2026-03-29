<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Create custom layouts table and add layout columns to site banners and A/B variants.
 *
 * oci_custom_layouts: User-editable banner layout templates, duplicated from system layouts.
 * oci_site_banners: Gets layout_key + custom_layout_id to reference the active layout.
 * oci_ab_variants: Gets layout_key + custom_layout_id so each variant can use a different layout.
 */
final class Version20260310_005_CreateCustomLayoutsTable extends Migration
{
    public function getDescription(): string
    {
        return 'Create oci_custom_layouts table and add layout columns to oci_site_banners and oci_ab_variants';
    }

    public function up(): void
    {
        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_custom_layouts` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `site_id` INT UNSIGNED NOT NULL,
                `base_layout_key` VARCHAR(50) NOT NULL
                    COMMENT 'Original system layout key, e.g. gdpr/classic',
                `layout_name` VARCHAR(200) NOT NULL,
                `html_content` LONGTEXT NOT NULL
                    COMMENT 'Twig template source',
                `custom_css` LONGTEXT NULL DEFAULT NULL,
                `cookie_laws` VARCHAR(20) NOT NULL DEFAULT 'gdpr',
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_cl_site` (`site_id`),
                CONSTRAINT `fk_cl_site` FOREIGN KEY (`site_id`)
                    REFERENCES `oci_sites` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            ALTER TABLE `oci_site_banners`
                ADD COLUMN `layout_key` VARCHAR(50) NULL DEFAULT 'gdpr/classic' AFTER `banner_template_id`,
                ADD COLUMN `custom_layout_id` INT UNSIGNED NULL DEFAULT NULL AFTER `layout_key`
        ");

        // oci_ab_variants only exists in cloud edition (A/B testing module)
        if ($this->tableExists('oci_ab_variants')) {
            $this->sql("
                ALTER TABLE `oci_ab_variants`
                    ADD COLUMN `layout_key` VARCHAR(50) NULL DEFAULT NULL AFTER `color_setting`,
                    ADD COLUMN `custom_layout_id` INT UNSIGNED NULL DEFAULT NULL AFTER `layout_key`
            ");
        }
    }

    public function down(): void
    {
        if ($this->tableExists('oci_ab_variants')) {
            $this->sql("ALTER TABLE `oci_ab_variants` DROP COLUMN `custom_layout_id`, DROP COLUMN `layout_key`");
        }
        $this->sql("ALTER TABLE `oci_site_banners` DROP COLUMN `custom_layout_id`, DROP COLUMN `layout_key`");
        $this->dropIfExists('oci_custom_layouts');
    }
}
