<?php

declare(strict_types=1);

namespace OCI\Scanning\Service;

use Predis\Client as RedisClient;

/**
 * Redis buffer abstraction for high-throughput beacon ingestion.
 *
 * All Redis key naming and serialization logic is centralised here
 * so that handlers and flush workers share a single source of truth.
 */
final class BeaconBufferService
{
    public const BUFFER_BEACON = 'oci:beacon:buffer';

    private const RATE_LIMIT_PREFIX = 'oci:ratelimit:';
    private const SITE_CACHE_PREFIX = 'oci:site:key:';
    private const COOKIE_SEEN_PREFIX = 'oci:cookie:seen:';

    private const RATE_LIMIT_TTL = 120;   // seconds (covers 1-minute window + slack)
    private const SITE_CACHE_TTL = 3600;  // 1 hour
    private const COOKIE_SEEN_TTL = 86400; // 1 day

    public function __construct(
        private readonly RedisClient $redis,
    ) {}

    /**
     * Push a payload to a named buffer list.
     */
    public function push(string $buffer, array $payload): void
    {
        $this->redis->lpush($buffer, [json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)]);
    }

    /**
     * Pop up to $count items from a buffer list (FIFO via RPOP).
     *
     * @return list<array<string, mixed>>
     */
    public function popBatch(string $buffer, int $count = 200): array
    {
        $items = [];

        for ($i = 0; $i < $count; $i++) {
            $raw = $this->redis->rpop($buffer);
            if ($raw === null) {
                break;
            }

            $decoded = json_decode($raw, true);
            if (\is_array($decoded)) {
                $items[] = $decoded;
            }
        }

        return $items;
    }

    /**
     * Per-site per-minute rate limit check.
     *
     * @return bool True if request is allowed, false if rate-limited.
     */
    public function rateCheck(string $websiteKey, int $maxPerMinute = 600): bool
    {
        $minute = (int) floor(time() / 60);
        $key = self::RATE_LIMIT_PREFIX . $websiteKey . ':' . $minute;

        $current = (int) $this->redis->incr($key);

        if ($current === 1) {
            $this->redis->expire($key, self::RATE_LIMIT_TTL);
        }

        return $current <= $maxPerMinute;
    }

    /**
     * Cache a website_key → site_id mapping.
     */
    public function cacheSiteId(string $websiteKey, int $siteId): void
    {
        $key = self::SITE_CACHE_PREFIX . $websiteKey;
        $this->redis->setex($key, self::SITE_CACHE_TTL, (string) $siteId);
    }

    /**
     * Look up a cached site_id by website_key.
     */
    public function getCachedSiteId(string $websiteKey): ?int
    {
        $key = self::SITE_CACHE_PREFIX . $websiteKey;
        $value = $this->redis->get($key);

        return $value !== null ? (int) $value : null;
    }

    /**
     * Mark a cookie as seen for a site today.
     *
     * @return bool True if the cookie was newly added (not seen before today).
     */
    public function markCookieSeen(int $siteId, string $cookieName): bool
    {
        $date = date('Y-m-d');
        $key = self::COOKIE_SEEN_PREFIX . $siteId . ':' . $date;

        $added = (int) $this->redis->sadd($key, [$cookieName]);

        if ($added === 1) {
            // Set TTL only on first add (SADD on existing set won't reset TTL)
            $ttl = (int) $this->redis->ttl($key);
            if ($ttl < 0) {
                $this->redis->expire($key, self::COOKIE_SEEN_TTL);
            }
        }

        return $added === 1;
    }

    /**
     * Get the current length of a buffer (for monitoring).
     */
    public function getBufferLength(string $buffer): int
    {
        return (int) $this->redis->llen($buffer);
    }
}
