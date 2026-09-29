<?php

declare(strict_types=1);

namespace OCI\Infrastructure\Ingest;

/**
 * Idempotency ledger for client-originated banner events.
 *
 * The consent script's offline queue can deliver the same event more than
 * once (the pagehide sendBeacon flush cannot observe its own outcome, so the
 * queued copy is retried until a confirmed 2xx). Every ingest endpoint that
 * carries a client_event_id claims it here first; only the first claim wins
 * and gets processed, every later arrival is acknowledged without effect.
 */
interface ClientEventLedgerInterface
{
    /**
     * Claim an event id for a site. Returns true when this call recorded the
     * event (process it), false when it was already claimed (a replay — skip
     * processing and acknowledge, so the client drops its queue entry).
     */
    public function claim(int $siteId, string $clientEventId, string $eventType, ?string $occurredAt): bool;

    /**
     * Delete ledger rows older than the retention window, in batches.
     * The ledger only needs to outlive the client queue TTL (30 days).
     *
     * @return int Rows deleted
     */
    public function purgeOlderThan(int $days = 45, int $batchSize = 5000): int;
}
