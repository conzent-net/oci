<?php

declare(strict_types=1);

namespace OCI\Scanning\Controller;

use Doctrine\DBAL\Connection;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Scanning\Service\BeaconBufferService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/scan_data — Receives client-side cookie scan beacons.
 *
 * Sent via navigator.sendBeacon() as multipart/form-data with:
 *   - key: website_key
 *   - payload: JSON string with scan_id, action, scan_url, consent_phase, data
 *
 * This handler does NO database writes in the request path. It validates
 * the website key, checks rate limits, and pushes the payload to a Redis
 * buffer for asynchronous batch processing by the flush worker.
 */
final class BeaconIngestHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Connection $db,
        private readonly BeaconBufferService $buffer,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $websiteKey = $body['key'] ?? '';
        $payloadRaw = $body['payload'] ?? '';

        if ($websiteKey === '' || $payloadRaw === '') {
            return $this->corsResponse(ApiResponse::json(['status' => 'ignored'], 200));
        }

        // Rate limit: 600 requests/minute per site
        if (!$this->buffer->rateCheck($websiteKey)) {
            return $this->corsResponse(ApiResponse::json(['status' => 'ok'], 200));
        }

        // Resolve site_id (cached in Redis, fallback to DB)
        $siteId = $this->resolveSiteId($websiteKey);
        if ($siteId === null) {
            return $this->corsResponse(ApiResponse::json(['status' => 'ignored'], 200));
        }

        // Decode and enrich payload
        $payload = json_decode($payloadRaw, true);
        if (!\is_array($payload)) {
            return $this->corsResponse(ApiResponse::json(['status' => 'ignored'], 200));
        }

        $payload['site_id'] = $siteId;
        $payload['website_key'] = $websiteKey;
        $payload['received_at'] = date('Y-m-d H:i:s');

        // Push to Redis buffer for async processing
        $this->buffer->push(BeaconBufferService::BUFFER_BEACON, $payload);

        return $this->corsResponse(ApiResponse::json(['status' => 'ok'], 200));
    }

    private function resolveSiteId(string $websiteKey): ?int
    {
        // Check Redis cache first
        $cached = $this->buffer->getCachedSiteId($websiteKey);
        if ($cached !== null) {
            return $cached;
        }

        // Fallback to DB
        $siteId = $this->db->fetchOne(
            'SELECT id FROM oci_sites WHERE website_key = :key AND status = :status',
            ['key' => $websiteKey, 'status' => 'active'],
        );

        if ($siteId === false) {
            return null;
        }

        $siteId = (int) $siteId;
        $this->buffer->cacheSiteId($websiteKey, $siteId);

        return $siteId;
    }

    private function corsResponse(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', 'POST, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type');
    }
}
