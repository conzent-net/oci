<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Generic fixed-window rate-limit counters (see Identity\Service\RateLimiter).
 *
 * First consumer: POST /register/start-verification, which sends a real SES
 * email per accepted request — bot-submitted signups were hard-bouncing and
 * damaging SES sending reputation. Bucket format is "{purpose}:{key}",
 * e.g. "signup-start:203.0.113.7".
 */
final class Version20260827_001_CreateRateLimits extends Migration
{
    public function getDescription(): string
    {
        return 'Create oci_rate_limits for per-IP fixed-window rate limiting';
    }

    public function up(): void
    {
        $this->sql('
            CREATE TABLE IF NOT EXISTS oci_rate_limits (
                bucket VARCHAR(191) NOT NULL,
                window_start INT UNSIGNED NOT NULL,
                hits INT UNSIGNED NOT NULL DEFAULT 1,
                PRIMARY KEY (bucket),
                KEY idx_window_start (window_start)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->sql('DROP TABLE IF EXISTS oci_rate_limits');
    }
}
