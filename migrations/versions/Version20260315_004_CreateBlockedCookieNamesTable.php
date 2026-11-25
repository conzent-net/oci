<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Cookie-name blocking patterns for the document.cookie interceptor.
 * These patterns block known tracking cookies from being written before consent.
 */
final class Version20260315_004_CreateBlockedCookieNamesTable extends Migration
{
    public function getDescription(): string
    {
        return 'Create oci_blocked_cookie_names table and seed known tracking cookie patterns';
    }

    public function up(): void
    {
        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_blocked_cookie_names` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `cookie_pattern` VARCHAR(200) NOT NULL COMMENT 'JS regex pattern matching cookie names',
                `category` VARCHAR(50) NOT NULL DEFAULT 'marketing' COMMENT 'Consent category that gates this cookie',
                `provider_name` VARCHAR(200) NULL DEFAULT NULL COMMENT 'Human-readable provider name',
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_bcn_pattern` (`cookie_pattern`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Seed known tracking cookie patterns
        $patterns = [
            ['^(_fbp|_fbc|fr)$', 'marketing', 'Facebook Pixel'],
            ['^(_gcl_au|_gcl_aw|_gcl_dc|_gcl_gb|_gcl_gs|_gcl_ha|_gcl_gf)$', 'marketing', 'Google Ads'],
            ['^(_ga|_ga_|_gid|_gat|__utm)', 'analytics', 'Google Analytics'],
            ['^(_tt_|_ttp)$', 'marketing', 'TikTok Pixel'],
            ['^(_uet|_uetsid|_uetvid|MUID|_clck|_clsk)$', 'marketing', 'Microsoft Advertising / Clarity'],
            ['^(_scid|_sctr|sc_at)$', 'marketing', 'Snapchat Pixel'],
            ['^(_pin_unauth|_pinterest_)$', 'marketing', 'Pinterest Tag'],
            ['^(_rdt_uuid|_rdt_cid)$', 'marketing', 'Reddit Pixel'],
            ['^(IDE|test_cookie|DSID)$', 'marketing', 'Google DoubleClick'],
            ['^(_hjid|_hjSession)', 'analytics', 'Hotjar'],
            ['^(mp_|amplitude_id)', 'analytics', 'Mixpanel / Amplitude'],
            ['^(ajs_anonymous_id|ajs_user_id)', 'analytics', 'Segment'],
            ['^(_li_ss|li_sugr|bcookie|lidc|UserMatchHistory)$', 'marketing', 'LinkedIn Insight Tag'],
            ['^(muc_ads|personalization_id|guest_id)$', 'marketing', 'Twitter / X Pixel'],
            ['^(cto_bundle|cto_bidid|cto_lwid)$', 'marketing', 'Criteo'],
            ['^(t_gid|t_pt_gid|taboola_)', 'marketing', 'Taboola'],
            ['^(__hs|hubspotutk|__hstc|__hssc|__hssrc)', 'marketing', 'HubSpot'],
        ];

        foreach ($patterns as [$pattern, $category, $provider]) {
            $escaped = addslashes($pattern);
            $this->sql(
                "INSERT IGNORE INTO `oci_blocked_cookie_names` (`cookie_pattern`, `category`, `provider_name`) VALUES ('{$escaped}', '{$category}', '{$provider}')",
            );
        }
    }

    public function down(): void
    {
        $this->dropIfExists('oci_blocked_cookie_names');
    }
}
