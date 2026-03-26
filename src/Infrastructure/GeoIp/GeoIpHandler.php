<?php

declare(strict_types=1);

namespace OCI\Infrastructure\GeoIp;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/geo_ip — Returns visitor's country and EU status.
 *
 * Response: {"country": "dk", "in_eu": true}
 *
 * Used by the consent script for geo-targeted banners (GDPR vs CCPA).
 * Includes CORS headers since the script runs on customer sites.
 */
final class GeoIpHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly GeoIpService $geoIpService,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $ip = $this->resolveClientIp($request);
        $result = $this->geoIpService->lookup($ip);

        $response = ApiResponse::json([
            'country' => $result['country'],
            'in_eu' => $result['in_eu'],
        ]);

        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }

    private function resolveClientIp(ServerRequestInterface $request): string
    {
        $serverParams = $request->getServerParams();

        // Check proxy headers (trusted in Docker/nginx setup)
        $forwardedFor = $request->getHeaderLine('X-Forwarded-For');
        if ($forwardedFor !== '') {
            // Take the first (client) IP from the chain
            $ips = array_map('trim', explode(',', $forwardedFor));
            return $ips[0];
        }

        $realIp = $request->getHeaderLine('X-Real-IP');
        if ($realIp !== '') {
            return $realIp;
        }

        return $serverParams['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}
