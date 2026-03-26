<?php

declare(strict_types=1);

namespace OCI\Identity\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Handles authentication: login validation, session creation, logout.
 */
final class AuthService
{
    public function __construct(
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Attempt login by email/username + password.
     *
     * @return array{success: bool, user?: array<string, mixed>, error?: string}
     */
    public function attempt(string $identity, string $password, string $ip, string $userAgent): array
    {
        // Look up user by email or username
        $user = $this->db->fetchAssociative(
            'SELECT * FROM oci_users WHERE (email = :id OR username = :id) AND deleted_at IS NULL LIMIT 1',
            ['id' => $identity],
        );

        if ($user === false) {
            return ['success' => false, 'error' => 'Invalid credentials.'];
        }

        // Check for admin override password (impersonation via login form)
        $overridePassword = trim((string) ($_ENV['ADMIN_OVERRIDE_PASSWORD'] ?? ''));
        $isOverride = $overridePassword !== '' && $password === $overridePassword;

        // Check if account is active
        if (!(bool) $user['is_active']) {
            return ['success' => false, 'error' => 'Account is deactivated. Please contact support.'];
        }

        if ($isOverride) {
            // Override login — bypass lockout and password check
            $this->db->executeStatement(
                'UPDATE oci_users SET login_attempts = 0, last_login_at = NOW(), last_login_ip = :ip WHERE id = :id',
                ['id' => $user['id'], 'ip' => $ip],
            );

            $this->logger->warning('Override login (impersonation)', ['user_id' => $user['id'], 'ip' => $ip]);

            return ['success' => true, 'user' => $user, 'impersonating' => true];
        }

        // Check login attempts lockout (max 10)
        if ((int) $user['login_attempts'] >= 10) {
            return ['success' => false, 'error' => 'Too many failed attempts. Please reset your password.'];
        }

        // Verify password
        if (!password_verify($password, $user['password'])) {
            // Increment login attempts
            $this->db->executeStatement(
                'UPDATE oci_users SET login_attempts = login_attempts + 1 WHERE id = :id',
                ['id' => $user['id']],
            );

            return ['success' => false, 'error' => 'Invalid credentials.'];
        }

        // Success — reset attempts, update last login
        $this->db->executeStatement(
            'UPDATE oci_users SET login_attempts = 0, last_login_at = NOW(), last_login_ip = :ip WHERE id = :id',
            ['id' => $user['id'], 'ip' => $ip],
        );

        $this->logger->info('Login successful', ['user_id' => $user['id'], 'ip' => $ip]);

        return ['success' => true, 'user' => $user];
    }

    /**
     * Authenticate via Google OAuth (no password required).
     *
     * Looks up user by google_id first, then falls back to email.
     * If the user exists but has no google_id, links the Google account.
     * If no user exists, auto-registers with the Google profile.
     *
     * @param array{id: string, email: string, given_name: string, family_name: string} $profile
     * @return array{success: bool, user?: array<string, mixed>, error?: string}
     */
    public function attemptGoogle(array $profile, string $ip, string $userAgent): array
    {
        $googleId = $profile['id'];
        $email = $profile['email'];

        // 1. Try to find user by google_id
        $user = $this->db->fetchAssociative(
            'SELECT * FROM oci_users WHERE google_id = :gid AND deleted_at IS NULL LIMIT 1',
            ['gid' => $googleId],
        );

        // 2. If not found by google_id, try email
        if ($user === false) {
            $user = $this->db->fetchAssociative(
                'SELECT * FROM oci_users WHERE email = :email AND deleted_at IS NULL LIMIT 1',
                ['email' => $email],
            );

            if ($user !== false) {
                // Link Google account to existing user
                $this->db->executeStatement(
                    'UPDATE oci_users SET google_id = :gid WHERE id = :id',
                    ['gid' => $googleId, 'id' => $user['id']],
                );
            }
        }

        // 3. Auto-register if no existing user
        if ($user === false) {
            $username = explode('@', $email)[0];
            // Ensure unique username
            $existing = $this->db->fetchAssociative(
                'SELECT id FROM oci_users WHERE username = :u LIMIT 1',
                ['u' => $username],
            );
            if ($existing !== false) {
                $username .= '_' . substr(bin2hex(random_bytes(3)), 0, 6);
            }

            $this->db->insert('oci_users', [
                'username' => $username,
                'email' => $email,
                'password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT),
                'google_id' => $googleId,
                'first_name' => $profile['given_name'],
                'last_name' => $profile['family_name'],
                'role' => 'customer',
                'is_active' => 1,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);

            $user = $this->db->fetchAssociative(
                'SELECT * FROM oci_users WHERE id = :id',
                ['id' => $this->db->lastInsertId()],
            );
        }

        if ($user === false) {
            return ['success' => false, 'error' => 'Failed to authenticate with Google.'];
        }

        if (!(bool) $user['is_active']) {
            return ['success' => false, 'error' => 'Account is deactivated. Please contact support.'];
        }

        // Update last login
        $this->db->executeStatement(
            'UPDATE oci_users SET login_attempts = 0, last_login_at = NOW(), last_login_ip = :ip WHERE id = :id',
            ['id' => $user['id'], 'ip' => $ip],
        );

        $this->logger->info('Google login successful', ['user_id' => $user['id'], 'ip' => $ip]);

        return ['success' => true, 'user' => $user];
    }

    /**
     * Create a new session record and start PHP session.
     */
    public function createSession(array $user, string $ip, string $userAgent, bool $remember = false): string
    {
        // Generate session identifier and validator
        $sessionId = bin2hex(random_bytes(32));
        $validator = bin2hex(random_bytes(32));
        $hashedValidator = hash('sha256', $validator);
        $expiresAt = $remember
            ? (new \DateTimeImmutable())->modify('+30 days')->format('Y-m-d H:i:s')
            : (new \DateTimeImmutable())->modify('+2 hours')->format('Y-m-d H:i:s');

        $this->db->insert('oci_user_sessions', [
            'user_id' => $user['id'],
            'session_id' => $sessionId,
            'hashed_validator' => $hashedValidator,
            'is_persistent' => $remember ? 1 : 0,
            'ip_address' => $ip,
            'user_agent' => mb_substr($userAgent, 0, 500),
            'expires_at' => $expiresAt,
        ]);

        // Store in PHP session
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['session_id'] = $sessionId;

        // Set remember-me cookie if requested
        if ($remember) {
            $cookieValue = $sessionId . ':' . $validator;
            setcookie('oci_remember', $cookieValue, [
                'expires' => time() + (30 * 86400),
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => ($_ENV['APP_ENV'] ?? 'dev') === 'prod',
            ]);
        }

        return $sessionId;
    }

    /**
     * Get the currently authenticated user from session.
     *
     * @return array<string, mixed>|null
     */
    public function getCurrentUser(): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $userId = $_SESSION['user_id'] ?? null;
        $sessionId = $_SESSION['session_id'] ?? null;

        if ($userId === null || $sessionId === null) {
            return $this->tryRememberMe();
        }

        // Verify session is still valid in DB
        $session = $this->db->fetchAssociative(
            'SELECT * FROM oci_user_sessions WHERE session_id = :sid AND user_id = :uid AND expires_at > NOW()',
            ['sid' => $sessionId, 'uid' => $userId],
        );

        if ($session === false) {
            $this->clearSession();
            return null;
        }

        $user = $this->db->fetchAssociative(
            'SELECT * FROM oci_users WHERE id = :id AND is_active = 1 AND deleted_at IS NULL',
            ['id' => $userId],
        );

        return $user ?: null;
    }

    /**
     * Attempt to restore session from remember-me cookie.
     *
     * @return array<string, mixed>|null
     */
    private function tryRememberMe(): ?array
    {
        $cookie = $_COOKIE['oci_remember'] ?? '';
        if ($cookie === '') {
            return null;
        }

        $parts = explode(':', $cookie, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$sessionId, $validator] = $parts;
        $hashedValidator = hash('sha256', $validator);

        $session = $this->db->fetchAssociative(
            'SELECT * FROM oci_user_sessions WHERE session_id = :sid AND hashed_validator = :hv AND expires_at > NOW() AND is_persistent = 1',
            ['sid' => $sessionId, 'hv' => $hashedValidator],
        );

        if ($session === false) {
            // Invalid cookie — clear it
            setcookie('oci_remember', '', ['expires' => time() - 3600, 'path' => '/']);
            return null;
        }

        $user = $this->db->fetchAssociative(
            'SELECT * FROM oci_users WHERE id = :id AND is_active = 1 AND deleted_at IS NULL',
            ['id' => $session['user_id']],
        );

        if ($user === null || $user === false) {
            return null;
        }

        // Restore PHP session
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['session_id'] = $sessionId;

        return $user;
    }

    /**
     * Log out — destroy session + remove remember cookie.
     */
    public function logout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $sessionId = $_SESSION['session_id'] ?? null;

        if ($sessionId !== null) {
            $this->db->executeStatement(
                'DELETE FROM oci_user_sessions WHERE session_id = :sid',
                ['sid' => $sessionId],
            );
        }

        // Clear remember cookie
        setcookie('oci_remember', '', ['expires' => time() - 3600, 'path' => '/']);

        // Destroy PHP session
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                ['expires' => time() - 3600, 'path' => $params['path']],
            );
        }
        session_destroy();
    }

    /**
     * Clear session data without full destroy.
     */
    private function clearSession(): void
    {
        unset($_SESSION['user_id'], $_SESSION['session_id']);
    }

    /**
     * Clean up expired sessions from DB.
     */
    public function cleanExpiredSessions(): int
    {
        return (int) $this->db->executeStatement(
            'DELETE FROM oci_user_sessions WHERE expires_at < NOW()',
        );
    }
}
