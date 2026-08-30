<?php

declare(strict_types=1);

namespace OCI\Admin\Service;

use Doctrine\DBAL\Connection;
use OCI\Scanning\Repository\ScanRepositoryInterface;
use Predis\Client as RedisClient;
use Psr\Log\LoggerInterface;

/**
 * Writes the public service-status feed (var/status.json, served at
 * GET /status.json with CORS) that the getconzent.com status page reads.
 *
 * Deliberately customer-facing only: which parts of the service work,
 * never infrastructure internals (no hostnames, memory, queue depths).
 * The scheduler writes it every cycle, so the feed's own freshness IS the
 * scheduler check: a stale generated_at means background processing is
 * behind, an unreachable feed means the platform itself is down — the
 * status page renders both without this service having to say so.
 */
final class StatusReportService
{
    public const OPERATIONAL = 'operational';
    public const DEGRADED = 'degraded';
    public const OUTAGE = 'outage';

    public function __construct(
        private readonly Connection $db,
        private readonly RedisClient $redis,
        private readonly ScanRepositoryInterface $scanRepo,
        private readonly LoggerInterface $logger,
        private readonly string $basePath,
    ) {}

    /**
     * Build the payload and write it atomically.
     *
     * @return array<string, mixed> The written payload.
     */
    public function write(): array
    {
        $components = [
            $this->checkConsentCollection(),
            $this->checkBannerDelivery(),
            $this->checkDashboard(),
            $this->checkScanning(),
        ];

        $overall = self::OPERATIONAL;
        foreach ($components as $component) {
            if ($component['status'] === self::OUTAGE) {
                $overall = self::OUTAGE;
                break;
            }
            if ($component['status'] === self::DEGRADED) {
                $overall = self::DEGRADED;
            }
        }

        $payload = [
            'schema_version' => 1,
            'generated_at' => date('c'),
            'status' => $overall,
            'components' => $components,
        ];

        $path = $this->basePath . '/var/status.json';
        $tmp = $path . '.tmp';
        try {
            file_put_contents($tmp, json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            rename($tmp, $path);
        } catch (\Throwable $e) {
            $this->logger->warning('Status feed write failed: ' . $e->getMessage());
        }

        return $payload;
    }

    /** @return array<string, string> */
    private function checkConsentCollection(): array
    {
        $ok = true;
        $note = '';

        try {
            $this->db->fetchOne('SELECT 1');
        } catch (\Throwable) {
            $ok = false;
            $note = 'Consent storage is unavailable.';
        }

        try {
            $this->redis->ping();
        } catch (\Throwable) {
            $ok = false;
            $note = trim($note . ' Consent processing is unavailable.');
        }

        return [
            'key' => 'consent_collection',
            'label' => 'Consent collection',
            'status' => $ok ? self::OPERATIONAL : self::OUTAGE,
            'note' => $note,
        ];
    }

    /** @return array<string, string> */
    private function checkBannerDelivery(): array
    {
        // The loader and the per-site bundles are what every visitor's
        // browser fetches; their store being present and readable is the
        // server-side half of delivery (the CDN edge fronts it).
        $loader = is_file($this->basePath . '/public/c/consent.js');
        $store = is_dir($this->basePath . '/public/sites_data');

        return [
            'key' => 'banner_delivery',
            'label' => 'Consent banner delivery',
            'status' => ($loader && $store) ? self::OPERATIONAL : self::OUTAGE,
            'note' => ($loader && $store) ? '' : 'The consent script store is unavailable.',
        ];
    }

    /** @return array<string, string> */
    private function checkDashboard(): array
    {
        // The dashboard needs the same database the collection check probes;
        // report it as its own component because customers think in features,
        // not in shared dependencies.
        try {
            $this->db->fetchOne('SELECT 1');
            $status = self::OPERATIONAL;
            $note = '';
        } catch (\Throwable) {
            $status = self::OUTAGE;
            $note = 'The dashboard cannot reach its data.';
        }

        return [
            'key' => 'dashboard',
            'label' => 'Customer dashboard',
            'status' => $status,
            'note' => $note,
        ];
    }

    /** @return array<string, string> */
    private function checkScanning(): array
    {
        try {
            $servers = $this->scanRepo->getAllScanServers();
        } catch (\Throwable) {
            return [
                'key' => 'scanning',
                'label' => 'Cookie scanning',
                'status' => self::DEGRADED,
                'note' => 'Scanning status could not be determined.',
            ];
        }

        $active = 0;
        $up = 0;
        foreach ($servers as $server) {
            if ((int) ($server['is_active'] ?? 0) !== 1) {
                continue;
            }
            $active++;
            // A server is considered up unless the health poll has alerted it
            // down (the poll runs in the same scheduler cycle as this writer).
            if (($server['down_alerted_at'] ?? null) === null) {
                $up++;
            }
        }

        if ($active === 0) {
            return [
                'key' => 'scanning',
                'label' => 'Cookie scanning',
                'status' => self::DEGRADED,
                'note' => 'No scan capacity is currently online; scans are queued.',
            ];
        }

        if ($up === 0) {
            return [
                'key' => 'scanning',
                'label' => 'Cookie scanning',
                'status' => self::OUTAGE,
                'note' => 'Scanning is temporarily unavailable; queued scans resume automatically.',
            ];
        }

        if ($up < $active) {
            return [
                'key' => 'scanning',
                'label' => 'Cookie scanning',
                'status' => self::DEGRADED,
                'note' => 'Scanning runs at reduced capacity; scans may take longer than usual.',
            ];
        }

        return [
            'key' => 'scanning',
            'label' => 'Cookie scanning',
            'status' => self::OPERATIONAL,
            'note' => '',
        ];
    }
}
