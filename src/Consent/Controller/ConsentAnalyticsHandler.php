<?php

declare(strict_types=1);

namespace OCI\Consent\Controller;

use OCI\Consent\Repository\ConsentRepositoryInterface;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/consents/analytics — AJAX endpoint for consent analytics data.
 *
 * Returns trend data + period comparison for chart updates when
 * the user changes the period selector.
 */
final class ConsentAnalyticsHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly ConsentRepositoryInterface $consentRepo,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $siteId = (int) ($body['site_id'] ?? 0);
        $days = (int) ($body['days'] ?? 7);
        $variantId = !empty($body['variant_id']) ? (int) $body['variant_id'] : null;

        if ($siteId <= 0) {
            return ApiResponse::error('Invalid site ID', 422);
        }

        if (!\in_array($days, [7, 30, 90], true)) {
            $days = 7;
        }

        $comparison = $this->consentRepo->getPeriodComparison($siteId, $days);
        $trend = $this->consentRepo->getDailyTrend($siteId, $days, $variantId);

        return ApiResponse::success([
            'comparison' => $comparison,
            'trend' => $trend,
        ]);
    }
}
