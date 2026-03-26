<?php

declare(strict_types=1);

namespace OCI\Identity\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Identity\Service\CsrfService;
use OCI\Identity\Service\PasswordResetService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment as TwigEnvironment;

/**
 * POST /forgot-password — Process forgot password form.
 */
final class ForgotPasswordHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly PasswordResetService $resetService,
        private readonly CsrfService $csrf,
        private readonly TwigEnvironment $twig,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody() ?? [];
        $email = trim((string) ($body['email'] ?? ''));
        $csrfToken = (string) ($body['_csrf_token'] ?? '');

        // CSRF check
        if (!$this->csrf->validate($csrfToken, 'forgot_password')) {
            return $this->render('Session expired. Please try again.', false);
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->render('Please enter a valid email address.', false);
        }

        // Always says success (prevent user enumeration)
        $this->resetService->sendResetLink($email);

        return $this->render(null, true);
    }

    private function render(?string $error, bool $success): ResponseInterface
    {
        $html = $this->twig->render('pages/auth/forgot-password.html.twig', [
            'title' => 'Forgot Password',
            'csrf_token' => $this->csrf->generate('forgot_password'),
            'error' => $error,
            'success' => $success,
        ]);

        return ApiResponse::html($html);
    }
}
