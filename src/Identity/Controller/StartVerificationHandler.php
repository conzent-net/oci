<?php

declare(strict_types=1);

namespace OCI\Identity\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Identity\Service\CsrfService;
use OCI\Identity\Service\EmailVerificationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /register/start-verification — JSON endpoint that validates the
 * signup form, stashes a hashed code in the session, and emails it.
 *
 * Returns a fresh `register_verify` CSRF token in the response payload so
 * the JS can submit /register/verify-code next.
 */
final class StartVerificationHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly EmailVerificationService $verification,
        private readonly CsrfService $csrf,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody() ?? [];
        $token = (string) ($body['_csrf_token'] ?? '');

        if (!$this->csrf->validate($token, 'register')) {
            return ApiResponse::error('Session expired. Please refresh and try again.', 403);
        }

        $result = $this->verification->startSignup([
            'email' => trim((string) ($body['email'] ?? '')),
            'first_name' => trim((string) ($body['first_name'] ?? '')),
            'last_name' => trim((string) ($body['last_name'] ?? '')),
            'password' => (string) ($body['password'] ?? ''),
            'password_confirm' => (string) ($body['password_confirm'] ?? ''),
        ]);

        if (!$result['success']) {
            return ApiResponse::error(
                'Please correct the errors below.',
                422,
                $result['errors'] ?? [],
            );
        }

        return ApiResponse::success([
            'csrf_token' => $this->csrf->generate('register_verify'),
        ]);
    }
}
