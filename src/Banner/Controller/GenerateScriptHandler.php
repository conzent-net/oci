<?php

declare(strict_types=1);

namespace OCI\Banner\Controller;

use OCI\Banner\Service\ScriptGenerationService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Site\Service\SiteAccess;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/sites/{id}/generate-script — Regenerate the consent script for a site.
 */
final class GenerateScriptHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly ScriptGenerationService $scriptService,
        private readonly SiteAccess $siteAccess,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $siteId = (int) ($request->getAttribute('id') ?? 0);
        if ($siteId === 0) {
            return ApiResponse::error('Invalid site ID', 400);
        }

        if (!$this->siteAccess->owns($user, $siteId)) {
            return ApiResponse::error('Site not found', 404);
        }

        $success = $this->scriptService->generate($siteId);

        if ($success) {
            return ApiResponse::success(['message' => 'Script generated successfully']);
        }

        return ApiResponse::error('Script generation failed', 500);
    }
}
