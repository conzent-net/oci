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
 * POST /register/verify-code — Check the 6-digit code submitted by the user.
 *
 * Handles both AJAX (JSON response) and the non-JS fallback (HTML redirect).
 */
final class VerifyCodeHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly EmailVerificationService $verification,
        private readonly CsrfService $csrf,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody() ?? [];
        $token = (string) ($body['_csrf_token'] ?? '');
        $code = preg_replace('/\D/', '', (string) ($body['code'] ?? '')) ?? '';

        $isAjax = $this->isAjax($request);

        if (!$this->csrf->validate($token, 'register_verify')) {
            return $this->fail('Session expired. Please refresh and try again.', 403, $isAjax);
        }

        if (\strlen($code) !== 6) {
            return $this->fail('Enter the 6-digit code from your email.', 422, $isAjax);
        }

        $result = $this->verification->verifyCode($code);

        if (!$result['success']) {
            $status = ($result['locked'] ?? false) ? 429 : 422;
            if ($isAjax) {
                return ApiResponse::error(
                    $result['error'] ?? 'Incorrect code.',
                    $status,
                    array_filter([
                        'attempts_left' => $result['attempts_left'] ?? null,
                        'locked' => $result['locked'] ?? null,
                        'expired' => $result['expired'] ?? null,
                        'csrf_token' => $this->csrf->generate('register_verify'),
                    ], static fn($v): bool => $v !== null),
                );
            }
            return ApiResponse::redirect('/register/verify?error=' . urlencode($result['error'] ?? 'Incorrect code.'));
        }

        if ($isAjax) {
            return ApiResponse::success(['redirect' => '/login?registered=1']);
        }
        return ApiResponse::redirect('/login?registered=1');
    }

    private function isAjax(ServerRequestInterface $request): bool
    {
        return $request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest'
            || str_contains($request->getHeaderLine('Accept'), 'application/json');
    }

    private function fail(string $message, int $status, bool $isAjax): ResponseInterface
    {
        if ($isAjax) {
            return ApiResponse::error($message, $status, ['csrf_token' => $this->csrf->generate('register_verify')]);
        }
        return ApiResponse::redirect('/register/verify?error=' . urlencode($message));
    }
}
