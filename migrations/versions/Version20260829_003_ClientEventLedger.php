<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Replay-safe ingest for the banner's offline event queue.
 *
 * The consent script buffers consent/log/scan events in localStorage while
 * the origin is unreachable and replays them later — possibly twice (the
 * pagehide sendBeacon flush cannot confirm delivery, so the queue keeps the
 * entry until a confirmed 2xx). The ledger makes every replay idempotent:
 * one row per (site, client_event_id), claimed with INSERT IGNORE; a second
 * arrival claims nothing and is acknowledged without processing.
 *
 * oci_consents additionally records which client event produced a row and
 * when the visitor actually acted (occurred_at), so replayed consents count
 * in daily stats on the day they happened, not the day the server was back.
 */
final class Version20260829_003_ClientEventLedger extends Migration
{
    public function getDescription(): string
    {
        return 'Client event ledger for replay dedupe; provenance columns on oci_consents';
    }

    public function up(): void
    {
        $this->sql("CREATE TABLE IF NOT EXISTS `oci_client_events` (
            `site_id` INT UNSIGNED NOT NULL,
            `client_event_id` VARCHAR(64) NOT NULL,
            `event_type` VARCHAR(16) NOT NULL,
            `occurred_at` DATETIME NULL,
            `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`site_id`, `client_event_id`),
            INDEX `idx_client_events_received` (`received_at`),
            CONSTRAINT `fk_client_events_site` FOREIGN KEY (`site_id`)
                REFERENCES `oci_sites`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->sql('ALTER TABLE `oci_consents`
            ADD COLUMN IF NOT EXISTS `client_event_id` VARCHAR(64) NULL');

        $this->sql('ALTER TABLE `oci_consents`
            ADD COLUMN IF NOT EXISTS `occurred_at` DATETIME NULL');
    }

    public function down(): void
    {
        $this->sql('ALTER TABLE `oci_consents` DROP COLUMN IF EXISTS `occurred_at`');
        $this->sql('ALTER TABLE `oci_consents` DROP COLUMN IF EXISTS `client_event_id`');
        $this->dropIfExists('oci_client_events');
    }
}
