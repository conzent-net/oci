<?php

declare(strict_types=1);

namespace OCI\Consent\Controller;

use Doctrine\DBAL\Connection;
use OCI\Consent\Service\ConsentService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/consent — Receives consent audit log from the banner script.
 *
 * Sent via navigator.sendBeacon() when the user takes a consent action
 * (accept all, reject all, save preferences). This is the GDPR audit trail.
 *
 * FormData fields:
 *   - conzent_id: session identifier (40-char random string)
 *   - key: website_key
 *   - log: JSON string of consent choices per category
 *   - consented_domain: the domain where consent was given
 *   - cookie_list_version: version of the cookie list
 *   - language: user's language
 *   - country: user's country
 *   - consent_time: client-side timestamp of consent action
 *   - variant_id: A/B test variant ID (optional)
 *   - tcf_data: IAB TCF v2.2 string (optional)
 *   - gacm_data: Google Consent Mode v2 data (optional)
 */
final class ConsentLogHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Connection $db,
        private readonly ConsentService $consentService,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $websiteKey = $body['key'] ?? '';
        $consentSession = $body['conzent_id'] ?? '';

        if ($websiteKey === '' || $consentSession === '') {
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

        $this->consentService->processConsent(
            (int) $siteId,
            $consentSession,
            $body['log'] ?? '[]',
            [
                'consented_domain' => $body['consented_domain'] ?? '',
                'language' => $body['language'] ?? '',
                'country' => $body['country'] ?? '',
                'consent_time' => $body['consent_time'] ?? null,
                'ip_address' => $this->getClientIp($request),
                'tcf_data' => $body['tcf_data'] ?? null,
                'gacm_data' => $body['gacm_data'] ?? null,
                'variant_id' => $body['variant_id'] ?? null,
            ],
        );

        return $this->corsResponse(ApiResponse::json(['status' => 'ok'], 200));
    }

    private function getClientIp(ServerRequestInterface $request): string
    {
        $serverParams = $request->getServerParams();

        // Cloudflare sends the real IP in CF-Connecting-IP
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $header) {
            $ip = $serverParams[$header] ?? '';
            if ($ip !== '') {
                return trim(explode(',', $ip)[0]);
            }
        }

        return '';
    }

    private function corsResponse(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', 'POST, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type');
    }
}
