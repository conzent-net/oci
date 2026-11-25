<?php

declare(strict_types=1);

namespace OCI\Agency\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final class IssueStateRepository implements IssueStateRepositoryInterface
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function forSites(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM oci_site_issues WHERE site_id IN (:siteIds)',
            ['siteIds' => array_map('intval', $siteIds)],
            ['siteIds' => ArrayParameterType::INTEGER],
        );

        $byKey = [];

        foreach ($rows as $row) {
            $byKey[$row['site_id'] . ':' . $row['issue_key']] = $row;
        }

        return $byKey;
    }

    public function decide(
        int $siteId,
        string $issueKey,
        string $severity,
        string $state,
        ?int $magnitude,
        ?string $evidenceHash,
        ?string $until,
        ?string $note,
        int $actorId,
    ): void {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // ON DUPLICATE KEY because the deriver runs on every page load: a
        // second decision on the same issue must replace the first, not
        // accumulate a second row for the same standing problem.
        //
        // first_seen_at is deliberately NOT overwritten — how long an issue has
        // been open is the age component of its ranking, and resetting it on
        // every snooze would make a long-ignored problem look new.
        $this->db->executeStatement(
            'INSERT INTO oci_site_issues
                (site_id, issue_key, severity, magnitude, state, evidence_hash, snoozed_until,
                 note, acted_by, acted_at, first_seen_at, last_seen_at, resolved_at)
             VALUES (:siteId, :issueKey, :severity, :magnitude, :state, :hash, :until,
                     :note, :actor, :now, :now, :now, NULL)
             ON DUPLICATE KEY UPDATE
                severity = VALUES(severity),
                magnitude = VALUES(magnitude),
                state = VALUES(state),
                evidence_hash = VALUES(evidence_hash),
                snoozed_until = VALUES(snoozed_until),
                note = VALUES(note),
                acted_by = VALUES(acted_by),
                acted_at = VALUES(acted_at),
                last_seen_at = VALUES(last_seen_at),
                resolved_at = NULL',
            [
                'siteId' => $siteId,
                'issueKey' => $issueKey,
                'severity' => $severity,
                'magnitude' => $magnitude,
                'state' => $state,
                'hash' => $evidenceHash,
                'until' => $until,
                'note' => $note,
                'actor' => $actorId > 0 ? $actorId : null,
                'now' => $now,
            ],
        );
    }

    public function reopen(int $siteId, string $issueKey): bool
    {
        return $this->db->executeStatement(
            "UPDATE oci_site_issues
             SET state = 'open', snoozed_until = NULL, evidence_hash = NULL, resolved_at = NULL
             WHERE site_id = :siteId AND issue_key = :issueKey",
            ['siteId' => $siteId, 'issueKey' => $issueKey],
        ) > 0;
    }

    public function resolveDisappeared(array $siteIds, array $stillOpen): int
    {
        if ($siteIds === []) {
            return 0;
        }

        $sql = "UPDATE oci_site_issues
                SET state = 'resolved', resolved_at = NOW(), alerted_at = NULL
                WHERE site_id IN (:siteIds)
                  AND state <> 'resolved'";

        $params = ['siteIds' => array_map('intval', $siteIds)];
        $types = ['siteIds' => ArrayParameterType::INTEGER];

        if ($stillOpen !== []) {
            $sql .= ' AND CONCAT(site_id, \':\', issue_key) NOT IN (:stillOpen)';
            $params['stillOpen'] = $stillOpen;
            $types['stillOpen'] = ArrayParameterType::STRING;
        }

        // alerted_at is cleared on resolve so a recurrence alerts once more.
        // Without that, a problem that comes back stays silent forever because
        // it was alerted about months ago.
        return (int) $this->db->executeStatement($sql, $params, $types);
    }
}
