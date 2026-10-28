<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

final class Version20260828_001_AddCookieSamesiteToSites extends Migration
{
    public function getDescription(): string
    {
        return 'Add per-site SameSite policy for the consent cookies (lax default; none enables B2B punch-out flows)';
    }

    public function up(): void
    {
        $this->sql("ALTER TABLE `oci_sites`
            ADD COLUMN IF NOT EXISTS `cookie_samesite` VARCHAR(10) NOT NULL DEFAULT 'lax' AFTER `consent_sharing_enabled`");
    }

    public function down(): void
    {
        $this->sql('ALTER TABLE `oci_sites` DROP COLUMN IF EXISTS `cookie_samesite`');
    }
}
