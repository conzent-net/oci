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
 * POST /register/cancel-verification — Drop the in-progress signup stash.
 *
 * Pure session cleanup; nothing has been persisted, so there is nothing to undo.
 */
final class CancelVerificationHandler implements RequestHandlerInterface
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
            return ApiResponse::error('Session expired.', 403);
        }

        $this->verification->cancelSignup();

        return ApiResponse::noContent();
    }
}
