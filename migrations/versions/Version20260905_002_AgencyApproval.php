<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Agency scoping, part 2: becoming an agency is a reviewed partnership request,
 * not a self-serve toggle.
 *
 * The earlier decision (2026-09-02) was that agency signup should be free and
 * unconditional. That was right about friction and wrong about blast radius,
 * because it predated free white-label. The two together are a pricing hole:
 * any customer on a paid-feature-limited plan could create a second account,
 * flag it an agency, invite their own main account as a client, and take
 * `custom_branding` for nothing. A human approving each partnership closes it
 * at the door, and the "1+ linked client" rule on white-label is the second
 * lock behind it.
 *
 * What did NOT change: an agency is still never required to run Conzent on its
 * own website. That catch-22 stays killed. The door is reviewed; the price of
 * entry did not go up.
 *
 * Shape notes:
 *
 * - `status` lives on `oci_agencies` rather than only on the application,
 *   because it is read on every single request by the agency middleware. A
 *   join to an applications table on the hot path to answer "may this account
 *   act as an agency" would be a join that exists purely to re-derive a fact.
 *
 * - `suspended` is a first-class state, not a deletion. Revoking an agency has
 *   to stop access and branding immediately while keeping the links and the
 *   history, because the usage export and the audit trail both need to be able
 *   to say what was true last month.
 *
 * - `is_active` is kept and left alone. It is an older, separate flag; the
 *   middleware requires `is_active = 1` AND `status = 'approved'`, so neither
 *   can quietly grant access on its own.
 *
 * - Existing agency rows are backfilled to `approved`. They predate the gate,
 *   and a migration that locks real agencies out of their own accounts to
 *   enforce a rule invented after they signed up would be the wrong kind of
 *   correct.
 *
 * - "One open application per user" is enforced in the service layer, not by a
 *   unique key: the real constraint is "at most one row with
 *   `status = 'pending'` per user", which MariaDB cannot express as a partial
 *   unique index. The index below makes the check cheap.
 */
final class Version20260905_002_AgencyApproval extends Migration
{
    public function getDescription(): string
    {
        return 'Agency partnership applications and the approval lifecycle on oci_agencies';
    }

    public function up(): void
    {
        $this->sql("
            ALTER TABLE `oci_agencies`
            ADD COLUMN `status` ENUM('pending','approved','rejected','suspended')
                NOT NULL DEFAULT 'pending'
                COMMENT 'only approved may act as an agency; suspended keeps links and history'
                AFTER `is_active`,
            ADD COLUMN `applied_at` DATETIME NULL DEFAULT NULL AFTER `status`,
            ADD COLUMN `reviewed_at` DATETIME NULL DEFAULT NULL AFTER `applied_at`,
            ADD COLUMN `reviewed_by` INT UNSIGNED NULL DEFAULT NULL
                COMMENT 'admin user who approved, rejected or suspended'
                AFTER `reviewed_at`,
            ADD COLUMN `review_note` VARCHAR(500) NULL DEFAULT NULL
                COMMENT 'shown to the applicant on rejection'
                AFTER `reviewed_by`,
            ADD INDEX `idx_oci_agency_status` (`status`),
            ADD CONSTRAINT `fk_oci_agency_reviewer`
                FOREIGN KEY (`reviewed_by`) REFERENCES `oci_users` (`id`) ON DELETE SET NULL
        ");

        // Everything that exists today was created before the gate and is real.
        // Stamp the review as the row's own creation so the audit trail does
        // not claim a review that never happened at a time it did not happen.
        $this->sql("
            UPDATE `oci_agencies`
            SET `status` = 'approved',
                `applied_at` = `created_at`,
                `reviewed_at` = `created_at`,
                `review_note` = 'Pre-dates the partnership approval gate; grandfathered by migration.'
            WHERE `status` = 'pending'
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_agency_applications` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `company_name` VARCHAR(200) NOT NULL,
                `website` VARCHAR(255) NULL DEFAULT NULL,
                `contact_email` VARCHAR(255) NULL DEFAULT NULL,
                `client_count` VARCHAR(30) NULL DEFAULT NULL COMMENT 'self-reported band, e.g. 1-5, 6-20, 21-50, 50+',
                `pitch` TEXT NULL DEFAULT NULL COMMENT 'what they manage and why, free text',
                `status` ENUM('pending','approved','rejected','withdrawn') NOT NULL DEFAULT 'pending',
                `review_note` VARCHAR(500) NULL DEFAULT NULL,
                `reviewed_by` INT UNSIGNED NULL DEFAULT NULL,
                `reviewed_at` DATETIME NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_oci_agapp_user_status` (`user_id`, `status`),
                INDEX `idx_oci_agapp_queue` (`status`, `created_at`),
                CONSTRAINT `fk_oci_agapp_user` FOREIGN KEY (`user_id`) REFERENCES `oci_users` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_oci_agapp_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `oci_users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropIfExists('oci_agency_applications');

        $this->sql('
            ALTER TABLE `oci_agencies`
            DROP FOREIGN KEY `fk_oci_agency_reviewer`
        ');

        $this->sql('
            ALTER TABLE `oci_agencies`
            DROP INDEX `idx_oci_agency_status`,
            DROP COLUMN `review_note`,
            DROP COLUMN `reviewed_by`,
            DROP COLUMN `reviewed_at`,
            DROP COLUMN `applied_at`,
            DROP COLUMN `status`
        ');
    }
}
