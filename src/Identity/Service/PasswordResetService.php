<?php

declare(strict_types=1);

namespace OCI\Identity\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Twig\Environment as TwigEnvironment;

/**
 * Handles forgot-password + reset-password flows.
 *
 * Uses oci_password_resets table (token-based, time-limited).
 */
final class PasswordResetService
{
    private const TOKEN_EXPIRY_MINUTES = 60;

    public function __construct(
        private readonly Connection $db,
        private readonly MailerService $mailer,
        private readonly TwigEnvironment $twig,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Create a password reset token and send an email.
     *
     * Returns true even if user not found (to prevent enumeration).
     */
    public function sendResetLink(string $email): bool
    {
        $user = $this->db->fetchAssociative(
            'SELECT id, email, first_name, username FROM oci_users WHERE email = :email AND is_active = 1 AND deleted_at IS NULL',
            ['email' => $email],
        );

        if ($user === false) {
            // Don't reveal whether user exists
            $this->logger->info('Password reset requested for unknown email', ['email' => $email]);
            return true;
        }

        // Delete any existing tokens for this user
        $this->db->executeStatement(
            'DELETE FROM oci_password_resets WHERE user_id = :uid',
            ['uid' => $user['id']],
        );

        // Generate token
        $token = bin2hex(random_bytes(32));
        $hashedToken = hash('sha256', $token);
        $expiresAt = (new \DateTimeImmutable())
            ->modify('+' . self::TOKEN_EXPIRY_MINUTES . ' minutes')
            ->format('Y-m-d H:i:s');

        $this->db->insert('oci_password_resets', [
            'user_id' => $user['id'],
            'email' => $user['email'],
            'token' => $hashedToken,
            'expires_at' => $expiresAt,
        ]);

        // Build reset URL
        $baseUrl = rtrim($_ENV['APP_URL'] ?? 'http://localhost:8100', '/');
        $resetUrl = $baseUrl . '/reset-password?token=' . $token . '&email=' . urlencode($user['email']);

        // Render email
        $htmlBody = $this->twig->render('emails/password-reset.html.twig', [
            'user' => $user,
            'reset_url' => $resetUrl,
            'expiry_minutes' => self::TOKEN_EXPIRY_MINUTES,
        ]);

        $sent = $this->mailer->send(
            $user['email'],
            'Reset Your Password — Conzent',
            $htmlBody,
        );

        if ($sent) {
            $this->logger->info('Password reset email sent', ['user_id' => $user['id']]);
        }

        return $sent;
    }

    /**
     * Validate a reset token.
     *
     * @return array{valid: bool, user_id?: int, error?: string}
     */
    public function validateToken(string $email, string $token): array
    {
        $hashedToken = hash('sha256', $token);

        $reset = $this->db->fetchAssociative(
            'SELECT * FROM oci_password_resets WHERE email = :email AND token = :token AND expires_at > NOW()',
            ['email' => $email, 'token' => $hashedToken],
        );

        if ($reset === false) {
            return ['valid' => false, 'error' => 'Invalid or expired reset link. Please request a new one.'];
        }

        return ['valid' => true, 'user_id' => (int) $reset['user_id']];
    }

    /**
     * Reset the password using a valid token.
     */
    public function resetPassword(string $email, string $token, string $newPassword): bool
    {
        $validation = $this->validateToken($email, $token);

        if (!$validation['valid']) {
            return false;
        }

        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

        $this->db->executeStatement(
            'UPDATE oci_users SET password = :pw, login_attempts = 0, updated_at = NOW() WHERE id = :id',
            ['pw' => $hashedPassword, 'id' => $validation['user_id']],
        );

        // Delete all reset tokens for this user
        $this->db->executeStatement(
            'DELETE FROM oci_password_resets WHERE user_id = :uid',
            ['uid' => $validation['user_id']],
        );

        // Also destroy all existing sessions (force re-login)
        $this->db->executeStatement(
            'DELETE FROM oci_user_sessions WHERE user_id = :uid',
            ['uid' => $validation['user_id']],
        );

        $this->logger->info('Password reset completed', ['user_id' => $validation['user_id']]);

        return true;
    }

    /**
     * Clean up expired tokens.
     */
    public function cleanExpiredTokens(): int
    {
        return (int) $this->db->executeStatement(
            'DELETE FROM oci_password_resets WHERE expires_at < NOW()',
        );
    }
}
