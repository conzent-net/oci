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
 * POST /reset-password — Process the new password submission.
 */
final class ResetPasswordHandler implements RequestHandlerInterface
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
        $token = (string) ($body['token'] ?? '');
        $password = (string) ($body['password'] ?? '');
        $passwordConfirm = (string) ($body['password_confirm'] ?? '');
        $csrfToken = (string) ($body['_csrf_token'] ?? '');

        // CSRF check
        if (!$this->csrf->validate($csrfToken, 'reset_password')) {
            return $this->renderError($token, $email, 'Session expired. Please try again.');
        }

        // Validate passwords
        if ($password === '' || strlen($password) < 8) {
            return $this->renderError($token, $email, 'Password must be at least 8 characters.');
        }

        if ($password !== $passwordConfirm) {
            return $this->renderError($token, $email, 'Passwords do not match.');
        }

        // Reset the password
        $success = $this->resetService->resetPassword($email, $token, $password);

        if (!$success) {
            return $this->renderError($token, $email, 'Invalid or expired reset link. Please request a new one.');
        }

        // Show success → redirect to login
        $html = $this->twig->render('pages/auth/reset-password-success.html.twig', [
            'title' => 'Password Reset',
        ]);

        return ApiResponse::html($html);
    }

    private function renderError(string $token, string $email, string $error): ResponseInterface
    {
        $html = $this->twig->render('pages/auth/reset-password.html.twig', [
            'title' => 'Reset Password',
            'csrf_token' => $this->csrf->generate('reset_password'),
            'error' => $error,
            'token' => $token,
            'email' => $email,
            'invalid_token' => false,
        ]);

        return ApiResponse::html($html, 422);
    }
}
