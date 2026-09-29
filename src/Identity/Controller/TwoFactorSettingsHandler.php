<?php

declare(strict_types=1);

namespace OCI\Identity\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Identity\Service\CsrfService;
use OCI\Identity\Service\TwoFactorService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment as TwigEnvironment;

/**
 * GET /account/2fa — the two-factor settings screen.
 *
 * Shows enrolment (QR plus the secret in text, for anyone whose camera will not
 * cooperate) when 2FA is off, and the trusted-device list plus remaining
 * recovery codes when it is on.
 */
final class TwoFactorSettingsHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly TwigEnvironment $twig,
        private readonly CsrfService $csrf,
        private readonly TwoFactorService $twoFactor,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if (!\is_array($user)) {
            return ApiResponse::redirect('/login');
        }

        $userId = (int) $user['id'];
        $enabled = $this->twoFactor->isEnabled($userId);

        $enrolment = null;
        if (!$enabled && $this->twoFactor->isAvailable()) {
            // Re-minted on each visit so an abandoned setup never leaves a
            // stale secret that a later confirm could activate.
            $enrolment = $this->twoFactor->beginEnrolment(
                $userId,
                (string) ($user['email'] ?? 'account'),
            );
        }

        // Recovery codes are shown exactly once, immediately after they are
        // generated, then cleared. Keeping them in the session would leave a
        // full set of bypass credentials sitting in storage and re-rendering on
        // every later visit to this page.
        $recoveryCodes = $_SESSION['2fa_new_recovery_codes'] ?? null;
        unset($_SESSION['2fa_new_recovery_codes']);

        return ApiResponse::html($this->twig->render('pages/account/two-factor.html.twig', [
            'title' => 'Two-factor authentication',
            'active_page' => 'account',
            'user' => $user,
            'csrf_token' => $this->csrf->generate('two_factor'),
            'available' => $this->twoFactor->isAvailable(),
            'enabled' => $enabled,
            'enrolment' => $enrolment,
            'recovery_remaining' => $enabled ? $this->twoFactor->unusedRecoveryCodeCount($userId) : 0,
            'trusted_devices' => $enabled ? $this->twoFactor->listTrustedDevices($userId) : [],
            'recovery_codes' => $recoveryCodes,
        ]));
    }
}
