<?php

declare(strict_types=1);

namespace OCI\Http\Middleware;

use OCI\Http\Response\ApiResponse;
use OCI\Identity\Service\AuthService;
use OCI\Identity\Service\TwoFactorService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Holds a session at the second-factor prompt until it has been satisfied.
 *
 * Why the check lives here and not in the login handler: the login form is not
 * the only way a session comes into existence. `tryRememberMe()` restores a
 * session straight from a cookie without ever calling `attempt()`, and Google
 * OAuth creates one through a different handler entirely. A gate on the login
 * form would be bypassed by both. Every authenticated request passes through
 * middleware, so this is the only honest chokepoint.
 *
 * The state itself lives on the session ROW (`two_factor_verified_at`), not in
 * $_SESSION, which is what makes remember-me inherit it rather than re-prompt,
 * and what lets impersonation hand over a pre-satisfied session.
 */
final class TwoFactorMiddleware implements MiddlewareInterface
{
    /**
     * Paths that must stay reachable while a session is pending, or the user
     * is trapped: the prompt itself, and the way out.
     */
    private const ALLOWED_PREFIXES = [
        '/2fa/verify',
        '/logout',
        '/health',
    ];

    public function __construct(
        private readonly AuthService $auth,
        private readonly TwoFactorService $twoFactor,
    ) {
    }

    public function process(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        $user = $request->getAttribute('user');

        // Unauthenticated requests are AuthMiddleware's problem, not ours.
        if (!\is_array($user) || !isset($user['id'])) {
            return $next($request);
        }

        $path = $request->getUri()->getPath();
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $next($request);
            }
        }

        $userId = (int) $user['id'];

        if (!$this->twoFactor->isEnabled($userId) || $this->auth->isSessionTwoFactorSatisfied()) {
            return $next($request);
        }

        // A trusted device satisfies the prompt without asking again. The token
        // is bound to this user and revoked whenever 2FA is reset, re-enrolled
        // or the password is changed, so it cannot outlive the trust it stands
        // for.
        $cookie = $request->getCookieParams()[TwoFactorService::TRUSTED_DEVICE_COOKIE] ?? null;
        if (\is_string($cookie) && $this->twoFactor->isTrustedDevice($userId, $cookie)) {
            $this->auth->markSessionTwoFactorSatisfied();

            return $next($request);
        }

        // Remember where they were going, so verification returns them there
        // instead of dumping them on the dashboard.
        if (session_status() === \PHP_SESSION_ACTIVE && $request->getMethod() === 'GET') {
            $_SESSION['intended_url'] = (string) $request->getUri()->getPath();
        }

        return ApiResponse::redirect('/2fa/verify');
    }
}
