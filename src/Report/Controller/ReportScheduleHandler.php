<?php

declare(strict_types=1);

namespace OCI\Report\Controller;

use OCI\Http\Response\ApiResponse;
use OCI\Report\Service\ReportService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use OCI\Http\Handler\RequestHandlerInterface;

final class ReportScheduleHandler implements RequestHandlerInterface
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
        $frequency = (string) ($body['frequency'] ?? 'monthly');
        $isActive = (bool) ($body['is_active'] ?? false);
        $emailTo = trim((string) ($body['email_to'] ?? ''));

        if ($siteId <= 0) {
            return ApiResponse::error('Invalid site ID', 422);
        }

        if (!\in_array($reportType, ['consent', 'scan', 'full'], true)) {
            return ApiResponse::error('Invalid report type', 422);
        }

        if ($emailTo !== '' && filter_var($emailTo, FILTER_VALIDATE_EMAIL) === false) {
            return ApiResponse::error('Invalid email address', 422, ['email_to' => 'Please enter a valid email address']);
        }

        $this->reportService->updateSchedule(
            $siteId,
            (int) $user['id'],
            $reportType,
            $frequency,
            $isActive,
            $emailTo !== '' ? $emailTo : null,
        );

        return ApiResponse::success(['message' => 'Schedule updated successfully']);
    }
}
