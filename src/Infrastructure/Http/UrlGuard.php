<?php

declare(strict_types=1);

namespace OCI\Infrastructure\Http;

use const DNS_A;
use const DNS_AAAA;
use const FILTER_FLAG_NO_PRIV_RANGE;
use const FILTER_FLAG_NO_RES_RANGE;
use const FILTER_VALIDATE_BOOLEAN;
use const FILTER_VALIDATE_IP;

use function in_array;
use function is_array;
use function is_callable;

/**
 * SSRF guard for outbound fetches to hosts a customer controls: the
 * diagnostics homepage fetch and the plugin claim challenge. Fails CLOSED.
 *
 * Deliberately not modeled on MatomoApiService::isUrlAllowed(), which
 * allows hosts that fail DNS resolution. Here an unresolvable host is a
 * rejection, every resolved address must be public, and the caller is
 * handed the validated IP so it can pin the connection against DNS
 * rebinding (CURLOPT_RESOLVE).
 *
 * Two relaxations, both closed in production (APP_ENV `prod` or
 * `production`, the two spellings the compose files use):
 *  - the Docker test site `testsite`, mirrored from ScriptCheckHandler;
 *  - CLAIM_ALLOW_PRIVATE_HOSTS=true, which lets a container on the compose
 *    network be fetched by its alias. DNS still has to resolve and the
 *    connection is still pinned; only the private-address rejection is lifted.
 */
final class UrlGuard
{
    /**
     * @param (callable(string): array{a: list<string>, aaaa: list<string>})|null $resolver
     *        Injectable DNS resolver for tests. Defaults to dns_get_record.
     */
    public function __construct(
        private readonly mixed $resolver = null,
    ) {}

    /**
     * Validate a URL for outbound fetching.
     *
     * @return array{ok: bool, reason: string, host: string, port: int, pin_ip: string}
     */
    public function check(string $url): array
    {
        $reject = static fn(string $reason): array => [
            'ok' => false,
            'reason' => $reason,
            'host' => '',
            'port' => 0,
            'pin_ip' => '',
        ];

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $reject('URL could not be parsed.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return $reject('Only http and https URLs are allowed.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return $reject('URLs with credentials are not allowed.');
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (!in_array($port, [80, 443], true)) {
            return $reject('Only ports 80 and 443 are allowed.');
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if ($host === '' || str_contains($host, '%') || preg_match('/\s/', $host) === 1) {
            return $reject('Invalid host.');
        }

        // Dev-only exception: the Docker test site, mirrored from ScriptCheckHandler.
        if ($host === 'testsite' && !$this->isProduction()) {
            return ['ok' => true, 'reason' => '', 'host' => $host, 'port' => $port, 'pin_ip' => ''];
        }

        $allowPrivate = $this->allowPrivateHosts();

        // String-level rejects before DNS (defense in depth).
        if (
            !$allowPrivate
            && (
                $host === 'localhost'
                || str_ends_with($host, '.localhost')
                || str_ends_with($host, '.local')
                || str_ends_with($host, '.internal')
                || str_ends_with($host, '.home.arpa')
            )
        ) {
            return $reject('Internal hostnames are not allowed.');
        }

        // IP literal? Validate directly, pin to it.
        $bareV6 = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) !== false || filter_var($bareV6, FILTER_VALIDATE_IP) !== false) {
            $ip = filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : $bareV6;
            $reason = $allowPrivate ? null : $this->rejectIp($ip);
            if ($reason !== null) {
                return $reject($reason);
            }

            return ['ok' => true, 'reason' => '', 'host' => $host, 'port' => $port, 'pin_ip' => $ip];
        }

        $ips = $this->resolve($host);
        if ($ips === []) {
            // Fail CLOSED: an unresolvable host is never fetched.
            return $reject('The domain could not be resolved safely.');
        }

        if (!$allowPrivate) {
            foreach ($ips as $ip) {
                $reason = $this->rejectIp($ip);
                if ($reason !== null) {
                    // One bad address rejects the host: the attacker controls record order.
                    return $reject($reason);
                }
            }
        }

        return ['ok' => true, 'reason' => '', 'host' => $host, 'port' => $port, 'pin_ip' => $ips[0]];
    }

    private function isProduction(): bool
    {
        return in_array((string) ($_ENV['APP_ENV'] ?? ''), ['prod', 'production'], true);
    }

    private function allowPrivateHosts(): bool
    {
        return !$this->isProduction()
            && filter_var($_ENV['CLAIM_ALLOW_PRIVATE_HOSTS'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        if (is_callable($this->resolver)) {
            $result = ($this->resolver)($host);
            return array_values(array_merge($result['a'] ?? [], $result['aaaa'] ?? []));
        }

        $ips = [];

        $a = @dns_get_record($host, DNS_A);
        if (is_array($a)) {
            foreach ($a as $record) {
                if (isset($record['ip'])) {
                    $ips[] = (string) $record['ip'];
                }
            }
        }

        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $record) {
                if (isset($record['ipv6'])) {
                    $ips[] = (string) $record['ipv6'];
                }
            }
        }

        return $ips;
    }

    /**
     * @return string|null Rejection reason, or null when the IP is publicly routable.
     */
    private function rejectIp(string $ip): ?string
    {
        // IPv4-mapped / NAT64 IPv6: judge the embedded IPv4 address.
        if (str_contains($ip, ':')) {
            $packed = @inet_pton($ip);
            if ($packed === false) {
                return 'Address could not be parsed.';
            }

            $mapped = null;
            if (str_starts_with(bin2hex($packed), '00000000000000000000ffff')) {
                $mapped = implode('.', array_map('ord', str_split(substr($packed, 12), 1)));
            } elseif (str_starts_with(bin2hex($packed), '0064ff9b')) {
                $mapped = implode('.', array_map('ord', str_split(substr($packed, 12), 1)));
            }

            if ($mapped !== null) {
                return $this->rejectIp($mapped);
            }
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return 'The domain points to a private or reserved address.';
        }

        // Ranges the filter flags do not cover.
        if (!str_contains($ip, ':')) {
            $long = ip2long($ip);
            if ($long === false) {
                return 'Address could not be parsed.';
            }

            $extraRanges = [
                ['100.64.0.0', 10],   // CGNAT
                ['192.0.0.0', 24],    // IETF protocol assignments
                ['198.18.0.0', 15],   // benchmarking
                ['224.0.0.0', 4],     // multicast
            ];
            foreach ($extraRanges as [$base, $bits]) {
                $mask = -1 << (32 - $bits);
                if (($long & $mask) === (ip2long($base) & $mask)) {
                    return 'The domain points to a private or reserved address.';
                }
            }

            if ($ip === '255.255.255.255') {
                return 'The domain points to a private or reserved address.';
            }
        } else {
            $packed = @inet_pton($ip);
            if ($packed === false) {
                return 'Address could not be parsed.';
            }
            $hex = bin2hex($packed);

            // Link-local fe80::/10, ULA fc00::/7, documentation 2001:db8::/32, multicast ff00::/8.
            $firstBits = hexdec(substr($hex, 0, 4));
            if (($firstBits & 0xFFC0) === 0xFE80 || ($firstBits & 0xFE00) === 0xFC00 || ($firstBits & 0xFF00) === 0xFF00) {
                return 'The domain points to a private or reserved address.';
            }
            if (str_starts_with($hex, '20010db8')) {
                return 'The domain points to a private or reserved address.';
            }
        }

        return null;
    }
}
