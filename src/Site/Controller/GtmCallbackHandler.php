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
 * GET /gtm/callback — Google OAuth callback.
 *
 * Exchanges the auth code for an access token, stores it in the session,
 * then redirects back to the dashboard.
 */
final class GtmCallbackHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly GtmOAuthService $gtmService,
        private readonly SiteAccess $siteAccess,
    ) {}

    /**
     * A return URL we are willing to redirect to: a path on this site, or
     * null. Starts with one '/', never '//' or '/\' (both read as another
     * host by browsers), no backslashes and no control characters.
     */
    public static function safeReturnPath(string $url): ?string
    {
        if ($url === '' || \strlen($url) > 2048 || $url[0] !== '/') {
            return null;
        }
        if (str_starts_with($url, '//') || str_contains($url, '\\')) {
            return null;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return null;
        }

        return $url;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::redirect('/login');
        }

        $params = $request->getQueryParams();
        $code = (string) ($params['code'] ?? '');
        $error = (string) ($params['error'] ?? '');

        // Decode state to get site_id, optional return_url and the nonce
        $siteId = 0;
        $returnUrl = '';
        $nonce = '';
        $state = (string) ($params['state'] ?? '');
        if ($state !== '') {
            $decoded = json_decode(base64_decode($state, true) ?: '', true);
            if (\is_array($decoded)) {
                $siteId = (int) ($decoded['site_id'] ?? 0);
                $returnUrl = \is_string($decoded['return_url'] ?? null) ? $decoded['return_url'] : '';
                $nonce = \is_string($decoded['nonce'] ?? null) ? $decoded['nonce'] : '';
            }
        }

        // One use only, whatever the outcome.
        $expected = $_SESSION[GtmAuthHandler::NONCE_SESSION_KEY] ?? null;
        unset($_SESSION[GtmAuthHandler::NONCE_SESSION_KEY]);

        if (!\is_string($expected) || $expected === '' || !hash_equals($expected, $nonce)) {
            // Not a flow this session started: nothing from the state is
            // trusted and no code is exchanged.
            return ApiResponse::redirect('/');
        }

        if ($siteId > 0 && !$this->siteAccess->owns($user, $siteId)) {
            $siteId = 0;
        }
        $returnUrl = self::safeReturnPath($returnUrl) ?? '';

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
