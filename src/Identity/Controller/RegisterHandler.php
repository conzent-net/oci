<?php

declare(strict_types=1);

namespace OCI\Identity\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Identity\Service\CsrfService;
use OCI\Identity\Service\EmailVerificationService;
use OCI\Identity\Service\GoogleAuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment as TwigEnvironment;

/**
 * POST /register — Process registration form submission.
 *
 * Does NOT create the user row anymore. Instead it stashes the form in the
 * session and emails a 6-digit verification code. AJAX/JSON clients open a
 * modal and submit /register/verify-code. Non-JS clients are redirected to
 * /register/verify (a server-rendered code entry page).
 *
 * Google OAuth signups bypass this flow entirely (AuthService::attemptGoogle
 * creates the user with email_verified='VALID').
 */
final class RegisterHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly EmailVerificationService $verification,
        private readonly CsrfService $csrf,
        private readonly TwigEnvironment $twig,
        private readonly GoogleAuthService $googleAuth,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody() ?? [];
        $email = trim((string) ($body['email'] ?? ''));
        $firstName = trim((string) ($body['first_name'] ?? ''));
        $lastName = trim((string) ($body['last_name'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $passwordConfirm = (string) ($body['password_confirm'] ?? '');
        $csrfToken = (string) ($body['_csrf_token'] ?? '');

        $isAjax = $request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest'
            || str_contains($request->getHeaderLine('Accept'), 'application/json');

        if (!$this->csrf->validate($csrfToken, 'register')) {
            if ($isAjax) {
                return ApiResponse::error('Session expired. Please refresh and try again.', 403);
            }
            return $this->renderWithError($email, $firstName, $lastName, 'Session expired. Please try again.');
        }

        $result = $this->verification->startSignup([
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'password' => $password,
            'password_confirm' => $passwordConfirm,
        ]);

        if (!$result['success']) {
            if ($isAjax) {
                return ApiResponse::error('Please correct the errors below.', 422, $result['errors'] ?? []);
            }
            return $this->renderWithErrors($email, $firstName, $lastName, $result['errors'] ?? []);
        }

        if ($isAjax) {
            return ApiResponse::success([
                'csrf_token' => $this->csrf->generate('register_verify'),
            ]);
        }

        return ApiResponse::redirect('/register/verify');
    }

    private function renderWithError(string $email, string $firstName, string $lastName, string $error): ResponseInterface
    {
        return $this->renderForm($email, $firstName, $lastName, $error, []);
    }

    private function renderWithErrors(string $email, string $firstName, string $lastName, array $errors): ResponseInterface
    {
        return $this->renderForm($email, $firstName, $lastName, null, $errors);
    }

    private function renderForm(string $email, string $firstName, string $lastName, ?string $error, array $errors): ResponseInterface
    {
        $html = $this->twig->render('pages/auth/register.html.twig', [
            'title' => 'Create Account',
            'csrf_token' => $this->csrf->generate('register'),
            'error' => $error,
            'errors' => $errors,
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'google_enabled' => $this->googleAuth->isConfigured(),
        ]);

        return ApiResponse::html($html, 422);
    }
}
