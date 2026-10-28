<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Two-phase cookie scans: every URL can be scanned before consent and again
 * after Accept All.
 *
 * A scan used to see only what the blocker let through, which after install
 * is our own two cookies, and that scan became the base of the customer's
 * public cookie declaration. These columns let a scan say what it asked for,
 * what each pass found, how consent was given, and whether the after-consent
 * result is trustworthy enough to replace a site's inventory.
 *
 * Every statement is idempotent and every column nullable: NULL means a
 * legacy scan, and nothing is backfilled, because the legacy fallback depends
 * on old rows keeping `consent_phase IS NULL`. `oci_scan_cookies` already has
 * `source` and `consent_phase` (Version20260310_001); only an index is added.
 *
 * This ships before any code writes these columns. deploy.sh runs migrations
 * after the containers start, so a callback processed in that window must not
 * find them missing.
 */
final class Version20260913_001_TwoPhaseCookieScans extends Migration
{
    public function getDescription(): string
    {
        return 'Two-phase cookie scans: per-phase counts, consent method, verified inventory';
    }

    public function up(): void
    {
        $this->sql('ALTER TABLE `oci_scans`
            ADD COLUMN IF NOT EXISTS `consent_phases` VARCHAR(40) NULL COMMENT \'phases requested, e.g. pre_consent,post_consent\',
            ADD COLUMN IF NOT EXISTS `inventory_complete` TINYINT(1) NULL COMMENT \'every completed URL has a verified after-consent pass\',
            ADD COLUMN IF NOT EXISTS `pre_consent_cookies` INT UNSIGNED NULL,
            ADD COLUMN IF NOT EXISTS `post_consent_cookies` INT UNSIGNED NULL,
            ADD COLUMN IF NOT EXISTS `pre_consent_beacons` INT UNSIGNED NULL,
            ADD COLUMN IF NOT EXISTS `post_consent_beacons` INT UNSIGNED NULL,
            ADD COLUMN IF NOT EXISTS `pre_consent_leaks` INT UNSIGNED NULL COMMENT \'distinct non-necessary cookies set before consent\',
            ADD COLUMN IF NOT EXISTS `pre_consent_unclassified` INT UNSIGNED NULL COMMENT \'distinct unclassified cookies set before consent\'');

        $this->sql('ALTER TABLE `oci_scan_urls`
            ADD COLUMN IF NOT EXISTS `consent_method` VARCHAR(20) NULL,
            ADD COLUMN IF NOT EXISTS `post_consent_status` VARCHAR(20) NULL COMMENT \'verified, unverified, failed\',
            ADD COLUMN IF NOT EXISTS `post_consent_error` VARCHAR(500) NULL,
            ADD COLUMN IF NOT EXISTS `final_url` VARCHAR(2048) NULL,
            ADD COLUMN IF NOT EXISTS `diagnostics` TEXT NULL COMMENT \'json\'');

        $this->sql('ALTER TABLE `oci_beacon_scans`
            ADD COLUMN IF NOT EXISTS `consent_phase` VARCHAR(15) NULL COMMENT \'pre_consent, post_consent\'');
        $this->sql('ALTER TABLE `oci_beacon_scans` ADD INDEX IF NOT EXISTS `idx_oci_beacon_scans_phase` (`scan_id`, `consent_phase`)');

        $this->sql('ALTER TABLE `oci_scan_cookies` ADD INDEX IF NOT EXISTS `idx_oci_scan_cookies_phase` (`scan_id`, `consent_phase`)');

        $this->sql('ALTER TABLE `oci_cookie_register_snapshots`
            ADD COLUMN IF NOT EXISTS `basis` VARCHAR(20) NULL COMMENT \'legacy or inventory: a change of basis re-baselines silently\'');
    }

    public function down(): void
    {
        $this->sql('ALTER TABLE `oci_cookie_register_snapshots` DROP COLUMN IF EXISTS `basis`');
        $this->sql('ALTER TABLE `oci_scan_cookies` DROP INDEX IF EXISTS `idx_oci_scan_cookies_phase`');
        $this->sql('ALTER TABLE `oci_beacon_scans` DROP INDEX IF EXISTS `idx_oci_beacon_scans_phase`');
        $this->sql('ALTER TABLE `oci_beacon_scans` DROP COLUMN IF EXISTS `consent_phase`');
        $this->sql('ALTER TABLE `oci_scan_urls`
            DROP COLUMN IF EXISTS `diagnostics`,
            DROP COLUMN IF EXISTS `final_url`,
            DROP COLUMN IF EXISTS `post_consent_error`,
            DROP COLUMN IF EXISTS `post_consent_status`,
            DROP COLUMN IF EXISTS `consent_method`');
        $this->sql('ALTER TABLE `oci_scans`
            DROP COLUMN IF EXISTS `pre_consent_unclassified`,
            DROP COLUMN IF EXISTS `pre_consent_leaks`,
            DROP COLUMN IF EXISTS `post_consent_beacons`,
            DROP COLUMN IF EXISTS `pre_consent_beacons`,
            DROP COLUMN IF EXISTS `post_consent_cookies`,
            DROP COLUMN IF EXISTS `pre_consent_cookies`,
            DROP COLUMN IF EXISTS `inventory_complete`,
            DROP COLUMN IF EXISTS `consent_phases`');
    }
}
