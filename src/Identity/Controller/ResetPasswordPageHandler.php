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
 * GET /reset-password?token=...&email=... — Show the reset password form.
 */
final class ResetPasswordPageHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly PasswordResetService $resetService,
        private readonly CsrfService $csrf,
        private readonly TwigEnvironment $twig,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $token = (string) ($params['token'] ?? '');
        $email = (string) ($params['email'] ?? '');

        if ($token === '' || $email === '') {
            return ApiResponse::redirect('/forgot-password');
        }

        // Validate token before showing form
        $validation = $this->resetService->validateToken($email, $token);

        if (!$validation['valid']) {
            $html = $this->twig->render('pages/auth/reset-password.html.twig', [
                'title' => 'Reset Password',
                'csrf_token' => '',
                'error' => $validation['error'] ?? 'Invalid or expired link.',
                'token' => '',
                'email' => '',
                'invalid_token' => true,
            ]);

            return ApiResponse::html($html, 422);
        }

        $html = $this->twig->render('pages/auth/reset-password.html.twig', [
            'title' => 'Reset Password',
            'csrf_token' => $this->csrf->generate('reset_password'),
            'error' => null,
            'token' => $token,
            'email' => $email,
            'invalid_token' => false,
        ]);

        return ApiResponse::html($html);
    }
}
