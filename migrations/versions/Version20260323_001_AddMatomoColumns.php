<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Add Matomo Tag Manager credential columns to oci_sites
 * for the Matomo TM Wizard integration.
 */
final class Version20260323_001_AddMatomoColumns extends Migration
{
    public function getDescription(): string
    {
        return 'Add matomo_url, matomo_token, matomo_site_id, matomo_container_id columns to oci_sites';
    }

    public function up(): void
    {
        $this->sql("
            ALTER TABLE oci_sites
            ADD COLUMN matomo_url VARCHAR(500) NULL DEFAULT NULL AFTER gtm_data_layer,
            ADD COLUMN matomo_token VARCHAR(255) NULL DEFAULT NULL AFTER matomo_url,
            ADD COLUMN matomo_site_id INT UNSIGNED NULL DEFAULT NULL AFTER matomo_token,
            ADD COLUMN matomo_container_id VARCHAR(50) NULL DEFAULT NULL AFTER matomo_site_id
        ");
    }

    public function down(): void
    {
        $this->sql("
            ALTER TABLE oci_sites
            DROP COLUMN matomo_url,
            DROP COLUMN matomo_token,
            DROP COLUMN matomo_site_id,
            DROP COLUMN matomo_container_id
        ");
    }
}
