<?php

declare(strict_types=1);

namespace OCI\Identity\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Identity\Repository\UserRepositoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/onboarding/complete — Mark the guided tour as completed.
 */
final class OnboardingCompleteHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepo,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::error('Not authenticated.', 401);
        }

        $this->userRepo->update((int) $user['id'], [
            'onboarding_completed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return ApiResponse::success(['completed' => true]);
    }
}
