<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Add uet_enabled column to oci_sites for Microsoft UET Consent Mode support.
 */
final class Version20260313_002_AddUetEnabledColumn extends Migration
{
    public function getDescription(): string
    {
        return 'Add uet_enabled column to oci_sites for Microsoft UET Consent Mode';
    }

    public function up(): void
    {
        $this->sql("
            ALTER TABLE oci_sites
            ADD COLUMN uet_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER meta_consent_enabled
        ");
    }

    public function down(): void
    {
        $this->sql("
            ALTER TABLE oci_sites DROP COLUMN uet_enabled
        ");
    }
}
