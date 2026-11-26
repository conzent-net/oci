<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Geniziz customer sync: one row per (workspace, user) recording which lead and
 * contact a Conzent account maps to in a Geniziz workspace, and how the last
 * push went.
 *
 * Cloud Edition only — the table belongs to src/Modules/Geniziz and is listed in
 * the publish skill's exclusions, so the open-source edition never carries it.
 *
 * Why a table and not oci_configuration blobs: the admin page filters and pages
 * over these rows (by state, by planned action, by sync status) and the
 * reconciler selects "due" rows by status and next_attempt_at. That is row
 * data with indexes, not a settings bag.
 *
 * Keyed by workspace on purpose. A Geniziz API key is pinned to one workspace,
 * so switching the active workspace starts a fresh link set and switching back
 * finds the old one intact. `fingerprint` is the sha1 of the last payload the
 * reconciler pushed; an unchanged fingerprint means no API call at all.
 *
 * `review` is terminal until a human resolves it on the page (duplicate leads,
 * ambiguous matches, a candidate that needs a click, a locked lead). `error`
 * rows retry on a backoff schedule; `skipped` rows are left alone.
 */
final class Version20260909_001_CreateGenizizSync extends Migration
{
    public function getDescription(): string
    {
        return 'Geniziz sync: per-workspace lead/contact links and sync state for every Conzent account';
    }

    public function up(): void
    {
        $this->sql("CREATE TABLE IF NOT EXISTS `oci_geniziz_sync` (
            `workspace_id`      VARCHAR(40)  NOT NULL,
            `user_id`           INT UNSIGNED NOT NULL,
            `lead_id`           VARCHAR(40)  NULL DEFAULT NULL,
            `contact_id`        VARCHAR(40)  NULL DEFAULT NULL,
            `fingerprint`       CHAR(40)     NULL DEFAULT NULL COMMENT 'sha1 of the last pushed payload',
            `derived_state`     VARCHAR(20)  NULL DEFAULT NULL,
            `match_reason`      VARCHAR(40)  NULL DEFAULT NULL COMMENT 'created|conzent_id|email|domain|email_domain|manual',
            `sync_status`       ENUM('pending','ok','error','review','skipped') NOT NULL DEFAULT 'pending',
            `planned_action`    VARCHAR(20)  NULL DEFAULT NULL COMMENT 'dry run: create|adopt|adopt_candidate|update|noop|skip',
            `candidate_lead_id` VARCHAR(40)  NULL DEFAULT NULL,
            `review_reason`     VARCHAR(40)  NULL DEFAULT NULL,
            `attempts`          TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `relinks`           TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `next_attempt_at`   DATETIME     NULL DEFAULT NULL,
            `last_error`        VARCHAR(500) NULL DEFAULT NULL,
            `synced_at`         DATETIME     NULL DEFAULT NULL,
            `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`workspace_id`, `user_id`),
            INDEX `idx_oci_gz_due`  (`workspace_id`, `sync_status`, `next_attempt_at`),
            INDEX `idx_oci_gz_lead` (`workspace_id`, `lead_id`),
            CONSTRAINT `fk_oci_gz_user` FOREIGN KEY (`user_id`) REFERENCES `oci_users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        $this->dropIfExists('oci_geniziz_sync');
    }
}
