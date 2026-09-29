<?php

declare(strict_types=1);

namespace OCI\Identity\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Identity\Service\UserService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/account/delete — Delete the current user's account.
 */
final class AccountDeleteHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly UserService $userService,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::error('Not authenticated.', 401);
        }

        // Admins cannot self-delete
        if ($user['role'] === 'admin') {
            return ApiResponse::error('Admin accounts cannot be self-deleted.', 403);
        }

        $userId = (int) $user['id'];

        $this->userService->deleteUser($userId);

        // Destroy session
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        return ApiResponse::success(['message' => 'Account deleted successfully.']);
    }
}
