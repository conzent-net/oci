<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Add GTM container ID and data layer name columns to oci_sites
 * so the consent script can auto-inject Google Tag Manager.
 */
final class Version20260313_003_AddGtmContainerColumns extends Migration
{
    public function getDescription(): string
    {
        return 'Add gtm_container_id and gtm_data_layer columns to oci_sites';
    }

    public function up(): void
    {
        $this->sql("
            ALTER TABLE oci_sites
            ADD COLUMN gtm_container_id VARCHAR(20) NULL DEFAULT NULL AFTER uet_enabled,
            ADD COLUMN gtm_data_layer VARCHAR(50) NULL DEFAULT NULL AFTER gtm_container_id
        ");
    }

    public function down(): void
    {
        $this->sql("
            ALTER TABLE oci_sites
            DROP COLUMN gtm_container_id,
            DROP COLUMN gtm_data_layer
        ");
    }
}
