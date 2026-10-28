<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Acknowledge and snooze state for the compliance view.
 *
 * The issues themselves are derived live from signals — they are not stored,
 * because a stored issue is a fact that can go stale while nobody notices. What
 * IS stored is the **human decision** about an issue: we know, it is the
 * client's call, come back to us in a month.
 *
 * ## Why `evidence_hash` matters more than it looks
 *
 * A snooze that never reopens is how a dashboard rots. Somebody snoozes "3
 * cookies before consent" for 90 days, the number climbs to 400, and the screen
 * stays quiet because the *issue key* has not changed. So a snooze records a
 * hash of the evidence it was granted against, and the derivation reopens the
 * row when the evidence materially worsens. Snoozing is "not now", never "never
 * again".
 *
 * ## Why resolution is automatic
 *
 * Nothing sets `resolved` by hand. The worker sets it when an issue stops
 * deriving, which means a fixed problem disappears on its own and a recurrence
 * comes back as a new episode with `alerted_at` cleared — so it can alert once
 * more rather than staying silent because it was once resolved.
 *
 * `UNIQUE(site_id, issue_key)` is what makes the whole thing idempotent: the
 * deriver runs on every page load and must never accumulate duplicate rows for
 * the same standing problem.
 */
final class Version20260905_005_CreateSiteIssues extends Migration
{
    public function getDescription(): string
    {
        return 'Acknowledge and snooze state for derived compliance issues';
    }

    public function up(): void
    {
        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_site_issues` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `site_id` INT UNSIGNED NOT NULL,
                `issue_key` VARCHAR(60) NOT NULL COMMENT 'shared vocabulary with ComplianceCheckService',
                `severity` ENUM('fail','warn','info') NOT NULL DEFAULT 'warn',
                `magnitude` INT UNSIGNED NULL DEFAULT NULL COMMENT 'size at the time the decision was taken',
                `state` ENUM('open','acked','snoozed','resolved') NOT NULL DEFAULT 'open',
                `evidence_hash` CHAR(32) NULL DEFAULT NULL COMMENT 'reopens the row when the evidence changes',
                `snoozed_until` DATE NULL DEFAULT NULL COMMENT 'NULL with state=snoozed means until it changes',
                `note` VARCHAR(255) NULL DEFAULT NULL,
                `acted_by` INT UNSIGNED NULL DEFAULT NULL,
                `acted_at` DATETIME NULL DEFAULT NULL,
                `alerted_at` DATETIME NULL DEFAULT NULL COMMENT 'one alert per open episode',
                `first_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `resolved_at` DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_oci_issue_site_key` (`site_id`, `issue_key`),
                INDEX `idx_oci_issue_state` (`state`, `severity`),
                INDEX `idx_oci_issue_snooze` (`state`, `snoozed_until`),
                CONSTRAINT `fk_oci_issue_site` FOREIGN KEY (`site_id`) REFERENCES `oci_sites` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_oci_issue_actor` FOREIGN KEY (`acted_by`) REFERENCES `oci_users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropIfExists('oci_site_issues');
    }
}
