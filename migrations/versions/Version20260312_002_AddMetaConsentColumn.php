<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Add meta_consent_enabled column to oci_sites for Meta (Facebook) Consent Mode support.
 */
final class Version20260312_002_AddMetaConsentColumn extends Migration
{
    public function getDescription(): string
    {
        return 'Add meta_consent_enabled column to oci_sites';
    }

    public function up(): void
    {
        $this->sql("
            ALTER TABLE oci_sites
            ADD COLUMN meta_consent_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER gcm_enabled
        ");
    }

    public function down(): void
    {
        $this->sql("
            ALTER TABLE oci_sites DROP COLUMN meta_consent_enabled
        ");
    }
}
