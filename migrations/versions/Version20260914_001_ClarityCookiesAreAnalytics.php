<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Microsoft Clarity's cookies are analytics, not marketing.
 *
 * The seed put Clarity's _clck and _clsk in one pattern with Microsoft
 * Advertising's cookies, gated by marketing. Clarity writes them under
 * analytics consent (its consent call takes analytics_Storage), the scanner
 * and the global cookie database both file them as analytics, and so do
 * sites. A visitor who accepted analytics and refused marketing had them held
 * by the cookie interceptor and removed on every page.
 *
 * The combined row is split: Microsoft Advertising stays marketing, Clarity
 * gets its own analytics row.
 */
final class Version20260914_001_ClarityCookiesAreAnalytics extends Migration
{
    private const COMBINED = '^(_uet|_uetsid|_uetvid|MUID|_clck|_clsk)$';

    public function getDescription(): string
    {
        return 'Split Clarity cookies out of the Microsoft Advertising blocking pattern, gated by analytics';
    }

    public function up(): void
    {
        $this->insert('^(_uet|_uetsid|_uetvid|MUID)$', 'marketing', 'Microsoft Advertising');
        $this->insert('^(_clck|_clsk)$', 'analytics', 'Microsoft Clarity');

        $this->sql(
            "DELETE FROM `oci_blocked_cookie_names` WHERE `cookie_pattern` = '" . addslashes(self::COMBINED) . "'",
        );
    }

    public function down(): void
    {
        $this->insert(self::COMBINED, 'marketing', 'Microsoft Advertising / Clarity');

        foreach (['^(_uet|_uetsid|_uetvid|MUID)$', '^(_clck|_clsk)$'] as $pattern) {
            $this->sql(
                "DELETE FROM `oci_blocked_cookie_names` WHERE `cookie_pattern` = '" . addslashes($pattern) . "'",
            );
        }
    }

    private function insert(string $pattern, string $category, string $provider): void
    {
        $this->sql(
            "INSERT IGNORE INTO `oci_blocked_cookie_names` (`cookie_pattern`, `category`, `provider_name`) VALUES ('"
            . addslashes($pattern) . "', '" . addslashes($category) . "', '" . addslashes($provider) . "')",
        );
    }
}
