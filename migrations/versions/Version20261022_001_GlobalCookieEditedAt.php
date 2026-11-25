<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * oci_cookies_global.edited_at: when an admin last saved the entry by hand.
 *
 * The Open Cookie Database sync overwrites every row it knows by cookie_id,
 * and classified_by cannot tell those rows apart from hand-edited ones
 * because it defaults to 'human' for all of them. The sync now leaves any
 * row with edited_at set alone. Entries already edited in the admin cookie
 * database are found through the audit log and stamped here, so the first
 * sync after this migration cannot undo them either.
 */
final class Version20261022_001_GlobalCookieEditedAt extends Migration
{
    public function getDescription(): string
    {
        return 'Add oci_cookies_global.edited_at and stamp entries already edited by hand';
    }

    public function up(): void
    {
        $this->sql('ALTER TABLE `oci_cookies_global`
            ADD COLUMN IF NOT EXISTS `edited_at` DATETIME NULL DEFAULT NULL AFTER `needs_review`');

        $this->sql("UPDATE `oci_cookies_global` cg
            JOIN (
                SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(`new_values`, '$.id')) AS UNSIGNED) AS `entry_id`,
                       MAX(`created_at`) AS `edited`
                FROM `oci_audit_log`
                WHERE `action` = 'global_cookie_updated'
                GROUP BY `entry_id`
            ) a ON a.`entry_id` = cg.`id`
            SET cg.`edited_at` = a.`edited`
            WHERE cg.`edited_at` IS NULL");
    }

    public function down(): void
    {
        $this->sql('ALTER TABLE `oci_cookies_global` DROP COLUMN IF EXISTS `edited_at`');
    }
}
