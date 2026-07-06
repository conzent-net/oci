<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Add migration tracking columns to oci_subscriptions for USD→EUR self-service conversion.
 */
final class Version20260326_001_AddMigrationTracking extends Migration
{
    public function getDescription(): string
    {
        return 'Add migration tracking columns (migrated_from_legacy_id, migrated_at, migration_free_months) to oci_subscriptions';
    }

    public function up(): void
    {
        $this->sql("
            ALTER TABLE `oci_subscriptions`
                ADD COLUMN `migrated_from_legacy_id` INT UNSIGNED NULL DEFAULT NULL
                    COMMENT 'ID from legacy site_subscriptions this was migrated from',
                ADD COLUMN `migrated_at` DATETIME NULL DEFAULT NULL
                    COMMENT 'When self-service migration was executed',
                ADD COLUMN `migration_free_months` SMALLINT UNSIGNED NULL DEFAULT NULL
                    COMMENT 'Free months granted (remaining + 3 bonus)'
        ");
    }

    public function down(): void
    {
        $this->sql("
            ALTER TABLE `oci_subscriptions`
                DROP COLUMN `migration_free_months`,
                DROP COLUMN `migrated_at`,
                DROP COLUMN `migrated_from_legacy_id`
        ");
    }
}
