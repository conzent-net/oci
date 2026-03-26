<?php

declare(strict_types=1);

namespace OCI\Report\Controller;

use OCI\Http\Response\ApiResponse;
use OCI\Report\Service\ReportService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use OCI\Http\Handler\RequestHandlerInterface;

final class ReportSendHandler implements RequestHandlerInterface
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

        $id = (int) $request->getAttribute('id');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $emailTo = trim((string) ($body['email_to'] ?? ''));

        if ($emailTo !== '' && filter_var($emailTo, FILTER_VALIDATE_EMAIL) === false) {
            return ApiResponse::error('Invalid email address', 422);
        }

        $report = $this->reportService->getReport($id);
        if ($report === null) {
            return ApiResponse::error('Report not found', 404);
        }

        $success = $this->reportService->send($id, $emailTo !== '' ? $emailTo : null);

        if (!$success) {
            return ApiResponse::error('Failed to send report email', 500);
        }

        return ApiResponse::success(['message' => 'Report sent successfully']);
    }
}
