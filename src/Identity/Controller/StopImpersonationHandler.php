<?php

declare(strict_types=1);

namespace OCI\Identity\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Identity\Service\UserService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/stop-impersonation — End impersonation session.
 *
 * Works for both admin impersonation and override-password logins.
 * Uses 'web' middleware so non-admin impersonated users can stop.
 */
final class StopImpersonationHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly UserService $userService,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::redirect('/login');
        }

        if (!isset($_SESSION['impersonating_from'])) {
            return ApiResponse::error('Not impersonating.', 400);
        }

        $wasOverride = $_SESSION['impersonating_from'] === 0;
        $this->userService->stopImpersonation();

        return ApiResponse::success([
            'message' => 'Impersonation ended.',
            'redirect' => $wasOverride ? '/logout' : '/admin/users',
        ]);
    }
}
