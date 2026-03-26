<?php

declare(strict_types=1);

namespace OCI\Scanning\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class ScanRepository implements ScanRepositoryInterface
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    // ── Scan CRUD ────────────────────────────────────────

    public function findById(int $scanId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM oci_scans WHERE id = :id',
            ['id' => $scanId],
        );

        return $row !== false ? $row : null;
    }

    public function createScan(array $data): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $data['created_at'] = $data['created_at'] ?? $now;
        $data['updated_at'] = $data['updated_at'] ?? $now;

        $this->db->insert('oci_scans', $data);

        return (int) $this->db->lastInsertId();
    }

    public function updateScan(int $scanId, array $data): void
    {
        $data['updated_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->db->update('oci_scans', $data, ['id' => $scanId]);
    }

    // ── Scan Queries ─────────────────────────────────────

    public function findBySite(int $siteId, int $limit = 50, int $offset = 0): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT s.*, srv.server_name
             FROM oci_scans s
             LEFT JOIN oci_scan_servers srv ON srv.id = s.server_id
             WHERE s.site_id = :siteId AND s.scan_status <> "initiated"
             ORDER BY s.id DESC
             LIMIT :limit OFFSET :offset',
            ['siteId' => $siteId, 'limit' => $limit, 'offset' => $offset],
            ['siteId' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );
    }

    public function countBySite(int $siteId): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM oci_scans WHERE site_id = :siteId AND scan_status <> "initiated"',
            ['siteId' => $siteId],
        );
    }

    public function getLastCompletedScan(int $siteId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM oci_scans WHERE site_id = :siteId AND scan_status = :status ORDER BY id DESC LIMIT 1',
            ['siteId' => $siteId, 'status' => 'completed'],
        );

        return $row !== false ? $row : null;
    }

    public function getNextScheduledScan(int $siteId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM oci_scans WHERE site_id = :siteId AND scan_status = :status ORDER BY id ASC LIMIT 1',
            ['siteId' => $siteId, 'status' => 'scheduled'],
        );

        return $row !== false ? $row : null;
    }

    public function hasActiveScan(int $siteId): bool
    {
        $count = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM oci_scans
             WHERE site_id = :siteId
             AND scan_status IN ('pending', 'queued', 'in_progress')",
            ['siteId' => $siteId],
        );

        return $count > 0;
    }

    public function getQueuedScans(int $limit = 10): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT s.*, st.domain
             FROM oci_scans s
             INNER JOIN oci_sites st ON st.id = s.site_id AND st.deleted_at IS NULL
             WHERE s.scan_status = 'queued'
             ORDER BY s.created_at ASC
             LIMIT :limit",
            ['limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );
    }

    public function getInProgressScans(int $limit = 50): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT s.*, st.domain
             FROM oci_scans s
             INNER JOIN oci_sites st ON st.id = s.site_id
             WHERE s.scan_status = 'in_progress'
             ORDER BY s.started_at ASC
             LIMIT :limit",
            ['limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );
    }

    public function getDueScheduledScans(int $limit = 10): array
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d');
        $time = (new \DateTimeImmutable())->format('H:i:s');

        return $this->db->fetchAllAssociative(
            "SELECT s.*, st.domain
             FROM oci_scans s
             INNER JOIN oci_sites st ON st.id = s.site_id AND st.deleted_at IS NULL
             WHERE s.scan_status = 'scheduled'
             AND (
                 (s.frequency = 'once' AND s.schedule_date <= :today AND s.schedule_time <= :now_time)
                 OR (s.frequency = 'monthly' AND DAY(s.schedule_date) = DAY(:today2) AND s.schedule_time <= :now_time2)
             )
             ORDER BY s.created_at ASC
             LIMIT :limit",
            ['today' => $now, 'now_time' => $time, 'today2' => $now, 'now_time2' => $time, 'limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );
    }

    public function getStaleScans(string $status, int $hours = 2, int $limit = 10): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT * FROM oci_scans
             WHERE scan_status = :status
             AND updated_at < DATE_SUB(NOW(), INTERVAL :hours HOUR)
             ORDER BY updated_at ASC
             LIMIT :limit",
            ['status' => $status, 'hours' => $hours, 'limit' => $limit],
            ['hours' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER],
        );
    }

    // ── Scan URLs ────────────────────────────────────────

    public function createScanUrls(int $scanId, array $urls): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        foreach ($urls as $url) {
            $this->db->insert('oci_scan_urls', [
                'scan_id' => $scanId,
                'url' => $url,
                'status' => 'pending',
                'created_at' => $now,
            ]);
        }
    }

    public function getScanUrls(int $scanId, ?string $status = null): array
    {
        $sql = 'SELECT * FROM oci_scan_urls WHERE scan_id = :scanId';
        $params = ['scanId' => $scanId];

        if ($status !== null) {
            $sql .= ' AND status = :status';
            $params['status'] = $status;
        }

        $sql .= ' ORDER BY id ASC';

        return $this->db->fetchAllAssociative($sql, $params);
    }

    public function updateScanUrl(int $urlId, array $data): void
    {
        $this->db->update('oci_scan_urls', $data, ['id' => $urlId]);
    }

    public function countScanUrlsByStatus(int $scanId, string $status): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM oci_scan_urls WHERE scan_id = :scanId AND status = :status',
            ['scanId' => $scanId, 'status' => $status],
        );
    }

    // ── Scan Cookies ─────────────────────────────────────

    public function addScanCookie(int $scanId, array $cookie): int
    {
        $cookie['scan_id'] = $scanId;
        $this->db->insert('oci_scan_cookies', $cookie);

        return (int) $this->db->lastInsertId();
    }

    public function addScanCookies(int $scanId, array $cookies): void
    {
        foreach ($cookies as $cookie) {
            $this->addScanCookie($scanId, $cookie);
        }
    }

    public function getScanCookies(int $scanId): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT * FROM oci_scan_cookies WHERE scan_id = :scanId ORDER BY cookie_name ASC',
            ['scanId' => $scanId],
        );
    }

    public function countScanCookies(int $scanId): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM oci_scan_cookies WHERE scan_id = :scanId',
            ['scanId' => $scanId],
        );
    }

    public function getScanCookieBreakdown(int $scanId): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT COALESCE(category_slug, \'unclassified\') AS category_slug, COUNT(*) AS total
             FROM oci_scan_cookies
             WHERE scan_id = :scanId
             GROUP BY category_slug
             ORDER BY total DESC',
            ['scanId' => $scanId],
        );
    }

    // ── Scan Servers ─────────────────────────────────────

    public function getActiveScanServer(): ?array
    {
        // Prefer servers with lower load, then by last heartbeat
        $row = $this->db->fetchAssociative(
            'SELECT * FROM oci_scan_servers
             WHERE is_active = 1 AND current_load < max_concurrent
             ORDER BY current_load ASC, last_heartbeat_at DESC
             LIMIT 1',
        );

        return $row !== false ? $row : null;
    }

    public function getAllScanServers(): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT * FROM oci_scan_servers ORDER BY id ASC',
        );
    }

    public function createScanServer(array $data): int
    {
        $this->db->insert('oci_scan_servers', $data);

        return (int) $this->db->lastInsertId();
    }

    public function updateScanServer(int $serverId, array $data): void
    {
        $this->db->update('oci_scan_servers', $data, ['id' => $serverId]);
    }

    public function deleteScanServer(int $serverId): void
    {
        $this->db->delete('oci_scan_servers', ['id' => $serverId]);
    }

    public function updateServerHeartbeat(int $serverId): void
    {
        $this->db->update('oci_scan_servers', [
            'last_heartbeat_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], ['id' => $serverId]);
    }

    // ── Beacons ──────────────────────────────────────────

    public function upsertBeacon(int $siteId, array $beacon): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $url = $beacon['beacon_url'];

        // Check if beacon already exists for this site
        $existing = $this->db->fetchAssociative(
            'SELECT id FROM oci_beacons WHERE site_id = :siteId AND beacon_url = :url',
            ['siteId' => $siteId, 'url' => $url],
        );

        if ($existing !== false) {
            $this->db->update('oci_beacons', ['last_seen_at' => $now], ['id' => (int) $existing['id']]);

            return (int) $existing['id'];
        }

        $this->db->insert('oci_beacons', [
            'site_id' => $siteId,
            'beacon_url' => $url,
            'beacon_type' => $beacon['beacon_type'] ?? null,
            'last_seen_at' => $now,
            'created_at' => $now,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function linkBeaconToScan(int $beaconId, int $scanId): void
    {
        $this->db->insert('oci_beacon_scans', [
            'beacon_id' => $beaconId,
            'scan_id' => $scanId,
            'found_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function getBeaconsByScan(int $scanId): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT b.*, bs.found_at
             FROM oci_beacons b
             INNER JOIN oci_beacon_scans bs ON bs.beacon_id = b.id
             WHERE bs.scan_id = :scanId
             ORDER BY b.beacon_url ASC',
            ['scanId' => $scanId],
        );
    }

    public function countBeaconsByScan(int $scanId): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM oci_beacon_scans WHERE scan_id = :scanId',
            ['scanId' => $scanId],
        );
    }

    // ── Cookie Observations ───────────────────────────────

    public function upsertCookieObservation(int $siteId, string $date, string $phase, array $data): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $preCount = $phase === 'pre_consent' ? 1 : 0;
        $postCount = $phase === 'post_consent' ? 1 : 0;

        $this->db->executeStatement(
            'INSERT INTO oci_cookie_observations
                (site_id, cookie_name, cookie_domain, observation_date,
                 pre_consent_count, post_consent_count, total_count,
                 category_slug, first_seen_at, last_seen_at)
             VALUES (:siteId, :name, :domain, :date,
                     :pre, :post, 1,
                     :category, :now, :now)
             ON DUPLICATE KEY UPDATE
                pre_consent_count = pre_consent_count + VALUES(pre_consent_count),
                post_consent_count = post_consent_count + VALUES(post_consent_count),
                total_count = total_count + 1,
                category_slug = COALESCE(VALUES(category_slug), category_slug),
                last_seen_at = VALUES(last_seen_at)',
            [
                'siteId' => $siteId,
                'name' => $data['cookie_name'],
                'domain' => $data['cookie_domain'] ?? null,
                'date' => $date,
                'pre' => $preCount,
                'post' => $postCount,
                'category' => $data['category_slug'] ?? null,
                'now' => $now,
            ],
        );
    }

    public function upsertCookieObservationBatch(int $siteId, string $date, array $observations): void
    {
        foreach ($observations as $obs) {
            $this->upsertCookieObservation($siteId, $date, $obs['phase'] ?? 'pre_consent', $obs);
        }
    }

    public function getPreConsentObservations(int $siteId, string $startDate, string $endDate): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT cookie_name, cookie_domain, category_slug,
                    SUM(pre_consent_count) AS total_pre_consent,
                    SUM(post_consent_count) AS total_post_consent,
                    MIN(first_seen_at) AS first_seen,
                    MAX(last_seen_at) AS last_seen
             FROM oci_cookie_observations
             WHERE site_id = :siteId
               AND observation_date BETWEEN :startDate AND :endDate
               AND pre_consent_count > 0
             GROUP BY cookie_name, cookie_domain, category_slug
             ORDER BY total_pre_consent DESC',
            ['siteId' => $siteId, 'startDate' => $startDate, 'endDate' => $endDate],
            ['siteId' => ParameterType::INTEGER],
        );
    }
}
