<?php

declare(strict_types=1);

namespace OCI\Dashboard\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Infrastructure\Http\UrlGuard;
use OCI\Http\Response\ApiResponse;
use OCI\Site\Repository\SiteRepositoryInterface;
use OCI\Site\Service\SiteAccess;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /app/preview/script-check — Check if the consent script is installed on a site.
 *
 * Fetches the site's HTML server-side (to avoid CORS) and checks for
 * the presence of the Conzent/OCI consent script tag.
 */
final class ScriptCheckHandler implements RequestHandlerInterface
{
    private const HTTP_TIMEOUT = 8;

    public function __construct(
        private readonly SiteRepositoryInterface $siteRepo,
        private readonly LoggerInterface $logger,
        private readonly SiteAccess $siteAccess,
        private readonly UrlGuard $urlGuard,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::error('Unauthorized', 401);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $siteId = (int) ($body['site_id'] ?? 0);

        if ($siteId <= 0) {
            return ApiResponse::error('Invalid site ID', 422);
        }

        if (!$this->siteAccess->owns($user, $siteId)) {
            return ApiResponse::error('Site not found', 404);
        }

        $site = $this->siteRepo->findById($siteId);
        if ($site === null) {
            return ApiResponse::error('Site not found', 404);
        }

        $domain = (string) ($site['domain'] ?? '');
        $websiteKey = (string) ($site['website_key'] ?? '');

        if ($domain === '') {
            return ApiResponse::success([
                'installed' => false,
                'domain' => '',
                'reason' => 'No domain configured for this site.',
            ]);
        }

        // Fetch the site's HTML — strip protocol from domain if present
        $cleanDomain = (string) preg_replace('#^https?://#i', '', $domain);

        // In Docker, localhost domains aren't reachable from the app container.
        // Map localhost:PORT → the Docker service name (testsite:80).
        if (preg_match('#^localhost(?::(\d+))?$#', $cleanDomain)) {
            $url = 'http://testsite';
        } else {
            $url = 'https://' . $cleanDomain;
        }
        $result = $this->checkScriptInstalled($url, $websiteKey);

        return ApiResponse::success($result + [
            'domain' => $domain,
            'frameable' => $result['frameable'] ?? true,
        ]);
    }

    /**
     * @return array{installed: bool, reason: string, frameable: bool}
     */
    private function checkScriptInstalled(string $url, string $websiteKey): array
    {
        try {
            [$html, $responseHeaders] = $this->fetch($url);

            if ($html === false) {
                // Try http:// fallback
                $httpUrl = str_replace('https://', 'http://', $url);
                [$html, $responseHeaders] = $this->fetch($httpUrl);

                if ($html === false) {
                    return [
                        'installed' => false,
                        'frameable' => false,
                        'reason' => 'Could not reach the website. Make sure the domain is accessible.',
                    ];
                }
            }

            // Check if the site allows iframing
            $frameable = $this->isFrameable($responseHeaders, $html);

            // Check for the consent script — look for common patterns:
            // 1. The website key in a script src
            // 2. conzent.net or conzent CDN references
            // 3. The OCI consent script pattern
            $patterns = [
                $websiteKey,           // Website key in script tag or data-key attribute
                'consent.js',          // CMP loader script (new path)
                'conzent-cmp.js',      // CMP loader script (legacy path)
                'conzent.net',         // Conzent CDN
                'conzent_banner',      // Legacy script variable
                'oci-consent',         // OCI consent script
                'cookie-consent.js',   // Common script name
            ];

            foreach ($patterns as $pattern) {
                if ($pattern !== '' && stripos($html, $pattern) !== false) {
                    return [
                        'installed' => true,
                        'frameable' => $frameable,
                        'reason' => 'Consent script detected on site.',
                    ];
                }
            }

            return [
                'installed' => false,
                'frameable' => $frameable,
                'reason' => 'Consent script not found on the website. Add the script tag to your site\'s <head> section.',
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('Script check failed: ' . $e->getMessage());

            return [
                'installed' => false,
                'frameable' => false,
                'reason' => 'Could not check the website: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * GET a page on the customer's domain, following up to five redirects by
     * hand so every hop goes through the UrlGuard. The domain is whatever the
     * site owner typed, so without this the server would fetch internal
     * addresses for them, directly or through a redirect.
     *
     * @return array{0: string|false, 1: array<int, string>}
     */
    private function fetch(string $url): array
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => self::HTTP_TIMEOUT,
                'ignore_errors' => true,
                'follow_location' => false,
                'header' => "User-Agent: OCI-ScriptCheck/1.0\r\n",
            ],
            'ssl' => [
                'verify_peer' => false, // Some sites have self-signed certs
            ],
        ]);

        for ($hop = 0; $hop <= 5; $hop++) {
            if (!$this->urlGuard->check($url)['ok']) {
                return [false, []];
            }

            $html = @file_get_contents($url, false, $context);
            /** @var array<int, string> $headers */
            $headers = $http_response_header ?? [];

            $location = null;
            if (preg_match('#^HTTP/\S+\s+3\d\d#', $headers[0] ?? '')) {
                foreach ($headers as $header) {
                    if (stripos($header, 'Location:') === 0) {
                        $location = trim(substr($header, 9));
                    }
                }
            }
            if ($location === null || $location === '') {
                return [$html, $headers];
            }

            $url = self::resolveLocation($url, $location);
        }

        return [false, []];
    }

    private static function resolveLocation(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $p = parse_url($base);
        $scheme = $p['scheme'] ?? 'https';
        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }

        $origin = $scheme . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');

        return $origin . (str_starts_with($location, '/') ? $location : '/' . $location);
    }

    /**
     * Check HTTP headers and HTML meta tags for frame-blocking directives.
     *
     * @param array<int, string> $headers Raw $http_response_header lines
     */
    private function isFrameable(array $headers, string $html): bool
    {
        // Check X-Frame-Options header
        foreach ($headers as $header) {
            if (stripos($header, 'X-Frame-Options') !== false) {
                $value = strtolower(trim(explode(':', $header, 2)[1] ?? ''));
                if ($value === 'deny' || $value === 'sameorigin') {
                    return false;
                }
            }
            // Check Content-Security-Policy frame-ancestors
            if (stripos($header, 'Content-Security-Policy') !== false) {
                $value = strtolower($header);
                if (preg_match('/frame-ancestors\s+[\'"]?none[\'"]?/', $value)) {
                    return false;
                }
                if (str_contains($value, 'frame-ancestors') && str_contains($value, "'self'")) {
                    return false;
                }
            }
        }

        // Check HTML meta http-equiv for X-Frame-Options
        if (preg_match('/<meta[^>]+http-equiv=["\']X-Frame-Options["\'][^>]+content=["\'](\w+)["\']/i', $html, $m)) {
            $val = strtolower($m[1]);
            if ($val === 'deny' || $val === 'sameorigin') {
                return false;
            }
        }

        return true;
    }
}
