<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Add google_id column to oci_users for Google Sign-In support.
 */
final class Version20260313_004_AddGoogleIdToUsers extends Migration
{
    public function getDescription(): string
    {
        return 'Add google_id column to oci_users for Google OAuth login';
    }

    public function up(): void
    {
        $this->sql(
            'ALTER TABLE oci_users ADD COLUMN google_id VARCHAR(255) NULL AFTER password'
        );

        $this->sql(
            'CREATE UNIQUE INDEX idx_users_google_id ON oci_users (google_id)'
        );
    }

    public function down(): void
    {
        $this->sql(
            'ALTER TABLE oci_users DROP INDEX idx_users_google_id'
        );

        $this->sql(
            'ALTER TABLE oci_users DROP COLUMN google_id'
        );
    }
}
