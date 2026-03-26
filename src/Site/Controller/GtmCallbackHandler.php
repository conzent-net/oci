<?php

declare(strict_types=1);

namespace OCI\Site\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Site\Service\GtmOAuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /gtm/callback — Google OAuth callback.
 *
 * Exchanges the auth code for an access token, stores it in the session,
 * then redirects back to the dashboard.
 */
final class GtmCallbackHandler implements RequestHandlerInterface
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

        $params = $request->getQueryParams();
        $code = $params['code'] ?? '';
        $error = $params['error'] ?? '';

        // Decode state to get site_id and optional return_url
        $siteId = 0;
        $returnUrl = '';
        $state = $params['state'] ?? '';
        if ($state !== '') {
            $decoded = json_decode(base64_decode($state, true) ?: '', true);
            if (\is_array($decoded)) {
                $siteId = (int) ($decoded['site_id'] ?? 0);
                $returnUrl = (string) ($decoded['return_url'] ?? '');
            }
        }

        $dashboardUrl = '/' . ($siteId > 0 ? '?site_id=' . $siteId : '');

        if ($error !== '' || $code === '') {
            // User denied consent or something went wrong
            $fallback = $returnUrl !== '' ? $returnUrl : $dashboardUrl;
            return ApiResponse::redirect($fallback);
        }

        $appUrl = rtrim($_ENV['APP_URL'] ?? '', '/');
        $redirectUri = $appUrl . '/gtm/callback';

        $token = $this->gtmService->exchangeCode($code, $redirectUri);
        if ($token === null) {
            $fallback = $returnUrl !== '' ? $returnUrl : $dashboardUrl;
            return ApiResponse::redirect($fallback);
        }

        // Store access token in session
        $_SESSION['gtm_access_token'] = $token['access_token'];
        $_SESSION['gtm_token_expires'] = time() + (int) ($token['expires_in'] ?? 3600);

        // Redirect to return_url if provided, otherwise back to dashboard
        if ($returnUrl !== '') {
            $sep = str_contains($returnUrl, '?') ? '&' : '?';
            return ApiResponse::redirect($returnUrl . $sep . 'gtm_connected=1');
        }

        return ApiResponse::redirect($dashboardUrl . ($siteId > 0 ? '&' : '?') . 'gtm_connected=1');
    }
}
