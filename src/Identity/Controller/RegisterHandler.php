<?php

declare(strict_types=1);

namespace OCI\Identity\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Identity\Service\AuthService;
use OCI\Identity\Service\CsrfService;
use OCI\Identity\Service\GoogleAuthService;
use OCI\Identity\Service\UserService;
use OCI\Identity\Repository\UserRepositoryInterface;
use OCI\Notification\Service\SendMailsService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment as TwigEnvironment;

/**
 * POST /register — Process registration form submission.
 */
final class RegisterHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly UserService $userService,
        private readonly UserRepositoryInterface $userRepo,
        private readonly AuthService $auth,
        private readonly CsrfService $csrf,
        private readonly TwigEnvironment $twig,
        private readonly GoogleAuthService $googleAuth,
        private readonly SendMailsService $sendMails,
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

        // CSRF check
        if (!$this->csrf->validate($csrfToken, 'register')) {
            return $this->renderWithError($email, $firstName, $lastName, 'Session expired. Please try again.');
        }

        // Password confirmation
        if ($password !== $passwordConfirm) {
            return $this->renderWithErrors($email, $firstName, $lastName, ['password_confirm' => 'Passwords do not match.']);
        }

        // Delegate to service
        $result = $this->userService->createUser([
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'password' => $password,
            'role' => 'customer',
            'is_active' => 1,
        ]);

        if (!$result['success']) {
            return $this->renderWithErrors($email, $firstName, $lastName, $result['errors'] ?? []);
        }

        // Auto-login: fetch the created user and create session
        $user = $this->userRepo->findById($result['user_id']);
        $ip = $this->getClientIp($request);
        $userAgent = $request->getHeaderLine('User-Agent');
        $this->auth->createSession($user, $ip, $userAgent);

        // Sync to newsletter list
        $this->sendMails->syncSubscriber($result['user_id']);

        return ApiResponse::redirect('/');
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
