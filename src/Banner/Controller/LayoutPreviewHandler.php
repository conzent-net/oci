<?php

declare(strict_types=1);

namespace OCI\Banner\Controller;

use OCI\Banner\Service\LayoutService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/layouts/preview — Render layout HTML preview (AJAX).
 *
 * Accepts raw Twig/HTML source and returns rendered HTML with sample data.
 * Used by the layout editor for live preview updates.
 */
final class LayoutPreviewHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly LayoutService $layoutService,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $body = json_decode((string) $request->getBody(), true) ?? [];
        $html = $body['html'] ?? '';
        $layoutKey = $body['layout_key'] ?? '';

        // If layout_key is provided, load the system layout HTML
        if ($html === '' && $layoutKey !== '') {
            $html = $this->layoutService->getSystemLayoutRendered($layoutKey);
        }

        if ($html === '') {
            return ApiResponse::error('HTML content or layout_key is required', 422);
        }

        $preview = $this->layoutService->renderPreview($html);
        $missing = $this->layoutService->validateLayout($html);
        $recommendations = $this->layoutService->getRecommendations($html);

        return ApiResponse::success([
            'preview' => $preview,
            'missing' => $missing,
            'recommendations' => $recommendations,
        ]);
    }
}
