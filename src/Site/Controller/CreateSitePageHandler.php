<?php

declare(strict_types=1);

namespace OCI\Site\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Site\Service\SiteCreationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment as TwigEnvironment;

/**
 * GET /sites/create — Render the "Create Site" page.
 *
 * Shows a different experience for first-time users (zero sites)
 * vs. returning users (sites count > 0).
 */
final class CreateSitePageHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteCreationService $siteCreationService,
        private readonly TwigEnvironment $twig,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, mixed>|null $user */
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::redirect('/login');
        }

        $userId = (int) $user['id'];
        $siteCount = $this->siteCreationService->getSiteCount($userId);
        $languages = $this->siteCreationService->getAvailableLanguages();
        $isFirstSite = $siteCount === 0;

        $html = $this->twig->render('pages/sites/create.html.twig', [
            'title' => $isFirstSite ? 'Welcome — Add Your First Site' : 'Add New Site',
            'user' => $user,
            'isFirstSite' => $isFirstSite,
            'siteCount' => $siteCount,
            'languages' => $languages,
            'errors' => [],
            'old' => [],
        ]);

        return ApiResponse::html($html);
    }
}
