<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Track installer phone-home pings (install vs update).
 */
final class Version20260326_002_CreateInstallEvents extends Migration
{
    public function getDescription(): string
    {
        return 'Create oci_install_events table for installer telemetry';
    }

    public function up(): void
    {
        $this->sql("CREATE TABLE IF NOT EXISTS `oci_install_events` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `event` ENUM('install','update') NOT NULL,
            `ip_hash` VARCHAR(64) NULL COMMENT 'SHA-256 of IP for unique-ish counting without storing PII',
            `country` VARCHAR(2) NULL,
            `version` VARCHAR(20) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_event` (`event`),
            INDEX `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        $this->dropIfExists('oci_install_events');
    }
}
