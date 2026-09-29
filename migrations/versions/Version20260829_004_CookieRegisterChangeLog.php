<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Cookie register change log (Tier 2 #8).
 *
 * The effective register (cookies + beacons a site actually serves, after
 * scan + observation merge, global enrichment and per-site overrides) was
 * computed on every page view and never stored, so "what changed since the
 * last scan" was unanswerable. Every completed scan now persists a snapshot
 * of the effective register as one JSON row and diffs it against the
 * previous snapshot; the diff rows feed an alert mail, the scan report and
 * the /cookies/changes timeline.
 */
final class Version20260829_004_CookieRegisterChangeLog extends Migration
{
    public function getDescription(): string
    {
        return 'Cookie register snapshots + change log tables';
    }

    public function up(): void
    {
        $this->sql("CREATE TABLE IF NOT EXISTS `oci_cookie_register_snapshots` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `site_id` INT UNSIGNED NOT NULL,
            `scan_id` INT UNSIGNED NULL,
            `entries` LONGTEXT NOT NULL COMMENT 'json',
            `entry_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_register_snapshot_site` (`site_id`, `id`),
            CONSTRAINT `fk_register_snapshot_site` FOREIGN KEY (`site_id`)
                REFERENCES `oci_sites`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->sql("CREATE TABLE IF NOT EXISTS `oci_cookie_register_changes` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `site_id` INT UNSIGNED NOT NULL,
            `snapshot_id` INT UNSIGNED NOT NULL,
            `scan_id` INT UNSIGNED NULL,
            `change_type` ENUM('added','removed','category_changed','attribute_changed') NOT NULL,
            `entry_type` ENUM('cookie','beacon') NOT NULL,
            `name` VARCHAR(500) NOT NULL,
            `domain` VARCHAR(300) NOT NULL DEFAULT '',
            `old_value` LONGTEXT NULL COMMENT 'json',
            `new_value` LONGTEXT NULL COMMENT 'json',
            `notified_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_register_change_site` (`site_id`, `created_at`),
            CONSTRAINT `fk_register_change_site` FOREIGN KEY (`site_id`)
                REFERENCES `oci_sites`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_register_change_snapshot` FOREIGN KEY (`snapshot_id`)
                REFERENCES `oci_cookie_register_snapshots`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        $this->dropIfExists('oci_cookie_register_changes');
        $this->dropIfExists('oci_cookie_register_snapshots');
    }
}
