<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Create oci_consent_daily_stats for pre-aggregated consent analytics.
 *
 * One row per site per day per variant. Counters are incremented via UPSERT
 * on every consent action, avoiding expensive GROUP BY on the large oci_consents table.
 */
final class Version20260310_003_CreateConsentDailyStats extends Migration
{
    public function getDescription(): string
    {
        return 'Create oci_consent_daily_stats table for consent trend analytics';
    }

    public function up(): void
    {
        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_consent_daily_stats` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `site_id` INT UNSIGNED NOT NULL,
                `stat_date` DATE NOT NULL,
                `variant_id` INT UNSIGNED NULL DEFAULT NULL,
                `total_consents` INT UNSIGNED NOT NULL DEFAULT 0,
                `accepted` INT UNSIGNED NOT NULL DEFAULT 0,
                `rejected` INT UNSIGNED NOT NULL DEFAULT 0,
                `partially_accepted` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_consent_daily` (`site_id`, `stat_date`, `variant_id`),
                INDEX `idx_cds_site_date` (`site_id`, `stat_date`),
                CONSTRAINT `fk_cds_site` FOREIGN KEY (`site_id`)
                    REFERENCES `oci_sites` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropIfExists('oci_consent_daily_stats');
    }
}
