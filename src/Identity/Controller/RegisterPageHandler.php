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
 * GET /register — Show the registration form.
 */
final class RegisterPageHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly TwigEnvironment $twig,
        private readonly CsrfService $csrf,
        private readonly GoogleAuthService $googleAuth,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $html = $this->twig->render('pages/auth/register.html.twig', [
            'title' => 'Create Account',
            'csrf_token' => $this->csrf->generate('register'),
            'error' => null,
            'errors' => [],
            'email' => '',
            'first_name' => '',
            'last_name' => '',
            'google_enabled' => $this->googleAuth->isConfigured(),
        ]);

        return ApiResponse::html($html);
    }
}
