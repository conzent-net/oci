<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Collapse duplicate oci_configuration rows that carry scope_id = NULL.
 *
 * `uq_oci_config` is UNIQUE (scope, scope_id, config_key), but MariaDB — like
 * MySQL — treats NULLs as distinct in a unique index. Every global and system
 * setting uses scope_id = NULL, so `INSERT … ON DUPLICATE KEY UPDATE` never
 * matched and inserted a fresh row instead.
 *
 * The consequence was not merely clutter. The scheduler reads its "last run"
 * stamps with fetchOne(), which returns the OLDEST duplicate, so the
 * "-23 hours" gate was permanently satisfied and the daily jobs fired on every
 * five-minute tick of their window. `email_verification_last_run` had 1,184
 * rows dating to 2026-05-26 and was running 24 times a day rather than once,
 * each run batching 500 addresses against a paid verification API.
 *
 * bin/oci now writes these through an UPDATE-then-INSERT helper. This migration
 * clears what the old pattern left behind, keeping the newest value.
 *
 * The index itself is deliberately not changed: making it dedupe NULLs would
 * mean replacing scope_id NULL with a sentinel, and every read in the codebase
 * matches on `scope_id IS NULL`. Correct writes are the cheaper guarantee.
 */
final class Version20260904_003_DedupeNullScopeConfig extends Migration
{
    public function getDescription(): string
    {
        return 'Collapse duplicate oci_configuration rows with a NULL scope_id';
    }

    public function up(): void
    {
        // Keep the highest id per (scope, config_key) — the most recent write —
        // and drop the rest. Done with a join rather than a subquery on the same
        // table, which MariaDB refuses inside DELETE.
        $this->sql('
            DELETE c FROM oci_configuration c
            JOIN (
                SELECT scope, config_key, MAX(id) AS keep_id
                  FROM oci_configuration
                 WHERE scope_id IS NULL
              GROUP BY scope, config_key
                HAVING COUNT(*) > 1
            ) newest
              ON newest.scope = c.scope
             AND newest.config_key = c.config_key
           WHERE c.scope_id IS NULL
             AND c.id < newest.keep_id
        ');
    }

    public function down(): void
    {
        // Deleting redundant duplicates is not meaningfully reversible, and
        // restoring them would reintroduce the bug.
    }
}
