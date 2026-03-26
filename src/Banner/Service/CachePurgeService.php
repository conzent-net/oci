<?php

declare(strict_types=1);

namespace OCI\Banner\Service;

use Psr\Log\LoggerInterface;

/**
 * Purges caches after script regeneration.
 *
 * Handles:
 *  - Cloudflare Edge cache (per-URL purge via API)
 *  - OPCache reset (invalidate compiled PHP files)
 *  - Browser cache (handled via version query param — see ScriptGenerationService)
 *  - Redis cache (site-level config cache)
 */
final class CachePurgeService
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Purge all caches for a site's generated script.
     */
    public function purgeForSite(string $websiteKey, string $domain = ''): void
    {
        $scriptPath = 'sites_data/' . $websiteKey . '/script.js';

        $this->purgeCloudflare($scriptPath, $domain);
        $this->purgeOpcache();
        $this->purgeRedis($websiteKey);
    }

    /**
     * Purge the shared CMP loader from Cloudflare edge cache.
     */
    public function purgeLoader(): void
    {
        $appUrl = rtrim($_ENV['APP_URL'] ?? '', '/');
        $this->purgeCloudflareUrls([$appUrl . '/c/consent.js']);
    }

    /**
     * Purge Cloudflare edge cache for a site's script URLs.
     */
    private function purgeCloudflare(string $scriptPath, string $domain): void
    {
        $appUrl = rtrim($_ENV['APP_URL'] ?? '', '/');
        $urls = [$appUrl . '/' . $scriptPath];

        if ($domain !== '') {
            $cleanDomain = (string) preg_replace('#^https?://#i', '', $domain);
            $urls[] = 'https://' . $cleanDomain . '/' . $scriptPath;
            $urls[] = 'http://' . $cleanDomain . '/' . $scriptPath;
        }

        $this->purgeCloudflareUrls($urls);
    }

    /**
     * Purge specific URLs from Cloudflare edge cache.
     *
     * @param list<string> $urls
     */
    private function purgeCloudflareUrls(array $urls): void
    {
        $zoneId = $_ENV['CLOUDFLARE_ZONE_ID'] ?? '';
        $apiToken = $_ENV['CLOUDFLARE_API_TOKEN'] ?? '';

        if ($zoneId === '' || $apiToken === '') {
            return;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://api.cloudflare.com/client/v4/zones/' . $zoneId . '/purge_cache',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['files' => $urls]),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiToken,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            $this->logger->info('Cloudflare cache purged', ['urls' => $urls]);
        } else {
            $this->logger->warning('Cloudflare purge failed', [
                'http_code' => $httpCode,
                'response' => $response,
                'urls' => $urls,
            ]);
        }
    }

    /**
     * Reset OPCache to ensure PHP picks up any changed files.
     */
    private function purgeOpcache(): void
    {
        if (\function_exists('opcache_reset')) {
            opcache_reset();
        }
    }

    /**
     * Clear Redis cache entries for a specific site.
     */
    public function purgeRedis(string $websiteKey): void
    {
        try {
            $redisUrl = $_ENV['REDIS_URL'] ?? '';
            if ($redisUrl === '') {
                return;
            }

            $redis = new \Predis\Client($redisUrl);
            // Delete site-specific cache keys
            $keys = $redis->keys('oci:site:' . $websiteKey . ':*');
            if ($keys !== []) {
                $redis->del($keys);
                $this->logger->info('Redis cache cleared', ['key' => $websiteKey, 'count' => \count($keys)]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Redis purge failed', ['error' => $e->getMessage()]);
        }
    }
}
