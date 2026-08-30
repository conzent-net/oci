<?php

declare(strict_types=1);

namespace OCI\Dashboard\Controller;

use OCI\Dashboard\Service\ComplianceCheckService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/dashboard/compliance-check — AJAX compliance check.
 *
 * Accepts: site_id, template (template name to apply)
 * Returns: JSON {status, total_passed, total_failed, total_checked, errors}
 */
final class ComplianceCheckHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly ComplianceCheckService $complianceService,
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
        $template = (string) ($body['template'] ?? '');
        $verifyOnly = (bool) ($body['verify_only'] ?? false);

        if ($siteId <= 0) {
            return ApiResponse::error('Invalid site ID', 422);
        }

        if ($template === '') {
            return ApiResponse::error('Template is required', 422);
        }

        try {
            if ($verifyOnly) {
                $result = $this->complianceService->check($siteId, $template, isTemplateUpdate: false, verifyOnly: true);
            } else {
                $result = $this->complianceService->checkAndSave($siteId, $template);
            }
        } catch (\Throwable $e) {
            return ApiResponse::error(
                'Compliance check failed: ' . $e->getMessage(),
                500,
            );
        }

        return ApiResponse::success($result);
    }
}
