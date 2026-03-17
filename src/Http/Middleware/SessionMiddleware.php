<?php

declare(strict_types=1);

namespace OCI\Http\Middleware;

use OCI\Identity\Service\AuthService;
use OCI\Identity\Service\CmpValidationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment as TwigEnvironment;

/**
 * Starts the PHP session and loads the current user into the request.
 *
 * Adds 'user' attribute to the request (null if not authenticated).
 * Also sets the user as a Twig global for use in templates.
 * Validates CMP ID against the IAB registry (cached in session).
 * Must run before AuthMiddleware / GuestOnlyMiddleware.
 */
final class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly CmpValidationService $cmpValidation,
        private readonly TwigEnvironment $twig,
    ) {}

    public function process(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $user = $this->auth->getCurrentUser();

        // Attach user to request (null = guest)
        $request = $request->withAttribute('user', $user);

        // Make user available in all Twig templates
        $this->twig->addGlobal('current_user', $user);

        // Onboarding tour: show if user has not completed the guided tour
        $this->twig->addGlobal('show_onboarding_tour', $user !== null && empty($user['onboarding_completed_at']));

        // Impersonation state — show warning bar in navbar
        $this->twig->addGlobal('is_impersonating', isset($_SESSION['impersonating_from']));
        $this->twig->addGlobal('impersonator_role', $_SESSION['impersonating_role'] ?? null);

        // Flash messages
        $flash = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        $this->twig->addGlobal('flash', $flash);

        // CMP/TCF validation — cached in session, checked once per login
        $cmp = $this->cmpValidation->getValidation();
        $this->twig->addGlobal('cmp_id', $cmp['cmp_id']);
        $this->twig->addGlobal('cmp_name', $cmp['cmp_name']);
        $this->twig->addGlobal('cmp_valid', $cmp['valid']);
        $this->twig->addGlobal('tcf_enabled', $cmp['valid']);

        return $next($request);
    }
}
