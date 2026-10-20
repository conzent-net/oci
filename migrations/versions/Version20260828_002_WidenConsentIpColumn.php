<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

final class Version20260828_002_WidenConsentIpColumn extends Migration
{
    public function getDescription(): string
    {
        return 'Widen oci_consents.ip_address to hold a 64-char HMAC-SHA256 pseudonym instead of a raw IP';
    }

    public function up(): void
    {
        $this->sql('ALTER TABLE `oci_consents` MODIFY COLUMN `ip_address` VARCHAR(64) NOT NULL DEFAULT \'\'');
    }

    public function down(): void
    {
        $this->sql('ALTER TABLE `oci_consents` MODIFY COLUMN `ip_address` VARCHAR(45) NOT NULL DEFAULT \'\'');
    }
}
