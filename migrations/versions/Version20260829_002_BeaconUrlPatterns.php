<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Path-level beacon classification. One domain can serve scripts of different
 * categories (www.google.com: recaptcha is necessary, pagead is marketing),
 * so a global beacon entry gains an optional url_pattern: '' means the whole
 * domain (the previous behavior), a path pattern like /recaptcha/* scopes the
 * classification to matching scripts. The unique key moves from domain alone
 * to (domain, url_pattern) so a domain can hold several pattern entries plus
 * one domain-wide fallback.
 */
final class Version20260829_002_BeaconUrlPatterns extends Migration
{
    public function getDescription(): string
    {
        return 'Add url_pattern to oci_beacons_global; unique key becomes (domain, url_pattern)';
    }

    public function up(): void
    {
        $this->sql("ALTER TABLE `oci_beacons_global`
            ADD COLUMN IF NOT EXISTS `url_pattern` VARCHAR(300) NOT NULL DEFAULT '' AFTER `domain`");

        $this->sql('ALTER TABLE `oci_beacons_global`
            DROP INDEX IF EXISTS `uq_oci_beacon_global_domain`');

        $this->sql('ALTER TABLE `oci_beacons_global`
            ADD UNIQUE KEY IF NOT EXISTS `uq_oci_beacon_global_domain_pattern` (`domain`, `url_pattern`)');
    }

    public function down(): void
    {
        $this->sql('ALTER TABLE `oci_beacons_global`
            DROP INDEX IF EXISTS `uq_oci_beacon_global_domain_pattern`');

        $this->sql('ALTER TABLE `oci_beacons_global`
            ADD UNIQUE KEY IF NOT EXISTS `uq_oci_beacon_global_domain` (`domain`)');

        $this->sql('ALTER TABLE `oci_beacons_global`
            DROP COLUMN IF EXISTS `url_pattern`');
    }
}
