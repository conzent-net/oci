<?php

declare(strict_types=1);

namespace OCI\Identity\Service;

use OCI\Admin\Service\AuditLogService;
use OCI\Identity\Repository\UserRepositoryInterface;
use OCI\Identity\Service\AuthService;
use Psr\Log\LoggerInterface;

/**
 * User management: CRUD, role changes, activation, impersonation.
 */
final class UserService
{
    /**
     * How long one impersonation session lasts before it stops being trusted.
     *
     * Impersonation had no expiry at all: a session opened to fix one thing
     * stayed open until the browser was closed, and anyone who later sat at
     * that machine was signed in as somebody else's customer. Two hours is
     * long enough for real work and short enough that a forgotten tab is not
     * a standing grant.
     */
    private const IMPERSONATION_TTL = 7200;

    public function __construct(
        private readonly UserRepositoryInterface $userRepo,
        private readonly AuthService $auth,
        private readonly LoggerInterface $logger,
        // Required, not nullable. As `?AuditLogService $audit = null` PHP-DI
        // declined to inject it and every impersonation audit call silently
        // did nothing — an audit trail that quietly writes no rows is worse
        // than none, because it looks like coverage.
        private readonly AuditLogService $audit,
    ) {}

    /**
     * List users with filtering and pagination.
     *
     * Each row carries its canonical subscription (sub_* keys), site counts and
     * last recorded payment — see UserRepositoryInterface::findAll().
     *
     * @return array{users: array, total: int, page: int, perPage: int}
     */
    public function listUsers(?string $role, ?string $search, bool $includeDeleted, int $page, int $perPage, ?string $subscriptionStatus = null): array
    {
        $offset = ($page - 1) * $perPage;
        $users = $this->userRepo->findAll($role, $search, $includeDeleted, $perPage, $offset, $subscriptionStatus);
        $total = $this->userRepo->countAll($role, $search, $includeDeleted, $subscriptionStatus);

        return [
            'users' => $users,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
        ];
    }

    /**
     * @return array{success: bool, user_id?: int, errors?: array<string, string>}
     */
    public function createUser(array $data): array
    {
        $errors = $this->validateUser($data);
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        // Check email uniqueness
        $existing = $this->userRepo->findByEmail($data['email']);
        if ($existing !== null) {
            return ['success' => false, 'errors' => ['email' => 'Email is already in use.']];
        }

        $userId = $this->userRepo->create([
            'email' => $data['email'],
            'username' => $data['username'] ?? $data['email'],
            'first_name' => $data['first_name'] ?? '',
            'last_name' => $data['last_name'] ?? '',
            'password' => $data['password'],
            'role' => $data['role'] ?? 'customer',
            'is_active' => (int) ($data['is_active'] ?? 1),
        ]);

        $this->logger->info('User created', ['user_id' => $userId, 'email' => $data['email']]);

        return ['success' => true, 'user_id' => $userId];
    }

    /**
     * @return array{success: bool, errors?: array<string, string>}
     */
    public function updateUser(int $id, array $data): array
    {
        $user = $this->userRepo->findById($id);
        if ($user === null) {
            return ['success' => false, 'errors' => ['id' => 'User not found.']];
        }

        // If email changed, check uniqueness
        if (isset($data['email']) && $data['email'] !== $user['email']) {
            $existing = $this->userRepo->findByEmail($data['email']);
            if ($existing !== null) {
                return ['success' => false, 'errors' => ['email' => 'Email is already in use.']];
            }
        }

        $updateData = [];
        foreach (['email', 'username', 'first_name', 'last_name', 'role', 'password'] as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $updateData[$field] = $data[$field];
            }
        }

        if (isset($data['is_active'])) {
            $updateData['is_active'] = (int) $data['is_active'];
        }

        if ($updateData !== []) {
            $this->userRepo->update($id, $updateData);
            $this->logger->info('User updated', ['user_id' => $id]);
        }

        return ['success' => true];
    }

    public function deleteUser(int $id): void
    {
        $this->userRepo->softDelete($id);
        $this->userRepo->destroyUserSessions($id);
        $this->logger->info('User soft-deleted', ['user_id' => $id]);
    }

    public function restoreUser(int $id): void
    {
        $this->userRepo->restore($id);
        $this->logger->info('User restored', ['user_id' => $id]);
    }

    public function destroyUser(int $id): void
    {
        $this->userRepo->destroy($id);
        $this->logger->info('User permanently deleted', ['user_id' => $id]);
    }

    public function changeRole(int $id, string $role): void
    {
        $allowed = ['admin', 'customer', 'agency'];
        if (!\in_array($role, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid role: {$role}");
        }

        $this->userRepo->updateRole($id, $role);
        $this->logger->info('User role changed', ['user_id' => $id, 'role' => $role]);
    }

    /**
     * End every session a user has, forcing a fresh login.
     *
     * Used after a credential changes underneath them, such as an operator
     * resetting their two-factor authentication: the sessions that exist were
     * established under the old credential and should not outlive it.
     */
    public function destroySessions(int $id): void
    {
        $this->userRepo->destroyUserSessions($id);
    }

    public function toggleActive(int $id, bool $active): void
    {
        $this->userRepo->setActive($id, $active);
        if (!$active) {
            $this->userRepo->destroyUserSessions($id);
        }
        $this->logger->info('User active status changed', ['user_id' => $id, 'active' => $active]);
    }

    public function resetLoginAttempts(int $id): void
    {
        $this->userRepo->resetLoginAttempts($id);
        $this->logger->info('Login attempts reset', ['user_id' => $id]);
    }

    /**
     * Start impersonation session for an admin logging in as another user.
     *
     * @return array<string, mixed>|null The target user, or null if not found
     */
    /**
     * @param string|null $returnTo where to send them when they stop — the page
     *                              they started from. Validated as a relative
     *                              same-site path; anything else is ignored.
     */
    public function startImpersonation(int $targetUserId, ?string $returnTo = null): ?array
    {
        $user = $this->userRepo->findById($targetUserId);
        if ($user === null || $user['deleted_at'] !== null) {
            return null;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $originalUserId = $_SESSION['user_id'];
        $originalSessionId = $_SESSION['session_id'];

        // Loaded before the second-factor check, which needs to know whether
        // the impersonator has 2FA of their own.
        $originalUser = $this->userRepo->findById($originalUserId);

        // Impersonation must not become a 2FA bypass: a customer enables 2FA,
        // and an account protected only by a password walks into it anyway.
        //
        // The check used to be "this session cleared a second factor", full
        // stop. That is unsatisfiable for an impersonator who has no 2FA
        // enrolled — TwoFactorMiddleware lets those users through WITHOUT
        // stamping the session, so the stamp is never set and impersonation was
        // impossible for them. Every agency and admin without 2FA was locked
        // out of a core feature, and told "User not found".
        //
        // The question that actually matches the intent is narrower: is anybody
        // in this pair relying on a second factor? If the TARGET has 2FA, the
        // impersonator must have cleared one too, or the target's second factor
        // means nothing. If the IMPERSONATOR has 2FA, their own session has to
        // honour it rather than skipping past it.
        $targetHas2fa = ($user['totp_confirmed_at'] ?? null) !== null;
        $impersonatorHas2fa = ($originalUser['totp_confirmed_at'] ?? null) !== null;

        if (($targetHas2fa || $impersonatorHas2fa) && !$this->auth->isSessionTwoFactorSatisfied($originalSessionId)) {
            $this->logger->warning('Impersonation refused: second factor not satisfied', [
                'admin_id' => $originalUserId,
                'target_id' => $targetUserId,
                'target_has_2fa' => $targetHas2fa,
                'impersonator_has_2fa' => $impersonatorHas2fa,
            ]);

            // Thrown, not returned as null. The caller cannot distinguish "no
            // such user" from "refused" when both are null, which is exactly
            // how a 2FA refusal reached the UI as "User not found".
            throw new \RuntimeException(
                $targetHas2fa && !$impersonatorHas2fa
                    ? 'This customer protects their account with two-factor authentication. '
                        . 'Enable it on your own account before signing in as them.'
                    : 'Verify your second factor before signing in as a customer.',
            );
        }

        // Create a real session for the target user so getCurrentUser() works.
        // Pre-stamped as two-factor satisfied: the impersonator proved who THEY
        // are, and cannot possess the target's TOTP secret, so demanding the
        // target's code here would make impersonation impossible rather than
        // more secure.
        $this->auth->createSession(
            $user,
            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            false,
            true,
        );

        // Mark as impersonating — store original user's id, session, and role
        $_SESSION['impersonating_from'] = $originalUserId;
        $_SESSION['impersonating_session'] = $originalSessionId;
        $_SESSION['impersonating_role'] = $originalUser['role'] ?? 'admin';
        $_SESSION['impersonating_until'] = time() + self::IMPERSONATION_TTL;

        // Where to land on the way back. The account's ROLE is the wrong signal
        // here: an admin who impersonates from the agency screens was acting as
        // an agency, and sending them to /admin because of what their account
        // is drops them somewhere they were not and did not ask for.
        $_SESSION['impersonating_return_url'] = $this->safeReturnPath($returnTo);

        $this->logger->info('Impersonation started', [
            'admin_id' => $originalUserId,
            'target_id' => $targetUserId,
        ]);

        // A Monolog line is not a record. Signing in as somebody else is the
        // single most sensitive thing this product lets one account do to
        // another — an agency reading a client's consent data, or editing it —
        // and until now it left nothing an operator or a regulator could
        // query. It is written against the IMPERSONATOR, because the question
        // being answered later is "who went into this account", not "what did
        // this account do to itself".
        $this->audit->log(
            userId: $originalUserId,
            action: 'user.impersonation.start',
            entityType: 'User',
            entityId: $targetUserId,
            newValues: [
                'target_email' => $user['email'] ?? null,
                'impersonator_role' => $originalUser['role'] ?? null,
                'expires_at' => date('Y-m-d H:i:s', $_SESSION['impersonating_until']),
            ],
            ipAddress: $_SERVER['REMOTE_ADDR'] ?? null,
            userAgent: $_SERVER['HTTP_USER_AGENT'] ?? null,
        );

        return $user;
    }

    /**
     * Has the current impersonation session outlived its window?
     *
     * Returns false when nothing is being impersonated, so callers can ask
     * unconditionally.
     */
    public function isImpersonationExpired(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (($_SESSION['impersonating_from'] ?? null) === null) {
            return false;
        }

        $until = $_SESSION['impersonating_until'] ?? null;

        // A session started before this expiry existed has no stamp. Treat it
        // as expired rather than as permanent — failing closed is the only
        // safe direction for a credential.
        return !\is_int($until) || $until < time();
    }

    /**
     * End impersonation, returning to original admin session.
     */
    public function stopImpersonation(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $originalId = $_SESSION['impersonating_from'] ?? null;
        $originalSessionId = $_SESSION['impersonating_session'] ?? null;

        if ($originalId === null) {
            return;
        }

        $targetId = (int) ($_SESSION['user_id'] ?? 0);

        // Override-password login (no original admin) — just clear flag, caller should logout
        if ($originalId === 0) {
            $this->clearImpersonationFlags();
            $this->logger->info('Override impersonation ended');

            return;
        }

        // Normal admin/agency impersonation — restore original session
        $_SESSION['user_id'] = $originalId;
        if ($originalSessionId !== null) {
            $_SESSION['session_id'] = $originalSessionId;
        }
        $this->clearImpersonationFlags();

        $this->logger->info('Impersonation ended', ['admin_id' => $originalId]);

        // The closing bracket of the pair. Without it the audit trail shows
        // people entering accounts and never leaving, which makes it
        // impossible to say how long anybody actually had access.
        $this->audit->log(
            userId: $originalId,
            action: 'user.impersonation.stop',
            entityType: 'User',
            entityId: $targetId,
            ipAddress: $_SERVER['REMOTE_ADDR'] ?? null,
            userAgent: $_SERVER['HTTP_USER_AGENT'] ?? null,
        );
    }

    private function clearImpersonationFlags(): void
    {
        unset(
            $_SESSION['impersonating_from'],
            $_SESSION['impersonating_session'],
            $_SESSION['impersonating_role'],
            $_SESSION['impersonating_until'],
            $_SESSION['impersonating_return_url'],
        );
    }

    /**
     * Accept a return path only if it is unmistakably a page on this site.
     *
     * The value reaches us from a Referer header, which the browser supplies
     * and an attacker can set. Storing it unchecked turns "return where you
     * came from" into an open redirect that fires after an operator has just
     * finished a privileged action. So: must start with a single `/`, must not
     * start with `//` or `/\` (protocol-relative URLs pointing off-site), and
     * must carry no scheme.
     */
    private function safeReturnPath(?string $path): ?string
    {
        if (!\is_string($path) || $path === '') {
            return null;
        }

        if ($path[0] !== '/' || str_starts_with($path, '//') || str_starts_with($path, '/\\')) {
            return null;
        }

        if (preg_match('~^/[^/]*:~', $path) === 1) {
            return null;
        }

        // Never bounce them back into the account they were impersonating from
        // its own URL space, and never into a loop through the trigger route.
        if (str_contains($path, '/impersonate') || str_contains($path, '/stop-impersonation')) {
            return null;
        }

        return mb_substr($path, 0, 255);
    }

    /**
     * @return array<string, string>
     */
    private function validateUser(array $data): array
    {
        $errors = [];

        if (!isset($data['email']) || $data['email'] === '') {
            $errors['email'] = 'Email is required.';
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email address.';
        }

        if (!isset($data['password']) || \strlen($data['password']) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        }

        return $errors;
    }
}
