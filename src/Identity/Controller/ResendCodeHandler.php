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
 * POST /register/resend-code — Resend the verification code email.
 *
 * Refuses if invoked within the 30s cooldown window.
 */
final class ResendCodeHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly EmailVerificationService $verification,
        private readonly CsrfService $csrf,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody() ?? [];
        $token = (string) ($body['_csrf_token'] ?? '');

        if (!$this->csrf->validate($token, 'register_verify')) {
            return ApiResponse::error('Session expired.', 403, ['csrf_token' => $this->csrf->generate('register_verify')]);
        }

        $result = $this->verification->resendCode();
        $payload = ['csrf_token' => $this->csrf->generate('register_verify')];

        if (!$result['success']) {
            if (isset($result['retry_after'])) {
                $payload['retry_after'] = $result['retry_after'];
            }
            return ApiResponse::error($result['error'] ?? 'Could not resend code.', 429, $payload);
        }

        return ApiResponse::success($payload);
    }
}
