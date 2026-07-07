<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Move matomo_url and matomo_token from oci_sites to oci_users.
 *
 * Matomo credentials are per-user (one Matomo instance per user),
 * while site_id and container_id remain per-site.
 */
final class Version20260323_002_MoveMatomoCredsToUsers extends Migration
{
    public function getDescription(): string
    {
        return 'Move matomo_url and matomo_token to oci_users (per-user credentials)';
    }

    public function up(): void
    {
        // Add columns to users table
        $this->sql("
            ALTER TABLE oci_users
            ADD COLUMN matomo_url VARCHAR(500) NULL DEFAULT NULL,
            ADD COLUMN matomo_token VARCHAR(255) NULL DEFAULT NULL
        ");

        // Migrate existing data: copy the first non-null credentials per user
        $this->sql("
            UPDATE oci_users u
            INNER JOIN (
                SELECT user_id, matomo_url, matomo_token
                FROM oci_sites
                WHERE matomo_url IS NOT NULL AND matomo_url != ''
                GROUP BY user_id
            ) s ON u.id = s.user_id
            SET u.matomo_url = s.matomo_url,
                u.matomo_token = s.matomo_token
        ");

        // Drop from sites table
        $this->sql("
            ALTER TABLE oci_sites
            DROP COLUMN matomo_url,
            DROP COLUMN matomo_token
        ");
    }

    public function down(): void
    {
        // Re-add columns to sites
        $this->sql("
            ALTER TABLE oci_sites
            ADD COLUMN matomo_url VARCHAR(500) NULL DEFAULT NULL AFTER gtm_data_layer,
            ADD COLUMN matomo_token VARCHAR(255) NULL DEFAULT NULL AFTER matomo_url
        ");

        // Drop from users
        $this->sql("
            ALTER TABLE oci_users
            DROP COLUMN matomo_url,
            DROP COLUMN matomo_token
        ");
    }
}
