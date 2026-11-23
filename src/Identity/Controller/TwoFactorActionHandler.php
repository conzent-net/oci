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

/**
 * POST /account/2fa — enable, disable, regenerate recovery codes, revoke a
 * trusted device.
 */
final class TwoFactorActionHandler implements RequestHandlerInterface
{
    public function __construct(
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

        $body = (array) ($request->getParsedBody() ?? []);

        if (!$this->csrf->validate((string) ($body['_csrf_token'] ?? ''), 'two_factor')) {
            $_SESSION['flash_error'] = 'Your session expired. Please try again.';

            return ApiResponse::redirect('/account/2fa');
        }

        $userId = (int) $user['id'];

        return match ((string) ($body['action'] ?? '')) {
            'enable' => $this->enable($userId, (string) ($body['code'] ?? '')),
            'disable' => $this->disable($userId, (string) ($body['code'] ?? '')),
            'regenerate' => $this->regenerate($userId, (string) ($body['code'] ?? '')),
            'revoke_devices' => $this->revokeDevices($userId),
            default => ApiResponse::redirect('/account/2fa'),
        };
    }

    private function enable(int $userId, string $code): ResponseInterface
    {
        // Throttled like any other credential check: six digits is only a
        // million possibilities, and an unthrottled endpoint makes that a
        // feasible online attack rather than a theoretical one.
        if (!$this->rateLimiter->allow('2fa_enrol_' . $userId, 10, 300)) {
            $_SESSION['flash_error'] = 'Too many attempts. Wait a few minutes and try again.';

            return ApiResponse::redirect('/account/2fa');
        }

        $codes = $this->twoFactor->confirmEnrolment($userId, $code);

        if ($codes === null) {
            $_SESSION['flash_error'] = 'That code was not correct. Check your authenticator app and try again.';

            return ApiResponse::redirect('/account/2fa');
        }

        // The session that just enabled 2FA has demonstrably satisfied it, so
        // stamp it rather than immediately bouncing the user to a prompt.
        $this->auth->markSessionTwoFactorSatisfied();

        $_SESSION['2fa_new_recovery_codes'] = $codes;
        $_SESSION['flash_success'] = 'Two-factor authentication is on. Save your recovery codes now.';

        return ApiResponse::redirect('/account/2fa');
    }

    private function disable(int $userId, string $code): ResponseInterface
    {
        // Turning 2FA OFF requires a current code. Otherwise anyone who walks
        // up to an unlocked, already-authenticated browser can quietly remove
        // the second factor, which would make it decorative.
        if (!$this->rateLimiter->allow('2fa_disable_' . $userId, 10, 300)) {
            $_SESSION['flash_error'] = 'Too many attempts. Wait a few minutes and try again.';

            return ApiResponse::redirect('/account/2fa');
        }

        if (!$this->twoFactor->verify($userId, $code)) {
            $_SESSION['flash_error'] = 'Enter a current code from your authenticator app to turn two-factor off.';

            return ApiResponse::redirect('/account/2fa');
        }

        $this->twoFactor->reset($userId, $userId);
        $_SESSION['flash_success'] = 'Two-factor authentication is off, and trusted devices were cleared.';

        return ApiResponse::redirect('/account/2fa');
    }

    private function regenerate(int $userId, string $code): ResponseInterface
    {
        if (!$this->rateLimiter->allow('2fa_regen_' . $userId, 10, 300)) {
            $_SESSION['flash_error'] = 'Too many attempts. Wait a few minutes and try again.';

            return ApiResponse::redirect('/account/2fa');
        }

        if (!$this->twoFactor->verify($userId, $code)) {
            $_SESSION['flash_error'] = 'Enter a current code to generate new recovery codes.';

            return ApiResponse::redirect('/account/2fa');
        }

        $_SESSION['2fa_new_recovery_codes'] = $this->twoFactor->regenerateRecoveryCodes($userId);
        $_SESSION['flash_success'] = 'New recovery codes generated. The previous set no longer works.';

        return ApiResponse::redirect('/account/2fa');
    }

    private function revokeDevices(int $userId): ResponseInterface
    {
        $this->twoFactor->revokeTrustedDevices($userId);
        $_SESSION['flash_success'] = 'Trusted devices cleared. Every device will ask for a code next time.';

        return ApiResponse::redirect('/account/2fa');
    }
}
