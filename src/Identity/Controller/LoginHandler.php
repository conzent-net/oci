<?php

declare(strict_types=1);

namespace OCI\Identity\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Identity\Service\AuthService;
use OCI\Identity\Service\CsrfService;
use OCI\Identity\Service\GoogleAuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment as TwigEnvironment;

/**
 * POST /login — Process login form submission.
 */
final class LoginHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly CsrfService $csrf,
        private readonly TwigEnvironment $twig,
        private readonly GoogleAuthService $googleAuth,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody() ?? [];
        $identity = trim((string) ($body['identity'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $remember = !empty($body['remember']);
        $csrfToken = (string) ($body['_csrf_token'] ?? '');

        // CSRF check
        if (!$this->csrf->validate($csrfToken, 'login')) {
            return $this->renderLoginWithError($request, $identity, 'Session expired. Please try again.');
        }

        // Validate input
        if ($identity === '' || $password === '') {
            return $this->renderLoginWithError($request, $identity, 'Please enter your email and password.');
        }

        // Client IP + User Agent
        $ip = $this->getClientIp($request);
        $userAgent = $request->getHeaderLine('User-Agent');

        // Attempt login
        $result = $this->auth->attempt($identity, $password, $ip, $userAgent);

        if (!$result['success']) {
            return $this->renderLoginWithError($request, $identity, $result['error'] ?? 'Login failed.');
        }

        // Create session
        $this->auth->createSession($result['user'], $ip, $userAgent, $remember);

        // Mark session as impersonating if override password was used
        if (!empty($result['impersonating'])) {
            $_SESSION['impersonating_from'] = 0; // no original admin user — direct override login
        }

        // Redirect to intended URL or dashboard
        $redirectTo = $_SESSION['intended_url'] ?? '/';
        unset($_SESSION['intended_url']);

        return ApiResponse::redirect($redirectTo);
    }

    private function renderLoginWithError(ServerRequestInterface $request, string $identity, string $error): ResponseInterface
    {
        $html = $this->twig->render('pages/auth/login.html.twig', [
            'title' => 'Log In',
            'csrf_token' => $this->csrf->generate('login'),
            'error' => $error,
            'identity' => $identity,
            'google_enabled' => $this->googleAuth->isConfigured(),
        ]);

        return ApiResponse::html($html, 422);
    }

    private function getClientIp(ServerRequestInterface $request): string
    {
        $serverParams = $request->getServerParams();
        $forwarded = $request->getHeaderLine('X-Forwarded-For');

        if ($forwarded !== '') {
            return explode(',', $forwarded)[0];
        }

        return $serverParams['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}
