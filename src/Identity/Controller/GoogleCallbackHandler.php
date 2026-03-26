<?php

declare(strict_types=1);

namespace OCI\Identity\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Identity\Service\AuthService;
use OCI\Identity\Service\GoogleAuthService;
use OCI\Notification\Service\SendMailsService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /auth/google/callback — Handle Google OAuth callback for user login.
 *
 * Exchanges the auth code for a token, fetches the user profile,
 * then logs in (or auto-registers) the user.
 */
final class GoogleCallbackHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly GoogleAuthService $googleAuth,
        private readonly AuthService $auth,
        private readonly SendMailsService $sendMails,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $code = $params['code'] ?? '';
        $error = $params['error'] ?? '';

        if ($error !== '' || $code === '') {
            return ApiResponse::redirect('/login?error=google_denied');
        }

        $appUrl = rtrim($_ENV['APP_URL'] ?? '', '/');
        $redirectUri = $appUrl . '/auth/google/callback';

        // Exchange code for token
        $token = $this->googleAuth->exchangeCode($code, $redirectUri);
        if ($token === null) {
            return ApiResponse::redirect('/login?error=google_failed');
        }

        // Fetch user profile
        $profile = $this->googleAuth->getUserProfile($token['access_token']);
        if ($profile === null) {
            return ApiResponse::redirect('/login?error=google_profile');
        }

        // Attempt login / auto-register
        $ip = $this->getClientIp($request);
        $userAgent = $request->getHeaderLine('User-Agent');

        $result = $this->auth->attemptGoogle($profile, $ip, $userAgent);

        if (!$result['success']) {
            return ApiResponse::redirect('/login?error=' . urlencode($result['error'] ?? 'Login failed'));
        }

        // Create session (remember for 30 days by default for social login)
        $this->auth->createSession($result['user'], $ip, $userAgent, true);

        // Sync to newsletter list
        $this->sendMails->syncSubscriber((int) $result['user']['id']);

        // Redirect to intended URL or dashboard
        $redirectTo = $_SESSION['intended_url'] ?? '/';
        unset($_SESSION['intended_url']);

        return ApiResponse::redirect($redirectTo);
    }

    private function getClientIp(ServerRequestInterface $request): string
    {
        $forwarded = $request->getHeaderLine('X-Forwarded-For');
        if ($forwarded !== '') {
            return explode(',', $forwarded)[0];
        }

        $serverParams = $request->getServerParams();
        return $serverParams['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}
