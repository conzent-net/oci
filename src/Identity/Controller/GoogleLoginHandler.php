<?php

declare(strict_types=1);

namespace OCI\Identity\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Identity\Service\GoogleAuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /auth/google — Redirect to Google OAuth consent screen for user login.
 */
final class GoogleLoginHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly GoogleAuthService $googleAuth,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->googleAuth->isConfigured()) {
            return ApiResponse::redirect('/login');
        }

        $appUrl = rtrim($_ENV['APP_URL'] ?? '', '/');
        $redirectUri = $appUrl . '/auth/google/callback';

        $authUrl = $this->googleAuth->getAuthUrl($redirectUri);

        return ApiResponse::redirect($authUrl);
    }
}
