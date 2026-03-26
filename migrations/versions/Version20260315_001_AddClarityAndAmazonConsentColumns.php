<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Add clarity_enabled and amazon_consent_enabled columns to oci_sites
 * for independent Microsoft Clarity and Amazon consent signal support.
 */
final class Version20260315_001_AddClarityAndAmazonConsentColumns extends Migration
{
    public function getDescription(): string
    {
        return 'Add clarity_enabled and amazon_consent_enabled columns to oci_sites';
    }

    public function up(): void
    {
        $this->sql("
            ALTER TABLE oci_sites
            ADD COLUMN clarity_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER uet_enabled,
            ADD COLUMN amazon_consent_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER clarity_enabled
        ");
    }

    public function down(): void
    {
        $this->sql("
            ALTER TABLE oci_sites
            DROP COLUMN clarity_enabled,
            DROP COLUMN amazon_consent_enabled
        ");
    }
}
