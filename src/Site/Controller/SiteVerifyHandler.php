<?php

declare(strict_types=1);

namespace OCI\Site\Controller;

use Doctrine\DBAL\Connection;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/verify?website_id=KEY — Verify a site exists by website key.
 *
 * Returns site domain, name, and key for plugin integrations (Wix, WordPress, etc.).
 * Includes CORS headers since it is called from external servers.
 */
final class SiteVerifyHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $websiteKey = trim($params['website_id'] ?? '');

        if ($websiteKey === '') {
            return ApiResponse::json(['error' => 'Missing website_id parameter'], 400)
                ->withHeader('Access-Control-Allow-Origin', '*');
        }

        $site = $this->db->fetchAssociative(
            'SELECT id, domain, site_name, website_key FROM oci_sites WHERE website_key = :key AND status = :status AND deleted_at IS NULL',
            ['key' => $websiteKey, 'status' => 'active'],
        );

        if ($site === false) {
            return ApiResponse::json(['error' => 'Site not found'], 404)
                ->withHeader('Access-Control-Allow-Origin', '*');
        }

        return ApiResponse::json([
            'id' => (int) $site['id'],
            'domain' => $site['domain'],
            'site_name' => $site['site_name'],
            'website_key' => $site['website_key'],
        ])->withHeader('Access-Control-Allow-Origin', '*')
          ->withHeader('Cache-Control', 'public, max-age=300');
    }
}
