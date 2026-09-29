<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Admin-driven import of conzent.net (v1) accounts: the stamps that say an
 * account or site came in through the LegacyMigration module, the flag that
 * marks a subscription row as a mirror of the customer's existing Stripe
 * subscription, and the run log the admin page shows.
 *
 * Cloud Edition only: the module that writes these lives in
 * src/Modules/LegacyMigration and the file is on the publish skill's
 * exclusion list. `legacy_id` on users, sites and agencies already exists in
 * the core schema and is what the importer keys on; these columns only add
 * "when" and "which run".
 *
 * `legacy_mirrored` matters to one reader: the self-service USD-to-EUR offer
 * (`SubscriptionMigrationService::getMigrationEligibility`) treats any active
 * row as "already on the new system" and hides the offer. A mirrored row is
 * not that, so the guard ignores rows carrying this flag.
 */
final class Version20260910_001_LegacyImportTracking extends Migration
{
    public function getDescription(): string
    {
        return 'Legacy import: import stamps on users and sites, the mirrored-subscription flag, and the run log';
    }

    public function up(): void
    {
        $this->sql('ALTER TABLE `oci_users`
            ADD COLUMN IF NOT EXISTS `legacy_imported_at` DATETIME NULL DEFAULT NULL AFTER `legacy_migrated_at`,
            ADD COLUMN IF NOT EXISTS `legacy_import_run_id` INT UNSIGNED NULL DEFAULT NULL AFTER `legacy_imported_at`,
            ADD INDEX IF NOT EXISTS `idx_oci_users_import_run` (`legacy_import_run_id`)');

        $this->sql('ALTER TABLE `oci_sites`
            ADD COLUMN IF NOT EXISTS `legacy_imported_at` DATETIME NULL DEFAULT NULL AFTER `legacy_id`');

        $this->sql('ALTER TABLE `oci_subscriptions`
            ADD COLUMN IF NOT EXISTS `legacy_mirrored` TINYINT(1) NOT NULL DEFAULT 0 COMMENT \'1 = a copy of the customer\'\'s existing conzent.net Stripe subscription, written by the legacy importer\' AFTER `migration_free_months`');

        $this->sql("CREATE TABLE IF NOT EXISTS `oci_legacy_import_runs` (
            `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `kind`              ENUM('user','agency') NOT NULL,
            `legacy_user_id`    INT UNSIGNED NOT NULL,
            `legacy_agency_id`  INT UNSIGNED NULL DEFAULT NULL,
            `email`             VARCHAR(255) NOT NULL DEFAULT '',
            `actor_user_id`     INT UNSIGNED NULL DEFAULT NULL,
            `mode`              ENUM('preview','apply') NOT NULL,
            `billing_strategy`  VARCHAR(20) NOT NULL DEFAULT 'none',
            `status`            VARCHAR(30) NOT NULL DEFAULT 'running',
            `summary`           TEXT NULL DEFAULT NULL COMMENT 'json',
            `tree`              LONGTEXT NULL DEFAULT NULL COMMENT 'json',
            `started_at`        DATETIME NOT NULL,
            `completed_at`      DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            INDEX `idx_oci_lir_legacy_user` (`legacy_user_id`),
            INDEX `idx_oci_lir_email` (`email`),
            INDEX `idx_oci_lir_started` (`started_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        $this->dropIfExists('oci_legacy_import_runs');
        $this->sql('ALTER TABLE `oci_subscriptions` DROP COLUMN IF EXISTS `legacy_mirrored`');
        $this->sql('ALTER TABLE `oci_sites` DROP COLUMN IF EXISTS `legacy_imported_at`');
        $this->sql('ALTER TABLE `oci_users`
            DROP INDEX IF EXISTS `idx_oci_users_import_run`,
            DROP COLUMN IF EXISTS `legacy_import_run_id`,
            DROP COLUMN IF EXISTS `legacy_imported_at`');
    }
}
