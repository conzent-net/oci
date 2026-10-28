<?php

declare(strict_types=1);

namespace OCI\Banner\Controller;

use OCI\Banner\Service\LayoutService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Site\Repository\SiteRepositoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment as TwigEnvironment;

/**
 * GET /layouts/{id}/edit — Layout editor with CodeMirror and live preview.
 */
final class LayoutEditorHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepositoryInterface $siteRepo,
        private readonly LayoutService $layoutService,
        private readonly TwigEnvironment $twig,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::redirect('/login');
        }

        $layoutId = (int) ($request->getAttribute('id') ?? 0);
        $layout = $this->layoutService->getCustomLayout($layoutId);

        if ($layout === null) {
            return ApiResponse::redirect('/layouts');
        }

        // Verify user owns this site
        $userId = (int) $user['id'];
        $siteId = (int) $layout['site_id'];
        $sites = $this->siteRepo->findAllByUser($userId);
        $siteIds = array_map(static fn(array $s): int => (int) $s['id'], $sites);

        if (!\in_array($siteId, $siteIds, true)) {
            return ApiResponse::redirect('/layouts');
        }

        // Validate current HTML
        $missing = $this->layoutService->validateLayout($layout['html_content']);
        $recommendations = $this->layoutService->getRecommendations($layout['html_content']);

        $html = $this->twig->render('pages/layouts/editor.html.twig', [
            'title' => 'Edit Layout: ' . $layout['layout_name'],
            'active_page' => 'layouts',
            'user' => $user,
            'sites' => $sites,
            'siteId' => $siteId,
            'layout' => $layout,
            'missing' => $missing,
            'recommendations' => $recommendations,
        ]);

        return ApiResponse::html($html);
    }
}
