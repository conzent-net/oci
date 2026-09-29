<?php

declare(strict_types=1);

namespace OCI\Identity\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Identity\Repository\UserRepositoryInterface;
use OCI\Identity\Service\CsrfService;
use OCI\Identity\Service\LegacyAccountMigrationService;
use OCI\Identity\Service\UserService;
use OCI\Notification\Service\SendMailsService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment as TwigEnvironment;

/**
 * GET  /account — Account profile page.
 * POST /account — Update profile + company info.
 */
final class AccountProfileHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly TwigEnvironment $twig,
        private readonly CsrfService $csrf,
        private readonly UserRepositoryInterface $userRepo,
        private readonly UserService $userService,
        private readonly LegacyAccountMigrationService $legacyMigration,
        private readonly SendMailsService $sendMails,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::redirect('/login');
        }

        if ($request->getMethod() === 'POST') {
            return $this->handlePost($request, $user);
        }

        return $this->renderForm($user);
    }

    private function renderForm(array $user, ?string $success = null, ?string $error = null, array $old = []): ResponseInterface
    {
        $company = $this->userRepo->getUserCompany((int) $user['id']) ?? [];

        // Legacy migration preview (only if not yet migrated and legacy DB is configured)
        $legacyPreview = null;
        $alreadyMigrated = !empty($user['legacy_migrated_at']);
        if (!$alreadyMigrated && $this->legacyMigration->isAvailable()) {
            try {
                $legacyPreview = $this->legacyMigration->previewMigration((string) $user['email']);
            } catch (\Throwable) {
                // Silently ignore legacy DB errors on the profile page
            }
        }

        $html = $this->twig->render('pages/account/profile.html.twig', [
            'csrf_token' => $this->csrf->generate('account_profile'),
            'active_page' => 'account',
            'success' => $success,
            'error' => $error,
            'user' => $user,
            'company' => $company,
            'old' => $old,
            'legacy_preview' => $legacyPreview,
            'already_migrated' => $alreadyMigrated,
        ]);

        return ApiResponse::html($html);
    }

    private function handlePost(ServerRequestInterface $request, array $user): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $userId = (int) $user['id'];

        // CSRF check
        $token = (string) ($body['_csrf_token'] ?? '');
        if (!$this->csrf->validate($token, 'account_profile')) {
            return $this->renderForm($user, null, 'Invalid security token. Please try again.', $body);
        }

        $firstName = trim((string) ($body['first_name'] ?? ''));
        $lastName = trim((string) ($body['last_name'] ?? ''));
        $email = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $passwordConfirm = (string) ($body['password_confirm'] ?? '');

        // Validate required fields
        if ($firstName === '' || $lastName === '' || $email === '') {
            return $this->renderForm($user, null, 'First name, last name and email are required.', $body);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->renderForm($user, null, 'Please enter a valid email address.', $body);
        }

        // Password validation (only if changing)
        $updateData = [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
        ];

        if ($password !== '') {
            if (\strlen($password) < 8) {
                return $this->renderForm($user, null, 'Password must be at least 8 characters.', $body);
            }
            if ($password !== $passwordConfirm) {
                return $this->renderForm($user, null, 'Passwords do not match.', $body);
            }
            $updateData['password'] = $password;
        }

        // Update user via service (handles email uniqueness check)
        $result = $this->userService->updateUser($userId, $updateData);
        if (!$result['success']) {
            $errorMsg = implode(' ', $result['errors'] ?? ['Update failed.']);
            return $this->renderForm($user, null, $errorMsg, $body);
        }

        // Update company info
        $this->userRepo->upsertUserCompany($userId, [
            'company_name' => trim((string) ($body['company_name'] ?? '')),
            'vat_number' => trim((string) ($body['vat_number'] ?? '')),
            'address' => trim((string) ($body['address'] ?? '')),
            'city' => trim((string) ($body['city'] ?? '')),
            'zip' => trim((string) ($body['zip'] ?? '')),
            'state' => trim((string) ($body['state'] ?? '')),
            'country_code' => trim((string) ($body['country_code'] ?? '')),
            'phone' => trim((string) ($body['phone'] ?? '')),
        ]);

        // Sync to newsletter list (after both user + company are saved)
        $this->sendMails->syncSubscriber($userId);

        // Re-fetch user for the form
        $updatedUser = $this->userRepo->findById($userId) ?? $user;

        return $this->renderForm($updatedUser, 'Profile updated successfully.');
    }
}
