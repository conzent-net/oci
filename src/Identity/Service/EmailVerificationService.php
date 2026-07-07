<?php

declare(strict_types=1);

namespace OCI\Identity\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use OCI\Identity\Exception\EndpointrException;
use OCI\Identity\Repository\UserRepositoryInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment as TwigEnvironment;

/**
 * Email verification — daily batch sweep + signup-time 6-digit code flow.
 */
final class EmailVerificationService
{
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_VALID = 'VALID';
    public const STATUS_INVALID = 'INVALID';

    private const SESSION_KEY = 'register_verify';
    private const CODE_TTL_SECONDS = 600;
    private const MAX_ATTEMPTS = 5;
    private const RESEND_COOLDOWN_SECONDS = 30;

    public function __construct(
        private readonly Connection $db,
        private readonly EndpointrClient $endpointr,
        private readonly UserRepositoryInterface $userRepo,
        private readonly UserService $userService,
        private readonly MailerService $mailer,
        private readonly TwigEnvironment $twig,
        private readonly LoggerInterface $logger,
    ) {}

    // ────────────────────────────────────────────────────────────
    // Daily batch
    // ────────────────────────────────────────────────────────────

    /**
     * Process up to $batchSize PENDING users. Returns per-status counts.
     *
     * @return array{processed: int, valid: int, invalid: int, pending: int, errors: int}
     */
    public function runBatch(int $batchSize, int $delayMs, bool $verbose = false): array
    {
        $stats = ['processed' => 0, 'valid' => 0, 'invalid' => 0, 'pending' => 0, 'errors' => 0];

        $users = $this->db->fetchAllAssociative(
            "SELECT id, email FROM oci_users
             WHERE email_verified = 'PENDING'
               AND is_active = 1
               AND deleted_at IS NULL
             ORDER BY id
             LIMIT ?",
            [$batchSize],
            [ParameterType::INTEGER],
        );

        foreach ($users as $i => $user) {
            $stats['processed']++;
            $id = (int) $user['id'];
            $email = (string) $user['email'];

            try {
                $result = $this->endpointr->verifyEmail($email);
                $status = (string) ($result['status'] ?? '');
                $mapped = $this->mapStatus($status);

                if ($mapped === self::STATUS_VALID) {
                    $this->db->executeStatement(
                        "UPDATE oci_users SET email_verified = 'VALID' WHERE id = ?",
                        [$id],
                    );
                    $stats['valid']++;
                    if ($verbose) {
                        echo "  #{$id} {$email} → VALID ({$status})\n";
                    }
                } elseif ($mapped === self::STATUS_INVALID) {
                    $this->db->executeStatement(
                        "UPDATE oci_users SET email_verified = 'INVALID', is_active = 0 WHERE id = ?",
                        [$id],
                    );
                    $this->db->executeStatement(
                        'DELETE FROM oci_user_sessions WHERE user_id = ?',
                        [$id],
                    );
                    $this->logger->warning('Email verification disabled account', [
                        'user_id' => $id,
                        'email' => $email,
                        'reason' => $status,
                    ]);
                    $stats['invalid']++;
                    if ($verbose) {
                        echo "  #{$id} {$email} → INVALID ({$status}) — account disabled\n";
                    }
                } else {
                    $stats['pending']++;
                    if ($verbose) {
                        echo "  #{$id} {$email} → PENDING ({$status})\n";
                    }
                }
            } catch (EndpointrException $e) {
                $stats['errors']++;
                $this->logger->error('Email verification transport error', [
                    'user_id' => $id,
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
                if ($verbose) {
                    echo "  #{$id} {$email} → ERROR: {$e->getMessage()}\n";
                }
            }

            if ($delayMs > 0 && $i < \count($users) - 1) {
                usleep($delayMs * 1000);
            }
        }

        return $stats;
    }

    private function mapStatus(string $status): ?string
    {
        return match (strtolower($status)) {
            'safe', 'valid' => self::STATUS_VALID,
            'invalid', 'disposable', 'spamtrap', 'disabled' => self::STATUS_INVALID,
            default => null,
        };
    }

    // ────────────────────────────────────────────────────────────
    // Signup-time code flow
    // ────────────────────────────────────────────────────────────

    /**
     * Validate the signup form, stash a hashed code in the session, send the email.
     *
     * @param array{email: string, first_name: string, last_name: string, password: string, password_confirm: string} $formData
     * @return array{success: bool, errors?: array<string, string>}
     */
    public function startSignup(array $formData): array
    {
        $this->ensureSession();

        $errors = $this->validateForm($formData);
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        if ($this->userRepo->findByEmail($formData['email']) !== null) {
            return ['success' => false, 'errors' => ['email' => 'Email is already in use.']];
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $_SESSION[self::SESSION_KEY] = [
            'email' => $formData['email'],
            'first_name' => $formData['first_name'],
            'last_name' => $formData['last_name'],
            'password_hash' => password_hash($formData['password'], PASSWORD_BCRYPT),
            'code_hash' => password_hash($code, PASSWORD_BCRYPT),
            'expires_at' => time() + self::CODE_TTL_SECONDS,
            'attempts' => 0,
            'last_sent_at' => time(),
        ];

        $this->sendCodeEmail($formData['email'], $formData['first_name'], $code);

        return ['success' => true];
    }

    /**
     * Verify the submitted code. On success creates the user with email_verified='VALID'.
     *
     * @return array{success: bool, user_id?: int, error?: string, attempts_left?: int, locked?: bool, expired?: bool}
     */
    public function verifyCode(string $code): array
    {
        $this->ensureSession();
        $stash = $_SESSION[self::SESSION_KEY] ?? null;

        if (!\is_array($stash)) {
            return ['success' => false, 'expired' => true, 'error' => 'Verification session not found. Please start over.'];
        }

        if (time() > (int) $stash['expires_at']) {
            unset($_SESSION[self::SESSION_KEY]);
            return ['success' => false, 'expired' => true, 'error' => 'Verification code has expired. Please start over.'];
        }

        if ((int) $stash['attempts'] >= self::MAX_ATTEMPTS) {
            unset($_SESSION[self::SESSION_KEY]);
            return ['success' => false, 'locked' => true, 'error' => 'Too many wrong attempts. Please start over.'];
        }

        if (!password_verify($code, (string) $stash['code_hash'])) {
            $_SESSION[self::SESSION_KEY]['attempts']++;
            $attemptsLeft = self::MAX_ATTEMPTS - (int) $_SESSION[self::SESSION_KEY]['attempts'];

            if ($attemptsLeft <= 0) {
                unset($_SESSION[self::SESSION_KEY]);
                return ['success' => false, 'locked' => true, 'error' => 'Too many wrong attempts. Please start over.'];
            }

            return [
                'success' => false,
                'attempts_left' => $attemptsLeft,
                'error' => "Incorrect code. {$attemptsLeft} attempt(s) remaining.",
            ];
        }

        // Create user. UserRepository::create() will see the $2 prefix and skip re-hashing.
        $result = $this->userService->createUser([
            'email' => $stash['email'],
            'first_name' => $stash['first_name'],
            'last_name' => $stash['last_name'],
            'password' => $stash['password_hash'],
            'role' => 'customer',
            'is_active' => 1,
        ]);

        if (!$result['success']) {
            // Race condition: someone registered the same email between start and verify.
            // Keep the stash cleared and surface the error.
            unset($_SESSION[self::SESSION_KEY]);
            $first = $result['errors'][array_key_first($result['errors'])] ?? 'Could not create account.';
            return ['success' => false, 'error' => $first];
        }

        $userId = (int) $result['user_id'];

        $this->db->executeStatement(
            "UPDATE oci_users SET email_verified = 'VALID' WHERE id = ?",
            [$userId],
        );

        unset($_SESSION[self::SESSION_KEY]);

        $this->logger->info('Signup verified by code', ['user_id' => $userId, 'email' => $stash['email']]);

        return ['success' => true, 'user_id' => $userId];
    }

    /**
     * Regenerate the code and resend the email. Refuses if cooldown active.
     *
     * @return array{success: bool, error?: string, retry_after?: int}
     */
    public function resendCode(): array
    {
        $this->ensureSession();
        $stash = $_SESSION[self::SESSION_KEY] ?? null;

        if (!\is_array($stash)) {
            return ['success' => false, 'error' => 'Verification session not found. Please start over.'];
        }

        $elapsed = time() - (int) $stash['last_sent_at'];
        if ($elapsed < self::RESEND_COOLDOWN_SECONDS) {
            return [
                'success' => false,
                'retry_after' => self::RESEND_COOLDOWN_SECONDS - $elapsed,
                'error' => 'Please wait before requesting another code.',
            ];
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $_SESSION[self::SESSION_KEY]['code_hash'] = password_hash($code, PASSWORD_BCRYPT);
        $_SESSION[self::SESSION_KEY]['expires_at'] = time() + self::CODE_TTL_SECONDS;
        $_SESSION[self::SESSION_KEY]['attempts'] = 0;
        $_SESSION[self::SESSION_KEY]['last_sent_at'] = time();

        $this->sendCodeEmail(
            (string) $stash['email'],
            (string) $stash['first_name'],
            $code,
        );

        return ['success' => true];
    }

    public function cancelSignup(): void
    {
        $this->ensureSession();
        unset($_SESSION[self::SESSION_KEY]);
    }

    public function hasPendingSignup(): bool
    {
        $this->ensureSession();
        $stash = $_SESSION[self::SESSION_KEY] ?? null;
        return \is_array($stash) && time() <= (int) $stash['expires_at'];
    }

    /**
     * @return array{email: string, first_name: string}|null
     */
    public function getPendingSignupDisplay(): ?array
    {
        $this->ensureSession();
        $stash = $_SESSION[self::SESSION_KEY] ?? null;
        if (!\is_array($stash)) {
            return null;
        }
        return [
            'email' => (string) $stash['email'],
            'first_name' => (string) $stash['first_name'],
        ];
    }

    // ────────────────────────────────────────────────────────────
    // Internals
    // ────────────────────────────────────────────────────────────

    private function sendCodeEmail(string $email, string $firstName, string $code): void
    {
        $html = $this->twig->render('emails/verification-code.html.twig', [
            'first_name' => $firstName !== '' ? $firstName : 'there',
            'code' => $code,
            'expiry_minutes' => (int) (self::CODE_TTL_SECONDS / 60),
        ]);

        $this->mailer->send($email, 'Your Conzent verification code', $html);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function validateForm(array $data): array
    {
        $errors = [];

        $email = (string) ($data['email'] ?? '');
        if ($email === '') {
            $errors['email'] = 'Email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email address.';
        }

        $password = (string) ($data['password'] ?? '');
        if (\strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        }

        $confirm = (string) ($data['password_confirm'] ?? '');
        if ($password !== $confirm) {
            $errors['password_confirm'] = 'Passwords do not match.';
        }

        return $errors;
    }

    private function ensureSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }
}
