<?php

declare(strict_types=1);

namespace OCI\Scanning\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Scanning\Service\ScanService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/scan-webhook — Receive scan results from scanner servers.
 *
 * Called by the scanner Docker container when a batch scan completes.
 * Also handles client-side beacon scan data from the consent script.
 *
 * Auth: X-Api-Key header or SCANNER_WEBHOOK_SECRET env var.
 */
final class ScanWebhookHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly ScanService $scanService,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Validate webhook authentication
        $webhookSecret = trim($_ENV['SCANNER_WEBHOOK_SECRET'] ?? '');
        if ($webhookSecret !== '') {
            $provided = $request->getHeaderLine('X-Api-Key');
            if ($provided !== $webhookSecret) {
                return ApiResponse::error('Unauthorized', 401);
            }
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
