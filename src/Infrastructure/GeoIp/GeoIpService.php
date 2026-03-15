<?php

declare(strict_types=1);

namespace OCI\Infrastructure\GeoIp;

use GeoIp2\Database\Reader;

/**
 * Resolves visitor IP to country using MaxMind GeoLite2 Country database.
 *
 * Fallback: when no .mmdb file is present, uses a static EU country list
 * and defaults to "unknown" country — the banner still works, it just
 * can't do geo-targeted consent (GDPR vs CCPA per region).
 *
 * To enable full GeoIP:
 *   1. Sign up at https://dev.maxmind.com/geoip/geolite2-free-geolocation-data
 *   2. Download GeoLite2-Country.mmdb
 *   3. Place it at storage/geoip/GeoLite2-Country.mmdb
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

    public function __construct(string $storagePath = '')
    {
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
        // Skip local/private IPs — return neutral result
        if ($this->isLocalIp($ip)) {
            return [
                'country' => 'local',
                'in_eu' => false,
                'source' => 'local',
            ];
        }

        if ($this->reader !== null) {
            return $this->lookupMaxMind($ip);
        }

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

    private function isLocalIp(string $ip): bool
    {
        if ($ip === '' || $ip === '127.0.0.1' || $ip === '::1' || $ip === 'localhost') {
            return true;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
