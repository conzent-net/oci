<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Create oci_cookie_observations table for aggregated client-side cookie
 * detection data, and add source/consent_phase columns to oci_scan_cookies.
 *
 * Cookie observations store one row per cookie per site per day with counters
 * for pre-consent and post-consent sightings. This supports high-volume beacon
 * ingestion without creating one row per visitor.
 */
final class Version20260310_001_CreateCookieObservationsTable extends Migration
{
    public function getDescription(): string
    {
        return 'Create oci_cookie_observations table and add source/consent_phase to oci_scan_cookies';
    }

    public function up(): void
    {
        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_cookie_observations` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `site_id` INT UNSIGNED NOT NULL,
                `cookie_name` VARCHAR(255) NOT NULL,
                `cookie_domain` VARCHAR(255) NULL DEFAULT NULL,
                `observation_date` DATE NOT NULL,
                `pre_consent_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `post_consent_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `total_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `category_slug` VARCHAR(60) NULL DEFAULT NULL,
                `first_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_cookie_obs` (`site_id`, `cookie_name`, `observation_date`),
                INDEX `idx_obs_site_date` (`site_id`, `observation_date`),
                CONSTRAINT `fk_obs_site` FOREIGN KEY (`site_id`)
                    REFERENCES `oci_sites` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            ALTER TABLE `oci_scan_cookies`
                ADD COLUMN `source` VARCHAR(10) NOT NULL DEFAULT 'server'
                    COMMENT 'server, client' AFTER `found_on_url`,
                ADD COLUMN `consent_phase` VARCHAR(15) NULL DEFAULT NULL
                    COMMENT 'pre_consent, post_consent' AFTER `source`
        ");
    }

    public function down(): void
    {
        $this->sql("ALTER TABLE `oci_scan_cookies` DROP COLUMN `source`, DROP COLUMN `consent_phase`");
        $this->dropIfExists('oci_cookie_observations');
    }
}
