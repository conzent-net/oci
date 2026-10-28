<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Add email_verified column to oci_users for signup-time + daily verification.
 *
 * Conservative backfill: users with last_login_at or google_id are marked VALID
 * (they've proven the address works). Everyone else stays PENDING for the cron.
 */
final class Version20260526_001_AddEmailVerifiedToUsers extends Migration
{
    public function getDescription(): string
    {
        return 'Add email_verified column to oci_users';
    }

    public function up(): void
    {
        $this->sql("
            ALTER TABLE `oci_users`
            ADD COLUMN `email_verified` VARCHAR(20) NOT NULL DEFAULT 'PENDING'
                COMMENT 'PENDING, VALID, INVALID'
            AFTER `email`
        ");

        $this->sql("CREATE INDEX idx_users_email_verified ON oci_users (email_verified)");

        $this->sql("
            UPDATE oci_users
            SET email_verified = 'VALID'
            WHERE last_login_at IS NOT NULL OR google_id IS NOT NULL
        ");
    }

    public function down(): void
    {
        $this->sql("ALTER TABLE `oci_users` DROP INDEX idx_users_email_verified");
        $this->sql("ALTER TABLE `oci_users` DROP COLUMN `email_verified`");
    }
}
