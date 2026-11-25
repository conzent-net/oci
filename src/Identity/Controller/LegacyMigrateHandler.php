<?php

declare(strict_types=1);

namespace OCI\Identity\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Identity\Repository\UserRepositoryInterface;
use OCI\Identity\Service\CsrfService;
use OCI\Identity\Service\LegacyAccountMigrationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/account/legacy-migrate — Migrate data from legacy Conzent account.
 */
final class LegacyMigrateHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly CsrfService $csrf,
        private readonly UserRepositoryInterface $userRepo,
        private readonly LegacyAccountMigrationService $legacyMigration,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::error('Unauthorized', 401);
        }

        // CSRF check
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $token = (string) ($body['_csrf_token'] ?? '');
        if (!$this->csrf->validate($token, 'account_profile')) {
            return ApiResponse::error('Invalid security token. Please reload the page and try again.', 403);
        }

        if (!$this->legacyMigration->isAvailable()) {
            return ApiResponse::error('Legacy migration is not available.', 400);
        }

        // Already migrated?
        if (!empty($user['legacy_migrated_at'])) {
            return ApiResponse::error('Your legacy account has already been migrated.', 400);
        }

        $userId = (int) $user['id'];
        $email = (string) $user['email'];

        try {
            // If user hasn't filled in their name yet (onboarding), copy from legacy
            $legacyAccount = $this->legacyMigration->findLegacyAccount($email);
            $updateData = ['legacy_migrated_at' => date('Y-m-d H:i:s')];

            if ($legacyAccount !== null) {
                $legacyUser = $legacyAccount['user'];
                if (empty($user['first_name']) && !empty($legacyUser['firstname'])) {
                    $updateData['first_name'] = (string) $legacyUser['firstname'];
                }
                if (empty($user['last_name']) && !empty($legacyUser['lastname'])) {
                    $updateData['last_name'] = (string) $legacyUser['lastname'];
                }
            }

            $result = $this->legacyMigration->migrateAccount($userId, $email);

            // Mark migration as completed (+ copy name if needed)
            $this->userRepo->update($userId, $updateData);

            return ApiResponse::success($result);
        } catch (\Throwable $e) {
            return ApiResponse::error('Migration failed: ' . $e->getMessage(), 500);
        }
    }
}
