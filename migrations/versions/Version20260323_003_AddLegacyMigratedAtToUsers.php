<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Add legacy_migrated_at column to oci_users for self-service legacy migration tracking.
 */
final class Version20260323_003_AddLegacyMigratedAtToUsers extends Migration
{
    public function getDescription(): string
    {
        return 'Add legacy_migrated_at column to oci_users for self-service legacy account migration';
    }

    public function up(): void
    {
        $this->sql(
            'ALTER TABLE oci_users ADD COLUMN legacy_migrated_at DATETIME NULL DEFAULT NULL AFTER updated_at'
        );
    }

    public function down(): void
    {
        $this->sql(
            'ALTER TABLE oci_users DROP COLUMN legacy_migrated_at'
        );
    }
}
