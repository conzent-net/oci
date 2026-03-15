<?php

declare(strict_types=1);

namespace OCI\Dashboard\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Site\Repository\SiteRepositoryInterface;
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

        return ApiResponse::success($result + ['domain' => $domain]);
    }

    /**
     * @return array{installed: bool, reason: string}
     */
    private function checkScriptInstalled(string $url, string $websiteKey): array
    {
        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => self::HTTP_TIMEOUT,
                    'ignore_errors' => true,
                    'follow_location' => true,
                    'max_redirects' => 5,
                    'header' => "User-Agent: OCI-ScriptCheck/1.0\r\n",
                ],
                'ssl' => [
                    'verify_peer' => false, // Some sites have self-signed certs
                ],
            ]);

            $html = @file_get_contents($url, false, $context);

            if ($html === false) {
                // Try http:// fallback
                $httpUrl = str_replace('https://', 'http://', $url);
                $html = @file_get_contents($httpUrl, false, $context);

                if ($html === false) {
                    return [
                        'installed' => false,
                        'reason' => 'Could not reach the website. Make sure the domain is accessible.',
                    ];
                }
            }

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
                        'reason' => 'Consent script detected on site.',
                    ];
                }
            }

            return [
                'installed' => false,
                'reason' => 'Consent script not found on the website. Add the script tag to your site\'s <head> section.',
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('Script check failed: ' . $e->getMessage());

            return [
                'installed' => false,
                'reason' => 'Could not check the website: ' . $e->getMessage(),
            ];
        }
    }
}
