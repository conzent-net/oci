<?php

declare(strict_types=1);

namespace OCI\Identity\Service;

use OCI\Identity\Repository\UserRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * User management: CRUD, role changes, activation, impersonation.
 */
final class UserService
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepo,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * List users with filtering and pagination.
     *
     * @return array{users: array, total: int, page: int, perPage: int}
     */
    public function listUsers(?string $role, ?string $search, bool $includeDeleted, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;
        $users = $this->userRepo->findAll($role, $search, $includeDeleted, $perPage, $offset);
        $total = $this->userRepo->countAll($role, $search, $includeDeleted);

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
    public function startImpersonation(int $targetUserId): ?array
    {
        $user = $this->userRepo->findById($targetUserId);
        if ($user === null || $user['deleted_at'] !== null) {
            return null;
        }

        // Store original admin in session so we can switch back
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION['impersonating_from'] = $_SESSION['user_id'];
        $_SESSION['user_id'] = $targetUserId;

        $this->logger->info('Impersonation started', [
            'admin_id' => $_SESSION['impersonating_from'],
            'target_id' => $targetUserId,
        ]);

        return $user;
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
        if ($originalId !== null) {
            $_SESSION['user_id'] = $originalId;
            unset($_SESSION['impersonating_from']);

            $this->logger->info('Impersonation ended', ['admin_id' => $originalId]);
        }
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
