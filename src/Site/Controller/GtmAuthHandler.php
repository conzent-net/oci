<?php

declare(strict_types=1);

namespace OCI\Site\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Site\Service\GtmOAuthService;
use OCI\Site\Service\SiteAccess;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /gtm/auth — Redirect user to Google OAuth consent screen.
 *
 * After consent, Google redirects back to GET /gtm/callback.
 */
final class GtmAuthHandler implements RequestHandlerInterface
{
    /** Session key holding the one-time value the callback's state must carry. */
    public const NONCE_SESSION_KEY = 'gtm_oauth_nonce';

    public function __construct(
        private readonly GtmOAuthService $gtmService,
        private readonly SiteAccess $siteAccess,
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

        // Pass site_id and return_url through state so we can redirect back.
        // A site that is not the user's is dropped, not refused: it only
        // decides where they land afterwards.
        $params = $request->getQueryParams();
        $siteId = (int) ($params['site_id'] ?? 0);
        if ($siteId > 0 && !$this->siteAccess->owns($user, $siteId)) {
            $siteId = 0;
        }
        $returnUrl = GtmCallbackHandler::safeReturnPath((string) ($params['return_url'] ?? '')) ?? '';

        // The nonce ties the callback to a flow this session started, so a
        // link someone else crafted cannot complete it (CSRF on the callback).
        $nonce = bin2hex(random_bytes(16));
        $_SESSION[self::NONCE_SESSION_KEY] = $nonce;

        $state = base64_encode(json_encode([
            'site_id' => $siteId,
            'return_url' => $returnUrl,
            'nonce' => $nonce,
        ], JSON_THROW_ON_ERROR));

        $authUrl = $this->gtmService->getAuthUrl($redirectUri, $state);

        return ApiResponse::redirect($authUrl);
    }
}
