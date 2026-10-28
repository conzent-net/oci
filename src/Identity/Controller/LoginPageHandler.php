<?php

declare(strict_types=1);

namespace OCI\Identity\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Identity\Service\CsrfService;
use OCI\Identity\Service\GoogleAuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment as TwigEnvironment;

/**
 * GET /login — Show the login form.
 */
final class LoginPageHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly TwigEnvironment $twig,
        private readonly CsrfService $csrf,
        private readonly GoogleAuthService $googleAuth,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Map query param errors from Google callback
        $params = $request->getQueryParams();
        $error = null;
        if (isset($params['error'])) {
            $error = match ($params['error']) {
                'google_denied' => 'Google sign-in was cancelled.',
                'google_failed' => 'Google sign-in failed. Please try again.',
                'google_profile' => 'Could not retrieve your Google profile.',
                default => $params['error'],
            };
        }

        $html = $this->twig->render('pages/auth/login.html.twig', [
            'title' => 'Log In',
            'csrf_token' => $this->csrf->generate('login'),
            'error' => $error,
            'identity' => '',
            'google_enabled' => $this->googleAuth->isConfigured(),
        ]);

        return ApiResponse::html($html);
    }
}
