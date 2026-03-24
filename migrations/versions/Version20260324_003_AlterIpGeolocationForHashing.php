<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Alter oci_ip_geolocation to store hashed IPs (SHA-256) instead of raw IPs,
 * and add in_eu + source columns for the ipregistry API fallback cache.
 */
final class Version20260324_003_AlterIpGeolocationForHashing extends Migration
{
    public function getDescription(): string
    {
        return 'Alter oci_ip_geolocation: replace ip_address with ip_hash, add in_eu and source columns';
    }

    public function up(): void
    {
        // Drop existing unique key, add new columns, drop raw IP column
        $this->sql("
            ALTER TABLE `oci_ip_geolocation`
                DROP INDEX `uq_oci_geo_ip`,
                DROP COLUMN `ip_address`,
                ADD COLUMN `ip_hash` VARCHAR(64) NOT NULL AFTER `id`,
                ADD COLUMN `in_eu` TINYINT(1) NOT NULL DEFAULT 0 AFTER `country_code`,
                ADD COLUMN `source` VARCHAR(20) NOT NULL DEFAULT 'ipregistry' AFTER `in_eu`,
                ADD UNIQUE KEY `uq_oci_geo_ip_hash` (`ip_hash`)
        ");
    }

    public function down(): void
    {
        $this->sql("
            ALTER TABLE `oci_ip_geolocation`
                DROP INDEX `uq_oci_geo_ip_hash`,
                DROP COLUMN `source`,
                DROP COLUMN `in_eu`,
                DROP COLUMN `ip_hash`,
                ADD COLUMN `ip_address` VARCHAR(45) NOT NULL AFTER `id`,
                ADD UNIQUE KEY `uq_oci_geo_ip` (`ip_address`)
        ");
    }
}
