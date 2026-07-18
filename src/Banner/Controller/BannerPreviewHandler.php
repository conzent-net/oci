<?php

declare(strict_types=1);

namespace OCI\Banner\Controller;

use Nyholm\Psr7\Response;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Site\Repository\SiteRepositoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /app/banners/preview?site_id=X — Standalone banner preview page.
 *
 * Renders a minimal HTML page that loads the site's consent script,
 * used as a fallback when the actual site blocks iframing (X-Frame-Options).
 */
final class BannerPreviewHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepositoryInterface $siteRepo,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::redirect('/login');
        }

        $params = $request->getQueryParams();
        $siteId = (int) ($params['site_id'] ?? 0);

        if ($siteId <= 0) {
            return ApiResponse::error('Invalid site ID', 422);
        }

        $site = $this->siteRepo->findById($siteId);
        if ($site === null) {
            return ApiResponse::error('Site not found', 404);
        }

        $websiteKey = htmlspecialchars((string) ($site['website_key'] ?? ''), ENT_QUOTES, 'UTF-8');
        $domain = htmlspecialchars((string) ($site['domain'] ?? ''), ENT_QUOTES, 'UTF-8');
        $appUrl = rtrim($_ENV['APP_URL'] ?? 'http://localhost:8098', '/');
        $scriptUrl = $appUrl . '/c/consent.js';

        // Allow framing from our own app
        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Banner Preview — {$domain}</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    background: #f5f5f5;
                    min-height: 100vh;
                }
                .preview-page {
                    max-width: 960px;
                    margin: 0 auto;
                    padding: 3rem 2rem;
                }
                .preview-page h1 {
                    font-size: 1.5rem;
                    color: #333;
                    margin-bottom: 0.5rem;
                }
                .preview-page p {
                    color: #666;
                    line-height: 1.6;
                    margin-bottom: 1rem;
                }
                .preview-notice {
                    background: #fff3cd;
                    border: 1px solid #ffc107;
                    border-radius: 8px;
                    padding: 1rem 1.25rem;
                    margin-bottom: 2rem;
                    font-size: 0.875rem;
                    color: #664d03;
                }
                .preview-notice strong { color: #664d03; }
                .mock-content {
                    background: #fff;
                    border-radius: 8px;
                    padding: 2rem;
                    margin-bottom: 1.5rem;
                    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
                }
                .mock-content h2 { font-size: 1.1rem; color: #444; margin-bottom: 0.75rem; }
                .mock-content .line {
                    height: 12px;
                    background: #e8e8e8;
                    border-radius: 4px;
                    margin-bottom: 0.5rem;
                }
                .mock-content .line:nth-child(2) { width: 92%; }
                .mock-content .line:nth-child(3) { width: 78%; }
                .mock-content .line:nth-child(4) { width: 85%; }
                .mock-content .line:nth-child(5) { width: 60%; }
            </style>
            <script async src="{$scriptUrl}" data-key="{$websiteKey}"></script>
        </head>
        <body>
            <div class="preview-page">
                <div class="preview-notice">
                    <strong>Banner Preview Mode</strong> — This page loads your consent banner for preview purposes.
                    Your website ({$domain}) could not be displayed in a frame due to its security settings.
                </div>
                <div class="mock-content">
                    <h2>Sample Page Content</h2>
                    <div class="line"></div>
                    <div class="line"></div>
                    <div class="line"></div>
                    <div class="line"></div>
                    <div class="line"></div>
                </div>
                <div class="mock-content">
                    <h2>Additional Content</h2>
                    <div class="line"></div>
                    <div class="line"></div>
                    <div class="line"></div>
                </div>
            </div>
        </body>
        </html>
        HTML;

        // Return with permissive framing headers so our app can iframe it
        return new Response(
            200,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'X-Frame-Options' => 'SAMEORIGIN',
            ],
            $html,
        );
    }
}
