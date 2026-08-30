<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Privacy frameworks: per-site framework selection for multi-framework compliance.
 *
 * Creates junction table linking sites to privacy frameworks from config/privacy-frameworks.json.
 * Seeds existing sites based on their current display_banner_type value.
 */
final class Version20260324_001_CreateSitePrivacyFrameworks extends Migration
{
    public function getDescription(): string
    {
        return 'Create oci_site_privacy_frameworks table and seed from display_banner_type';
    }

    public function up(): void
    {
        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_site_privacy_frameworks` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `site_id` INT UNSIGNED NOT NULL,
                `framework_id` VARCHAR(40) NOT NULL COMMENT 'References privacy-frameworks.json id',
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_site_framework` (`site_id`, `framework_id`),
                INDEX `idx_framework` (`framework_id`),
                CONSTRAINT `fk_spf_site` FOREIGN KEY (`site_id`) REFERENCES `oci_sites` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Seed from existing display_banner_type values
        // gdpr → gdpr + eprivacy_directive
        $this->sql("
            INSERT IGNORE INTO `oci_site_privacy_frameworks` (`site_id`, `framework_id`)
            SELECT `id`, 'gdpr' FROM `oci_sites`
            WHERE `display_banner_type` IN ('gdpr', 'gdpr_ccpa')
              AND `deleted_at` IS NULL
        ");

        $this->sql("
            INSERT IGNORE INTO `oci_site_privacy_frameworks` (`site_id`, `framework_id`)
            SELECT `id`, 'eprivacy_directive' FROM `oci_sites`
            WHERE `display_banner_type` IN ('gdpr', 'gdpr_ccpa')
              AND `deleted_at` IS NULL
        ");

        // ccpa → ccpa_cpra
        $this->sql("
            INSERT IGNORE INTO `oci_site_privacy_frameworks` (`site_id`, `framework_id`)
            SELECT `id`, 'ccpa_cpra' FROM `oci_sites`
            WHERE `display_banner_type` IN ('ccpa', 'gdpr_ccpa')
              AND `deleted_at` IS NULL
        ");
    }

    public function down(): void
    {
        $this->dropIfExists('oci_site_privacy_frameworks');
    }
}
