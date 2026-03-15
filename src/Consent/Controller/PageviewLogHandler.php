<?php

declare(strict_types=1);

namespace OCI\Consent\Controller;

use Doctrine\DBAL\Connection;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Scanning\Service\BeaconBufferService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/log — Receives pageview/banner-load beacons from the consent script.
 *
 * Sent via navigator.sendBeacon() as multipart/form-data with:
 *   - key: website_key
 *   - request_type: 'banner_load' | 'banner_view'
 *   - log_time: unix timestamp
 *   - payload: JSON string with consent_session_id, banner_id, url, cookies, thirdparty
 *
 * Increments the daily pageview counter in oci_site_pageviews.
 */
final class PageviewLogHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Connection $db,
        private readonly BeaconBufferService $beaconBuffer,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();

        // Fallback: if parsed body is empty, try $_POST directly
        if (empty($body)) {
            $body = $_POST;
        }

        $websiteKey = $body['key'] ?? '';
        $requestType = $body['request_type'] ?? '';

        if ($websiteKey === '' || $requestType === '') {
            return $this->corsResponse(ApiResponse::json(['status' => 'ignored'], 200));
        }

        // Look up site by key
        $siteId = $this->db->fetchOne(
            'SELECT id FROM oci_sites WHERE website_key = :key AND status = :status',
            ['key' => $websiteKey, 'status' => 'active'],
        );

        if ($siteId === false) {
            return $this->corsResponse(ApiResponse::json(['status' => 'ignored'], 200));
        }

        $siteId = (int) $siteId;

        // Only count actual page loads as pageviews (not modal opens or post-consent re-scans)
        if ($requestType === 'banner_load') {
            $today = date('Y-m-d');
            $this->db->executeStatement(
                'INSERT INTO oci_site_pageviews (site_id, period_date, pageview_count, unique_visitors)
                 VALUES (:siteId, :date, 1, 1)
                 ON DUPLICATE KEY UPDATE pageview_count = pageview_count + 1',
                ['siteId' => $siteId, 'date' => $today],
            );
        }

        // Buffer cookie observations for ALL request types (banner_load, banner_view, consent_cookies)
        $this->bufferCookieObservations($siteId, $websiteKey, $body);

        return $this->corsResponse(ApiResponse::json(['status' => 'ok'], 200));
    }

    /**
     * Extract cookies from the pageview payload and push to the beacon buffer.
     *
     * The consent script already sends cookie names in the payload JSON
     * as {cookies: [{name, valueLen}, ...]}. We enrich with consent_phase
     * and push for async batch processing.
     *
     * @param array<string, mixed> $body
     */
    private function bufferCookieObservations(int $siteId, string $websiteKey, array $body): void
    {
        $payloadRaw = $body['payload'] ?? '';
        if ($payloadRaw === '') {
            return;
        }

        $payload = json_decode((string) $payloadRaw, true);
        if (!\is_array($payload) || empty($payload['cookies'])) {
            return;
        }

        // Determine consent phase: if ConzentConsent cookie is in the list, user has consented
        $consentPhase = 'pre_consent';
        if (isset($payload['consent_phase'])) {
            $consentPhase = $payload['consent_phase'];
        } else {
            // Check if ConzentConsent is among the detected cookies
            foreach ($payload['cookies'] as $cookie) {
                $name = $cookie['name'] ?? '';
                if (strcasecmp($name, 'ConzentConsent') === 0) {
                    $consentPhase = 'post_consent';
                    break;
                }
            }
        }

        $requestType = $body['request_type'] ?? '';

        $this->beaconBuffer->push(BeaconBufferService::BUFFER_BEACON, [
            'site_id' => $siteId,
            'website_key' => $websiteKey,
            'cookies' => $payload['cookies'],
            'consent_phase' => $consentPhase,
            'url' => $payload['url'] ?? '',
            'request_type' => $requestType,
            'received_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function corsResponse(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', 'POST, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type');
    }
}
