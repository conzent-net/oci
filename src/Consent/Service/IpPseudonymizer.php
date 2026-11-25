<?php

declare(strict_types=1);

namespace OCI\Consent\Service;

use Psr\Log\LoggerInterface;

/**
 * Pseudonymizes visitor IP addresses before they enter the consent log.
 *
 * Proof of consent needs a stable correlator, not an address: a keyed
 * HMAC-SHA256 (CONSENT_IP_KEY) keeps records linkable without storing
 * personal data. A plain unkeyed hash would not qualify — the IPv4 space
 * is small enough to enumerate — so without a key we fall back to
 * truncation (v4 /24, v6 /48) instead.
 */
final class IpPseudonymizer
{
    private static bool $warnedMissingKey = false;

    public function __construct(
        private readonly string $key = '',
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function pseudonymize(string $ip): string
    {
        $ip = trim($ip);
        if ($ip === '' || $this->isPseudonymized($ip)) {
            return $ip;
        }

        if ($this->key !== '') {
            return hash_hmac('sha256', $ip, $this->key);
        }

        if (!self::$warnedMissingKey) {
            self::$warnedMissingKey = true;
            $this->logger?->warning(
                'CONSENT_IP_KEY is not set — consent-log IPs are truncated instead of HMAC-hashed, which weakens session correlation',
            );
        }

        return $this->truncate($ip);
    }

    public function isPseudonymized(string $value): bool
    {
        return preg_match('/^[0-9a-f]{64}$/', $value) === 1;
    }

    private function truncate(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $parts = explode('.', $ip);
            $parts[3] = '0';

            return implode('.', $parts);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($ip);
            if ($packed === false) {
                return '';
            }
            // Keep the /48 prefix, zero the rest
            $truncated = substr($packed, 0, 6) . str_repeat("\0", 10);

            return (string) inet_ntop($truncated);
        }

        // Not an IP at all (spoofed header content) — store nothing
        return '';
    }
}
