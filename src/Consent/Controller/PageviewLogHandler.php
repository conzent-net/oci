<?php

declare(strict_types=1);

namespace OCI\Consent\Controller;

use Doctrine\DBAL\Connection;
use OCI\Banner\Service\ScriptGenerationService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Monetization\Service\PricingService;
use OCI\Monetization\Service\SubscriptionService;
use OCI\Scanning\Service\BeaconBufferService;
use OCI\Site\Repository\PageviewRepositoryInterface;
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
        private readonly ?PageviewRepositoryInterface $pageviewRepo = null,
        private readonly ?PricingService $pricingService = null,
        private readonly ?SubscriptionService $subscriptionService = null,
        private readonly ?ScriptGenerationService $scriptService = null,
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

            // Check if monthly pageview limit just crossed
            $this->checkPageviewLimit($siteId, $websiteKey);
        }

        // Buffer cookie observations for ALL request types (banner_load, banner_view, consent_cookies)
        $this->bufferCookieObservations($siteId, $websiteKey, $body);

        return $this->corsResponse(ApiResponse::json(['status' => 'ok'], 200));
    }

    /**
     * Check if the user's monthly pageview limit has been exceeded.
     * If so, mark the site's version.json so the loader shows a warning instead of the banner.
     */
    private function checkPageviewLimit(int $siteId, string $websiteKey): void
    {
        if ($this->pageviewRepo === null || $this->pricingService === null
            || $this->subscriptionService === null || $this->scriptService === null) {
            return;
        }

        $userId = $this->db->fetchOne(
            'SELECT user_id FROM oci_sites WHERE id = :id',
            ['id' => $siteId],
        );

        if ($userId === false) {
            return;
        }

        $userId = (int) $userId;
        $planKey = $this->subscriptionService->getPlanKey($userId);
        if ($planKey === null) {
            return;
        }

        $limit = $this->pricingService->getLimit($planKey, 'pageviews_per_month');
        if ($limit <= 0) {
            return; // Unlimited
        }

        $used = $this->pageviewRepo->getMonthlyTotalForUser($userId);
        if ($used >= $limit) {
            $this->scriptService->markSiteExceeded($websiteKey);
        }
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
