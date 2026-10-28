<?php

declare(strict_types=1);

namespace OCI\Scanning\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Scanning\Service\ScanService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /api/v1/scan-webhook — Receive scan results from scanner servers.
 *
 * Called by the scanner Docker container when a batch scan completes.
 * Also handles client-side beacon scan data from the consent script.
 *
 * Auth: X-Api-Key header must equal ScanService::webhookSecret()
 * (SCANNER_WEBHOOK_SECRET, falling back to SCANNER_API_KEY). With neither
 * set every request is refused.
 */
final class ScanWebhookHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly ScanService $scanService,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Fails closed. Results posted here can end up as a site's public
        // cookie declaration, so an unconfigured secret refuses everything
        // rather than accepting anyone. Constant-time comparison, so the check
        // does not leak the secret one byte at a time. Scanners send back the
        // callback_api_key the dispatch gave them, which is this same value.
        $webhookSecret = ScanService::webhookSecret();
        if ($webhookSecret === '') {
            $this->logger?->warning('Scan webhook refused: neither SCANNER_WEBHOOK_SECRET nor SCANNER_API_KEY is set');

            return ApiResponse::error('Unauthorized', 401);
        }
        if (!hash_equals($webhookSecret, $request->getHeaderLine('X-Api-Key'))) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $body = (array) $request->getParsedBody();
        $action = (string) ($body['action'] ?? 'webhook');

        if ($action === 'runscan' || $action === 'client_scan') {
            // Client-side beacon scan data
            $scanId = (int) ($body['scan_id'] ?? 0);
            $scanUrl = (string) ($body['scan_url'] ?? '');
            $data = (array) ($body['data'] ?? []);

            if ($scanId > 0 && $scanUrl !== '') {
                $this->scanService->processClientScanData($scanId, $scanUrl, $data);
            }

            return ApiResponse::success(['status' => 'received']);
        }

        // Scanner server webhook (batch results)
        $this->scanService->processWebhookResults($body);

        return ApiResponse::success(['status' => 'processed']);
    }
}
