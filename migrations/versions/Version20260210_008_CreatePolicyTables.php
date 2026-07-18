<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Policy domain: cookie policies, privacy policies, templates.
 */
final class Version20260210_008_CreatePolicyTables extends Migration
{
    public function getDescription(): string
    {
        return 'Create cookie_policies, privacy_policies, and policy template tables';
    }

    public function up(): void
    {
        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_cookie_policies` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `site_id` INT UNSIGNED NOT NULL,
                `language_id` INT UNSIGNED NOT NULL,
                `heading` VARCHAR(300) NULL DEFAULT NULL,
                `type_heading` VARCHAR(300) NULL DEFAULT NULL,
                `url_key` VARCHAR(255) NULL DEFAULT NULL,
                `preference_heading` VARCHAR(300) NULL DEFAULT NULL,
                `preference_description` TEXT NULL DEFAULT NULL,
                `revisit_consent_widget` TEXT NULL DEFAULT NULL,
                `policy_content` LONGTEXT NULL DEFAULT NULL,
                `show_audit_table` TINYINT(1) NOT NULL DEFAULT 0,
                `effective_date` DATE NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_oci_cpolicy` (`site_id`, `language_id`),
                CONSTRAINT `fk_oci_cpolicy_site` FOREIGN KEY (`site_id`) REFERENCES `oci_sites` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_oci_cpolicy_lang` FOREIGN KEY (`language_id`) REFERENCES `oci_languages` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_privacy_policies` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `site_id` INT UNSIGNED NOT NULL,
                `language_id` INT UNSIGNED NOT NULL,
                `heading` VARCHAR(300) NULL DEFAULT NULL,
                `url_key` VARCHAR(255) NULL DEFAULT NULL,
                `step_data` LONGTEXT NULL DEFAULT NULL COMMENT 'JSON wizard steps',
                `policy_content` LONGTEXT NULL DEFAULT NULL,
                `effective_date` DATE NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_oci_ppolicy` (`site_id`, `language_id`),
                CONSTRAINT `fk_oci_ppolicy_site` FOREIGN KEY (`site_id`) REFERENCES `oci_sites` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_oci_ppolicy_lang` FOREIGN KEY (`language_id`) REFERENCES `oci_languages` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_cookie_policy_templates` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `template_name` VARCHAR(200) NOT NULL,
                `language_id` INT UNSIGNED NOT NULL,
                `content` LONGTEXT NOT NULL,
                `is_default` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_oci_cptpl_lang` (`language_id`),
                CONSTRAINT `fk_oci_cptpl_lang` FOREIGN KEY (`language_id`) REFERENCES `oci_languages` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_privacy_policy_templates` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `template_name` VARCHAR(200) NOT NULL,
                `language_id` INT UNSIGNED NOT NULL,
                `content` LONGTEXT NOT NULL,
                `is_default` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_oci_pptpl_lang` (`language_id`),
                CONSTRAINT `fk_oci_pptpl_lang` FOREIGN KEY (`language_id`) REFERENCES `oci_languages` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropIfExists('oci_privacy_policy_templates');
        $this->dropIfExists('oci_cookie_policy_templates');
        $this->dropIfExists('oci_privacy_policies');
        $this->dropIfExists('oci_cookie_policies');
    }
}
