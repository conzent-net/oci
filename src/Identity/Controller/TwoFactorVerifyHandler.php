<?php

declare(strict_types=1);

namespace OCI\Identity\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Identity\Service\AuthService;
use OCI\Identity\Service\CsrfService;
use OCI\Identity\Service\RateLimiter;
use OCI\Identity\Service\TwoFactorService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment as TwigEnvironment;

/**
 * GET/POST /2fa/verify — the prompt a pending session is held at.
 *
 * Reached only via TwoFactorMiddleware, which is why this route is inside the
 * `web` group: the user is authenticated, just not yet second-factored.
 */
final class TwoFactorVerifyHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly TwigEnvironment $twig,
        private readonly CsrfService $csrf,
        private readonly TwoFactorService $twoFactor,
        private readonly AuthService $auth,
        private readonly RateLimiter $rateLimiter,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if (!\is_array($user)) {
            return ApiResponse::redirect('/login');
        }

        $userId = (int) $user['id'];

        // Nothing to do: either 2FA is off, or this session already cleared it.
        // Without this, a bookmarked /2fa/verify would show a pointless prompt.
        if (!$this->twoFactor->isEnabled($userId) || $this->auth->isSessionTwoFactorSatisfied()) {
            return ApiResponse::redirect($this->intendedUrl());
        }

        if (strtoupper($request->getMethod()) !== 'POST') {
            return $this->render(null);
        }

        $body = (array) ($request->getParsedBody() ?? []);

        if (!$this->csrf->validate((string) ($body['_csrf_token'] ?? ''), 'two_factor_verify')) {
            return $this->render('Your session expired. Please try again.');
        }

        // Keyed on the user, not the IP: the account is what is under attack,
        // and an attacker changing address must not reset the budget.
        if (!$this->rateLimiter->allow('2fa_verify_' . $userId, 10, 300)) {
            return $this->render('Too many attempts. Wait a few minutes and try again.');
        }

        if (!$this->twoFactor->verify($userId, (string) ($body['code'] ?? ''))) {
            return $this->render('That code was not correct. Codes change every 30 seconds, so check the current one.');
        }

        $this->auth->markSessionTwoFactorSatisfied();

        $response = ApiResponse::redirect($this->intendedUrl());

        // "Trust this device" issues a bypass token. Only offered after a
        // successful code, never before, so it can never be used to skip the
        // first verification on a device.
        if (($body['trust_device'] ?? '') !== '') {
            $cookie = $this->twoFactor->trustDevice(
                $userId,
                $this->deviceLabel($request),
                $request->getServerParams()['REMOTE_ADDR'] ?? null,
            );

            setcookie(TwoFactorService::TRUSTED_DEVICE_COOKIE, $cookie, [
                'expires' => time() + (30 * 86400),
                'path' => '/',
                // Host-only: this cookie is a bypass credential and has no
                // business being sent to any other subdomain.
                'secure' => ($request->getServerParams()['HTTPS'] ?? '') !== '' || ($request->getUri()->getScheme() === 'https'),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        return $response;
    }

    private function render(?string $error): ResponseInterface
    {
        return ApiResponse::html($this->twig->render('pages/auth/two-factor-verify.html.twig', [
            'title' => 'Two-factor authentication',
            'csrf_token' => $this->csrf->generate('two_factor_verify'),
            'error' => $error,
        ]));
    }

    /** Where the user was headed before the prompt interrupted them. */
    private function intendedUrl(): string
    {
        $intended = $_SESSION['intended_url'] ?? '/';
        unset($_SESSION['intended_url']);

        // Only ever a local path: an absolute URL here would turn the login
        // flow into an open redirect.
        if (!\is_string($intended) || $intended === '' || !str_starts_with($intended, '/') || str_starts_with($intended, '//')) {
            return '/';
        }

        return $intended;
    }

    private function deviceLabel(ServerRequestInterface $request): string
    {
        $agent = $request->getHeaderLine('User-Agent');

        return $agent === '' ? 'Unknown device' : mb_substr($agent, 0, 200);
    }
}
