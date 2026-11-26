<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Keep the readings behind the public status page.
 *
 * The status feed only ever held the current state, so the page could not
 * answer "was it down last month". The scheduler writes one sample every
 * five minutes; those are rolled up per day and component here, and the
 * incidents table carries the human reason for anything that was not
 * operational. A planned update is an incident of its own kind: it is not
 * counted as downtime and never colours a day red.
 */
final class Version20260921_001_StatusHistory extends Migration
{
    public function getDescription(): string
    {
        return 'Record status readings per day and the incidents behind them';
    }

    public function up(): void
    {
        $this->sql("CREATE TABLE IF NOT EXISTS `oci_status_days` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `day` DATE NOT NULL,
            `component` VARCHAR(40) NOT NULL COMMENT 'component key, or `overall`',
            `samples` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `operational` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `degraded` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `outage` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_day_component` (`day`, `component`),
            INDEX `idx_day` (`day`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->sql("CREATE TABLE IF NOT EXISTS `oci_status_incidents` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `started_at` DATETIME NOT NULL,
            `ended_at` DATETIME NULL,
            `kind` VARCHAR(20) NOT NULL DEFAULT 'outage' COMMENT 'update | degraded | outage',
            `component` VARCHAR(40) NULL COMMENT 'null = the whole service',
            `summary` VARCHAR(200) NOT NULL,
            `detail` TEXT NULL,
            `is_published` TINYINT(1) NOT NULL DEFAULT 1,
            `source` VARCHAR(40) NOT NULL DEFAULT 'manual' COMMENT 'manual | deployment | monitor',
            `external_ref` VARCHAR(100) NULL COMMENT 'deployment id or push sha, so a backfill cannot duplicate',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_source_ref` (`source`, `external_ref`),
            INDEX `idx_started` (`started_at`),
            INDEX `idx_kind` (`kind`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        $this->dropIfExists('oci_status_incidents');
        $this->dropIfExists('oci_status_days');
    }
}
