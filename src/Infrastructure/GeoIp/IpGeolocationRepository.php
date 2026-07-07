<?php

declare(strict_types=1);

namespace OCI\Infrastructure\GeoIp;

use Doctrine\DBAL\Connection;

/**
 * Caches IP-to-country lookups in oci_ip_geolocation using hashed IPs (SHA-256).
 */
final class IpGeolocationRepository
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    /**
     * @return array{country_code: string, in_eu: bool, source: string}|null
     */
    public function findByIpHash(string $ipHash): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT country_code, in_eu, source FROM oci_ip_geolocation WHERE ip_hash = ?',
            [$ipHash],
        );

        if ($row === false) {
            return null;
        }

        return [
            'country_code' => $row['country_code'] ?? 'unknown',
            'in_eu' => (bool) $row['in_eu'],
            'source' => $row['source'],
        ];
    }

    public function save(string $ipHash, string $countryCode, bool $inEu, string $source): void
    {
        $this->db->executeStatement(
            'INSERT IGNORE INTO oci_ip_geolocation (ip_hash, country_code, in_eu, source, looked_up_at)
             VALUES (?, ?, ?, ?, NOW())',
            [$ipHash, $countryCode, $inEu ? 1 : 0, $source],
        );
    }
}
