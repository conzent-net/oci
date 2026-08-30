<?php

declare(strict_types=1);

namespace OCI\Identity\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Identity\Service\CsrfService;
use OCI\Identity\Service\EmailValidator;
use OCI\Identity\Service\EmailVerificationService;
use OCI\Identity\Service\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /register/start-verification — JSON endpoint that validates the
 * signup form, stashes a hashed code in the session, and emails it.
 *
 * Returns a fresh `register_verify` CSRF token in the response payload so
 * the JS can submit /register/verify-code next.
 *
 * Every accepted request here sends a real SES email, so two bot gates run
 * before the form is even validated: a honeypot field (bots fill it, the
 * form visually hides it) and a per-IP fixed-window rate limit. These cover
 * the case validation can't — a syntactically valid mailbox at a big
 * provider, which accepts-then-bounces and damages SES reputation.
 */
final class StartVerificationHandler implements RequestHandlerInterface
{
    /** Signup starts allowed per IP per window. Humans mistype maybe twice. */
    private const RATE_LIMIT = 5;
    private const RATE_WINDOW_SECONDS = 900;

    public function __construct(
        private readonly EmailVerificationService $verification,
        private readonly CsrfService $csrf,
        private readonly RateLimiter $rateLimiter,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody() ?? [];
        $token = (string) ($body['_csrf_token'] ?? '');

        if (!$this->csrf->validate($token, 'register')) {
            return ApiResponse::error('Session expired. Please refresh and try again.', 403);
        }

        // CSRF tokens are single-use — validate() just consumed this one. Every
        // recoverable failure below must hand back a fresh `register` token or
        // the user's SECOND attempt dies with "Session expired" no matter what
        // they fixed.
        $freshToken = fn (): array => ['csrf_token' => $this->csrf->generate('register')];

        // Honeypot: the `website` field is visually hidden and tab-skipped in
        // the template — a human never fills it. Answer with the ordinary
        // inline validation error so the bot learns nothing, and send nothing.
        if (trim((string) ($body['website'] ?? '')) !== '') {
            $this->logger->info('Signup honeypot tripped', ['ip' => $this->clientIp($request)]);

            return ApiResponse::error(
                'Please correct the errors below.',
                422,
                ['email' => EmailValidator::ERROR_MESSAGE],
                $freshToken(),
            );
        }

        $ip = $this->clientIp($request);
        if ($ip !== '' && !$this->rateLimiter->allow('signup-start:' . $ip, self::RATE_LIMIT, self::RATE_WINDOW_SECONDS)) {
            $this->logger->warning('Signup rate limit hit', ['ip' => $ip]);

            return ApiResponse::error('Too many attempts. Please try again later.', 429, [], $freshToken());
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
                $freshToken(),
            );
        }

        return ApiResponse::success([
            'csrf_token' => $this->csrf->generate('register_verify'),
        ]);
    }

    /**
     * Same resolution order as ConsentLogHandler: Cloudflare's header first,
     * then the standard proxy headers, then the socket address.
     */
    private function clientIp(ServerRequestInterface $request): string
    {
        $serverParams = $request->getServerParams();

        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $header) {
            $ip = (string) ($serverParams[$header] ?? '');
            if ($ip !== '') {
                return trim(explode(',', $ip)[0]);
            }
        }

        return '';
    }
}
