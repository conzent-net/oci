<?php

declare(strict_types=1);

namespace OCI\Banner\Controller;

use OCI\Banner\Service\ScriptGenerationService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/sites/{id}/generate-script — Regenerate the consent script for a site.
 */
final class GenerateScriptHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly ScriptGenerationService $scriptService,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = $request->getAttribute('session');
        $userId = (int) ($session['user_id'] ?? 0);

        if ($userId === 0) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $siteId = (int) ($request->getAttribute('id') ?? 0);
        if ($siteId === 0) {
            return ApiResponse::error('Invalid site ID', 400);
        }

        $success = $this->scriptService->generate($siteId);

        if ($success) {
            return ApiResponse::success(['message' => 'Script generated successfully']);
        }

        return ApiResponse::error('Script generation failed', 500);
    }
}
