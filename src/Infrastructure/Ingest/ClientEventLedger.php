<?php

declare(strict_types=1);

namespace OCI\Infrastructure\Ingest;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class ClientEventLedger implements ClientEventLedgerInterface
{
    /** Oldest client timestamp accepted — matches the client queue TTL plus slack. */
    public const MAX_AGE_DAYS = 35;

    /** Forward clock skew tolerated on client timestamps, in seconds. */
    public const MAX_SKEW_SECONDS = 300;

    public function __construct(
        private readonly Connection $db,
    ) {}

    /**
     * Validate a client-supplied event id. Old bundles send none; anything
     * malformed is treated as absent so the request still processes (without
     * dedupe) instead of being rejected.
     */
    public static function normalizeEventId(mixed $raw): ?string
    {
        if (!\is_string($raw)) {
            return null;
        }

        return preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $raw) === 1 ? $raw : null;
    }

    /**
     * Validate a client-supplied unix timestamp (seconds) of when the event
     * actually happened. Rejects anything more than MAX_SKEW_SECONDS in the
     * future or older than MAX_AGE_DAYS — a rejected value means "attribute
     * to the server clock", never a rejected request.
     */
    public static function normalizeOccurredAt(mixed $raw): ?string
    {
        if (!is_numeric($raw)) {
            return null;
        }

        $ts = (int) $raw;
        // Tolerate millisecond timestamps from older client builds
        if ($ts > 100000000000) {
            $ts = intdiv($ts, 1000);
        }

        $now = time();
        if ($ts > $now + self::MAX_SKEW_SECONDS || $ts < $now - self::MAX_AGE_DAYS * 86400) {
            return null;
        }

        return date('Y-m-d H:i:s', $ts);
    }

    public function claim(int $siteId, string $clientEventId, string $eventType, ?string $occurredAt): bool
    {
        $affected = $this->db->executeStatement(
            'INSERT IGNORE INTO oci_client_events (site_id, client_event_id, event_type, occurred_at)
             VALUES (:siteId, :eventId, :eventType, :occurredAt)',
            [
                'siteId' => $siteId,
                'eventId' => $clientEventId,
                'eventType' => $eventType,
                'occurredAt' => $occurredAt,
            ],
            [
                'siteId' => ParameterType::INTEGER,
            ],
        );

        return (int) $affected === 1;
    }

    public function purgeOlderThan(int $days = 45, int $batchSize = 5000): int
    {
        $total = 0;

        do {
            $deleted = (int) $this->db->executeStatement(
                'DELETE FROM oci_client_events
                 WHERE received_at < DATE_SUB(NOW(), INTERVAL :days DAY)
                 LIMIT :batch',
                ['days' => $days, 'batch' => $batchSize],
                ['days' => ParameterType::INTEGER, 'batch' => ParameterType::INTEGER],
            );
            $total += $deleted;
        } while ($deleted === $batchSize);

        return $total;
    }
}
