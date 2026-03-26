<?php

declare(strict_types=1);

namespace OCI\Report\Controller;

use OCI\Http\Response\ApiResponse;
use OCI\Report\Service\ReportService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use OCI\Http\Handler\RequestHandlerInterface;

final class ReportGenerateHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly ReportService $reportService,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::error('Unauthorized', 401);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $siteId = (int) ($body['site_id'] ?? 0);
        $reportType = (string) ($body['report_type'] ?? 'full');
        $periodStart = (string) ($body['period_start'] ?? '');
        $periodEnd = (string) ($body['period_end'] ?? '');

        if ($siteId <= 0) {
            return ApiResponse::error('Invalid site ID', 422);
        }

        // Validate report type
        if (!\in_array($reportType, ['consent', 'scan', 'full'], true)) {
            return ApiResponse::error('Invalid report type', 422);
        }

        // Default to last 30 days if no dates provided
        if ($periodStart === '' || $periodEnd === '') {
            $periodEnd = (new \DateTimeImmutable())->format('Y-m-d');
            $periodStart = (new \DateTimeImmutable('-30 days'))->format('Y-m-d');
        }

        // Validate date format
        $start = \DateTimeImmutable::createFromFormat('Y-m-d', $periodStart);
        $end = \DateTimeImmutable::createFromFormat('Y-m-d', $periodEnd);

        if ($start === false || $end === false) {
            return ApiResponse::error('Invalid date format (expected YYYY-MM-DD)', 422);
        }

        if ($start > $end) {
            return ApiResponse::error('Start date must be before end date', 422);
        }

        $userId = (int) $user['id'];
        $reportId = $this->reportService->generate($siteId, $userId, $reportType, $periodStart, $periodEnd);

        return ApiResponse::success([
            'report_id' => $reportId,
            'message' => 'Report generated successfully',
        ]);
    }
}
