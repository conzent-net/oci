<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

final class Version20260828_004_AddAdvertiserConsentModeToggle extends Migration
{
    public function getDescription(): string
    {
        return 'Dedicated client-controlled toggle for TCData.enableAdvertiserConsentMode (Google CMP Partner Program requirement), default ON';
    }

    public function up(): void
    {
        $this->sql("ALTER TABLE `oci_sites`
            ADD COLUMN IF NOT EXISTS `advertiser_consent_mode` TINYINT(1) NOT NULL DEFAULT 1 AFTER `gcm_enabled`");
    }

    public function down(): void
    {
        $this->sql('ALTER TABLE `oci_sites` DROP COLUMN IF EXISTS `advertiser_consent_mode`');
    }
}
