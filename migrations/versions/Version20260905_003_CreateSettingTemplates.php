<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Agency-owned settings templates, and the record of where they were applied.
 *
 * An agency's whole pitch is "we apply our standard across every client without
 * logging into each one". That needs two things the product does not have: a
 * place to keep the standard, and a record of which sites are on it.
 *
 * ## `oci_setting_templates`
 *
 * `payload` holds a **partial** settings map — `general`, `content` and `site`
 * sections, merged over whatever a site already has rather than replacing it.
 * The partialness is the whole design: a template states the handful of things
 * a standard cares about and stays silent about per-language banner text,
 * policy URLs, logos and colours, none of which one client should inherit from
 * another.
 *
 * `agency_id NULL` means a system template. The four built-ins are seeded from
 * `config/banner-templates.php`, so the file stays the single definition and
 * this table is a mirror rather than a fork.
 *
 * ## `oci_site_template_links`
 *
 * One row per site, so "which sites are on this template" is a query rather
 * than a scan. Three columns earn their place:
 *
 * - `applied_fingerprint` is a hash of the covered subset at apply time. Drift
 *   is "the site's current fingerprint no longer matches", which is the only
 *   way to detect that somebody edited a site out from under the standard
 *   without diffing every key on every page load.
 * - `previous_settings` is the revert snapshot. Applying a template to forty
 *   sites is the first cross-tenant write in the product; being able to undo
 *   one is the difference between a mistake and an incident.
 * - `applied_by` because an agency changing a client's banner must be
 *   attributable. Without it the agency is an unlogged editor of somebody
 *   else's compliance record, which is exactly the evidence a DPA asks for.
 *
 * `ON DELETE CASCADE` from the site: a deleted site's link is meaningless and
 * would otherwise inflate every "sites on this template" count with ghosts.
 * `ON DELETE SET NULL` from the template: unlinking history should survive the
 * template being deleted, because the audit question is "what happened", not
 * "what still exists".
 */
final class Version20260905_003_CreateSettingTemplates extends Migration
{
    public function getDescription(): string
    {
        return 'Agency settings templates and the per-site record of where they were applied';
    }

    public function up(): void
    {
        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_setting_templates` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `agency_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = built-in system template',
                `system_key` VARCHAR(50) NULL DEFAULT NULL COMMENT 'basic, advanced, basic_tcf, advanced_tcf',
                `name` VARCHAR(150) NOT NULL,
                `description` VARCHAR(500) NULL DEFAULT NULL,
                `payload` LONGTEXT NOT NULL COMMENT 'json: {general:{}, content:{}, site:{}} — a PARTIAL map, merged not replaced',
                `created_by` INT UNSIGNED NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_oci_tpl_system` (`system_key`),
                INDEX `idx_oci_tpl_agency` (`agency_id`),
                CONSTRAINT `fk_oci_tpl_agency` FOREIGN KEY (`agency_id`) REFERENCES `oci_agencies` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_oci_tpl_creator` FOREIGN KEY (`created_by`) REFERENCES `oci_users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_site_template_links` (
                `site_id` INT UNSIGNED NOT NULL,
                `template_id` INT UNSIGNED NULL DEFAULT NULL,
                `applied_fingerprint` CHAR(32) NOT NULL COMMENT 'md5 of the covered subset at apply time; drift = mismatch',
                `previous_settings` LONGTEXT NULL COMMENT 'json snapshot for revert',
                `applied_by` INT UNSIGNED NULL DEFAULT NULL,
                `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`site_id`),
                INDEX `idx_oci_tpllink_template` (`template_id`),
                CONSTRAINT `fk_oci_tpllink_site` FOREIGN KEY (`site_id`) REFERENCES `oci_sites` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_oci_tpllink_template` FOREIGN KEY (`template_id`) REFERENCES `oci_setting_templates` (`id`) ON DELETE SET NULL,
                CONSTRAINT `fk_oci_tpllink_user` FOREIGN KEY (`applied_by`) REFERENCES `oci_users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropIfExists('oci_site_template_links');
        $this->dropIfExists('oci_setting_templates');
    }
}
