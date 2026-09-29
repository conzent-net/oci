<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Drop the commission and payout machinery.
 *
 * Conzent pays **no recurring commission, ever** — a settled product decision,
 * not a temporary state. The accrual side was never built: nothing in the
 * codebase has ever written a row to `oci_agency_commissions`, and the two read
 * methods that existed fed a dashboard route that had already been made
 * unreachable. Under client-direct billing there is no commission to accrue at
 * all, because the agency never buys anything.
 *
 * The banking columns are the part that actually matters. `iban`, `swift`,
 * `account_reg` and `account_number` are financial PII, and the only reason
 * they existed was to pay commissions that will never be paid. Holding
 * somebody's bank details with no purpose is not a tidiness problem, it is a
 * data-protection one — the lawful basis went away with the payout model.
 *
 * **Deliberately a separate migration from the rest of the agency work.** Every
 * other migration in this batch is additive and safely reversible; this one is
 * neither. Keeping it apart means the agency feature can ship, and be rolled
 * back, without this going with it.
 *
 * `down()` restores the SCHEMA, never the data. A drop is not undone by a
 * CREATE. Verified empty on production data before writing this (0 commission
 * rows, 0 agencies with banking details, 0 with a commission percentage), so
 * on the current dataset there is nothing to lose — but that is a fact about
 * today, and anyone re-running this later should check it again first.
 */
final class Version20260906_001_RemoveCommissionMachinery extends Migration
{
    public function getDescription(): string
    {
        return 'Drop oci_agency_commissions and the unused payout banking columns';
    }

    public function up(): void
    {
        // Refuse rather than destroy. If a future install has actually accrued
        // commissions or stored bank details, dropping them silently during a
        // deploy is the wrong answer — someone needs to decide.
        $rows = (int) $this->db->fetchOne('SELECT COUNT(*) FROM `oci_agency_commissions`');

        if ($rows > 0) {
            throw new \RuntimeException(sprintf(
                'oci_agency_commissions holds %d row(s). This migration drops the table, so it refuses to run '
                . 'while there is data in it. Export what you need, empty the table, then re-run.',
                $rows,
            ));
        }

        $banking = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM `oci_agencies`
             WHERE COALESCE(`iban`, '') <> '' OR COALESCE(`swift`, '') <> ''
                OR COALESCE(`account_reg`, '') <> '' OR COALESCE(`account_number`, '') <> ''",
        );

        if ($banking > 0) {
            throw new \RuntimeException(sprintf(
                '%d agency row(s) still hold bank details. Those columns are about to be dropped. '
                . 'Confirm the details are recorded wherever they are actually needed, clear them, then re-run.',
                $banking,
            ));
        }

        $this->dropIfExists('oci_agency_commissions');

        $this->sql('
            ALTER TABLE `oci_agencies`
            DROP COLUMN `iban`,
            DROP COLUMN `swift`,
            DROP COLUMN `account_reg`,
            DROP COLUMN `account_number`,
            DROP COLUMN `commission_pct`
        ');
    }

    public function down(): void
    {
        $this->sql("
            ALTER TABLE `oci_agencies`
            ADD COLUMN `iban` VARCHAR(50) NULL DEFAULT NULL,
            ADD COLUMN `swift` VARCHAR(20) NULL DEFAULT NULL,
            ADD COLUMN `account_reg` VARCHAR(50) NULL DEFAULT NULL,
            ADD COLUMN `account_number` VARCHAR(50) NULL DEFAULT NULL,
            ADD COLUMN `commission_pct` TINYINT UNSIGNED NOT NULL DEFAULT 0
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_agency_commissions` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `agency_id` INT UNSIGNED NOT NULL,
                `subscription_id` INT UNSIGNED NOT NULL,
                `amount` DECIMAL(10,2) NOT NULL,
                `currency` VARCHAR(10) NOT NULL DEFAULT 'EUR',
                `status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending, paid, cancelled',
                `paid_at` DATETIME NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_oci_agcomm_agency` (`agency_id`),
                INDEX `idx_oci_agcomm_sub` (`subscription_id`),
                CONSTRAINT `fk_oci_agcomm_agency` FOREIGN KEY (`agency_id`) REFERENCES `oci_agencies` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_oci_agcomm_sub` FOREIGN KEY (`subscription_id`) REFERENCES `oci_subscriptions` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}
