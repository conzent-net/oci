<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Add user_id and structured columns to policy template tables
 * so templates can store full policy data (not just raw content).
 */
final class Version20260313_001_AlterPolicyTemplateTables extends Migration
{
    public function getDescription(): string
    {
        return 'Add user_id and policy fields to cookie/privacy policy template tables';
    }

    public function up(): void
    {
        // Cookie policy templates — add user ownership + all policy fields
        $this->sql("
            ALTER TABLE `oci_cookie_policy_templates`
                ADD COLUMN `user_id` INT UNSIGNED NULL DEFAULT NULL AFTER `id`,
                ADD COLUMN `heading` VARCHAR(300) NULL DEFAULT NULL AFTER `template_name`,
                ADD COLUMN `type_heading` VARCHAR(300) NULL DEFAULT NULL AFTER `heading`,
                ADD COLUMN `preference_heading` VARCHAR(300) NULL DEFAULT NULL AFTER `type_heading`,
                ADD COLUMN `preference_description` TEXT NULL DEFAULT NULL AFTER `preference_heading`,
                ADD COLUMN `revisit_consent_widget` TEXT NULL DEFAULT NULL AFTER `preference_description`,
                ADD COLUMN `show_audit_table` TINYINT(1) NOT NULL DEFAULT 0 AFTER `revisit_consent_widget`,
                ADD COLUMN `effective_date` DATE NULL DEFAULT NULL AFTER `show_audit_table`,
                ADD COLUMN `policy_content` LONGTEXT NULL DEFAULT NULL AFTER `content`,
                ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`,
                ADD INDEX `idx_oci_cptpl_user` (`user_id`),
                ADD CONSTRAINT `fk_oci_cptpl_user` FOREIGN KEY (`user_id`) REFERENCES `oci_users` (`id`) ON DELETE CASCADE
        ");

        // Privacy policy templates — add user ownership + structured fields
        $this->sql("
            ALTER TABLE `oci_privacy_policy_templates`
                ADD COLUMN `user_id` INT UNSIGNED NULL DEFAULT NULL AFTER `id`,
                ADD COLUMN `heading` VARCHAR(300) NULL DEFAULT NULL AFTER `template_name`,
                ADD COLUMN `url_key` VARCHAR(255) NULL DEFAULT NULL AFTER `heading`,
                ADD COLUMN `step_data` LONGTEXT NULL DEFAULT NULL COMMENT 'JSON wizard steps' AFTER `url_key`,
                ADD COLUMN `effective_date` DATE NULL DEFAULT NULL AFTER `content`,
                ADD COLUMN `policy_content` LONGTEXT NULL DEFAULT NULL AFTER `effective_date`,
                ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`,
                ADD INDEX `idx_oci_pptpl_user` (`user_id`),
                ADD CONSTRAINT `fk_oci_pptpl_user` FOREIGN KEY (`user_id`) REFERENCES `oci_users` (`id`) ON DELETE CASCADE
        ");
    }

    public function down(): void
    {
        $this->sql("
            ALTER TABLE `oci_cookie_policy_templates`
                DROP FOREIGN KEY `fk_oci_cptpl_user`,
                DROP INDEX `idx_oci_cptpl_user`,
                DROP COLUMN `user_id`,
                DROP COLUMN `heading`,
                DROP COLUMN `type_heading`,
                DROP COLUMN `preference_heading`,
                DROP COLUMN `preference_description`,
                DROP COLUMN `revisit_consent_widget`,
                DROP COLUMN `show_audit_table`,
                DROP COLUMN `effective_date`,
                DROP COLUMN `policy_content`,
                DROP COLUMN `updated_at`
        ");

        $this->sql("
            ALTER TABLE `oci_privacy_policy_templates`
                DROP FOREIGN KEY `fk_oci_pptpl_user`,
                DROP INDEX `idx_oci_pptpl_user`,
                DROP COLUMN `user_id`,
                DROP COLUMN `heading`,
                DROP COLUMN `url_key`,
                DROP COLUMN `step_data`,
                DROP COLUMN `effective_date`,
                DROP COLUMN `policy_content`,
                DROP COLUMN `updated_at`
        ");
    }
}
