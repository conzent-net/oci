<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Add health-monitoring columns to oci_scan_servers.
 *
 * The admin Servers page derives online/offline from last_heartbeat_at, but
 * nothing wrote it automatically — a registered scanner always looked Offline.
 * These columns let ServerHealthService (run from the scheduler) poll each
 * server's /health endpoint, record every attempt, count consecutive misses,
 * and email a downtime alert exactly once per outage (down_alerted_at is the
 * de-dup latch: set when the "down" mail goes out, cleared on recovery).
 */
final class Version20260706_002_AddScanServerHealthColumns extends Migration
{
    public function getDescription(): string
    {
        return 'Add health-monitoring columns to oci_scan_servers';
    }

    public function up(): void
    {
        $this->sql("
            ALTER TABLE `oci_scan_servers`
                ADD COLUMN IF NOT EXISTS `last_health_check_at` DATETIME NULL DEFAULT NULL
                    COMMENT 'Last time the health poll ran, regardless of outcome' AFTER `last_heartbeat_at`,
                ADD COLUMN IF NOT EXISTS `consecutive_failures` SMALLINT UNSIGNED NOT NULL DEFAULT 0
                    COMMENT 'Consecutive failed health checks; reset to 0 on success' AFTER `last_health_check_at`,
                ADD COLUMN IF NOT EXISTS `down_alerted_at` DATETIME NULL DEFAULT NULL
                    COMMENT 'Set when a downtime alert email was sent; NULL once recovered' AFTER `consecutive_failures`
        ");
    }

    public function down(): void
    {
        $this->sql("
            ALTER TABLE `oci_scan_servers`
                DROP COLUMN IF EXISTS `down_alerted_at`,
                DROP COLUMN IF EXISTS `consecutive_failures`,
                DROP COLUMN IF EXISTS `last_health_check_at`
        ");
    }
}
