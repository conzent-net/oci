<?php

declare(strict_types=1);

namespace OCI\Identity\Service;

use Doctrine\DBAL\Connection;

/**
 * Fixed-window rate limiter backed by oci_rate_limits.
 *
 * DB-backed on purpose: the app runs without Redis/APCu, sessions are useless
 * against bots (a new session per request is free), and the thing being
 * protected — the signup endpoint sending real SES email — is already a DB
 * write path, so one extra upsert is noise.
 *
 * Fixed window rather than sliding: at these limits (single digits per
 * quarter hour) the burst-at-the-boundary weakness is irrelevant, and the
 * whole counter is one atomic INSERT ... ON DUPLICATE KEY UPDATE.
 */
final class RateLimiter
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    /**
     * Count a hit against $bucket and report whether it is still within
     * $limit hits per $windowSeconds. The hit is counted before the check,
     * so callers simply gate on the return value.
     */
    public function allow(string $bucket, int $limit, int $windowSeconds): bool
    {
        $now = time();
        $cutoff = $now - $windowSeconds;

        // Atomic: expired window resets to 1, live window increments.
        // `hits` is assigned before `window_start` (MySQL evaluates SET
        // clauses left to right), so its IF() still sees the OLD window_start.
        $this->db->executeStatement(
            'INSERT INTO oci_rate_limits (bucket, window_start, hits)
             VALUES (:bucket, :now, 1)
             ON DUPLICATE KEY UPDATE
                 hits = IF(window_start <= :cutoff, 1, hits + 1),
                 window_start = IF(window_start <= :cutoff, :now, window_start)',
            ['bucket' => $bucket, 'now' => $now, 'cutoff' => $cutoff],
        );

        $hits = (int) $this->db->fetchOne(
            'SELECT hits FROM oci_rate_limits WHERE bucket = ?',
            [$bucket],
        );

        // Opportunistic garbage collection — roughly 1% of calls sweep rows
        // whose window closed more than a day ago. Keeps the table tiny
        // without a cron.
        if (random_int(1, 100) === 1) {
            $this->db->executeStatement(
                'DELETE FROM oci_rate_limits WHERE window_start < ?',
                [$now - 86400],
            );
        }

        return $hits <= $limit;
    }
}
