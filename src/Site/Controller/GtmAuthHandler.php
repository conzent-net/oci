<?php

declare(strict_types=1);

namespace OCI\Site\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Site\Service\GtmOAuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /gtm/auth — Redirect user to Google OAuth consent screen.
 *
 * After consent, Google redirects back to GET /gtm/callback.
 */
final class GtmAuthHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly GtmOAuthService $gtmService,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::redirect('/login');
        }

        if (!$this->gtmService->isConfigured()) {
            return ApiResponse::error('Google Tag Manager integration is not configured', 503);
        }

        $appUrl = rtrim($_ENV['APP_URL'] ?? '', '/');
        $redirectUri = $appUrl . '/gtm/callback';

        // Pass site_id and return_url through state so we can redirect back
        $params = $request->getQueryParams();
        $siteId = (int) ($params['site_id'] ?? 0);
        $returnUrl = (string) ($params['return_url'] ?? '');
        $state = base64_encode(json_encode([
            'site_id' => $siteId,
            'return_url' => $returnUrl,
        ], JSON_THROW_ON_ERROR));

        $authUrl = $this->gtmService->getAuthUrl($redirectUri, $state);

        return ApiResponse::redirect($authUrl);
    }
}
