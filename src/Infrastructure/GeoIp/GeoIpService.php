<?php

declare(strict_types=1);

namespace OCI\Infrastructure\GeoIp;

use GeoIp2\Database\Reader;
use Psr\Log\LoggerInterface;

/**
 * Resolves visitor IP to country using a multi-layer lookup:
 *
 *   1. Local/private IP → "local"
 *   2. MaxMind GeoLite2 → country (if DB present and IP found)
 *   3. DB cache (oci_ip_geolocation) by SHA-256(ip) → cached result
 *   4. ipregistry API → fetches + caches → country
 *   5. All failed → "unknown"
 */
final class GeoIpService
{
    /** EEA + UK country codes (ISO 3166-1 alpha-2, lowercase) */
    private const EU_COUNTRIES = [
        'at', 'be', 'bg', 'hr', 'cy', 'cz', 'dk', 'ee', 'fi', 'fr',
        'de', 'gr', 'hu', 'ie', 'it', 'lv', 'lt', 'lu', 'mt', 'nl',
        'pl', 'pt', 'ro', 'sk', 'si', 'es', 'se',
        // EEA (non-EU)
        'is', 'li', 'no',
        // UK (post-Brexit, still uses GDPR-equivalent)
        'gb',
    ];

    private ?Reader $reader = null;
    private string $dbPath;

    public function __construct(
        private readonly IpGeolocationRepository $geoRepo,
        private readonly LoggerInterface $logger,
        private readonly string $ipregistryApiKey = '',
        string $storagePath = '',
    ) {
        $this->dbPath = $storagePath !== ''
            ? $storagePath
            : dirname(__DIR__, 3) . '/storage/geoip/GeoLite2-Country.mmdb';

        if (file_exists($this->dbPath)) {
            try {
                $this->reader = new Reader($this->dbPath);
            } catch (\Throwable) {
                // Corrupted DB — fall through to fallback
            }
        }
    }

    /**
     * @return array{country: string, in_eu: bool, source: string}
     */
    public function lookup(string $ip): array
    {
        // 1. Skip local/private IPs
        if ($this->isLocalIp($ip)) {
            return [
                'country' => 'local',
                'in_eu' => false,
                'source' => 'local',
            ];
        }

        // 2. Try MaxMind
        if ($this->reader !== null) {
            $result = $this->lookupMaxMind($ip);
            if ($result['country'] !== 'unknown') {
                return $result;
            }
        }

        // 3. Check DB cache by hashed IP
        $ipHash = hash('sha256', $ip);
        $cached = $this->geoRepo->findByIpHash($ipHash);

        if ($cached !== null) {
            return [
                'country' => $cached['country_code'],
                'in_eu' => $cached['in_eu'],
                'source' => 'cache',
            ];
        }

        // 4. Call ipregistry API (only if key is configured)
        if ($this->ipregistryApiKey !== '') {
            $apiResult = $this->lookupIpregistry($ip);
            if ($apiResult !== null) {
                // Cache the result
                $this->geoRepo->save($ipHash, $apiResult['country'], $apiResult['in_eu'], 'ipregistry');

                return [
                    'country' => $apiResult['country'],
                    'in_eu' => $apiResult['in_eu'],
                    'source' => 'ipregistry',
                ];
            }
        }

        // 5. All failed
        return [
            'country' => 'unknown',
            'in_eu' => false,
            'source' => 'none',
        ];
    }

    public function isAvailable(): bool
    {
        return $this->reader !== null;
    }

    /**
     * @return array{country: string, in_eu: bool, source: string}
     */
    private function lookupMaxMind(string $ip): array
    {
        try {
            $record = $this->reader->country($ip);
            $country = strtolower($record->country->isoCode ?? 'unknown');

            return [
                'country' => $country,
                'in_eu' => \in_array($country, self::EU_COUNTRIES, true),
                'source' => 'maxmind',
            ];
        } catch (\Throwable) {
            return [
                'country' => 'unknown',
                'in_eu' => false,
                'source' => 'maxmind_error',
            ];
        }
    }

    /**
     * @return array{country: string, in_eu: bool}|null
     */
    private function lookupIpregistry(string $ip): ?array
    {
        $url = 'https://api.ipregistry.co/' . urlencode($ip) . '?key=' . urlencode($this->ipregistryApiKey);

        try {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 5,
                    'header' => "Accept: application/json\r\n",
                ],
            ]);

            $response = @file_get_contents($url, false, $context);

            if ($response === false) {
                $this->logger->warning('ipregistry API request failed', ['ip_hash' => hash('sha256', $ip)]);
                return null;
            }

            $data = json_decode($response, true);

            if (!is_array($data) || !isset($data['location']['country']['code'])) {
                $this->logger->warning('ipregistry API returned unexpected response', ['ip_hash' => hash('sha256', $ip)]);
                return null;
            }

            $country = strtolower($data['location']['country']['code']);
            $inEu = $data['location']['in_eu'] ?? \in_array($country, self::EU_COUNTRIES, true);

            return [
                'country' => $country,
                'in_eu' => (bool) $inEu,
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('ipregistry API error', [
                'ip_hash' => hash('sha256', $ip),
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function isLocalIp(string $ip): bool
    {
        if ($ip === '' || $ip === '127.0.0.1' || $ip === '::1' || $ip === 'localhost') {
            return true;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
