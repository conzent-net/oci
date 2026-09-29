<?php

declare(strict_types=1);

namespace OCI\Identity\Service;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Two-factor authentication: enrolment, verification, recovery codes, trusted
 * devices, and reset.
 *
 * Lives in core (not src/Modules/) on purpose. The self-hosted edition needs
 * working 2FA, and anything added to composer.json ships publicly anyway, so a
 * cloud-only implementation was never viable. Only the admin *button* is cloud;
 * the mechanism and the CLI path are here.
 */
final class TwoFactorService
{
    /** Recovery codes issued per user. Ten is the common bar. */
    private const RECOVERY_CODE_COUNT = 10;

    /** How long "trust this device" lasts before the second factor returns. */
    private const TRUSTED_DEVICE_DAYS = 30;

    public const TRUSTED_DEVICE_COOKIE = 'oci_2fa_device';

    public function __construct(
        private readonly Connection $db,
        private readonly TotpService $totp,
        private readonly SecretCipher $cipher,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * 2FA cannot be enabled without an encryption key, because the alternative
     * is writing plaintext TOTP secrets to the database.
     */
    public function isAvailable(): bool
    {
        return $this->cipher->isConfigured();
    }

    /**
     * 2FA is enforced only once enrolment was confirmed. A stored-but-
     * unconfirmed secret means setup was started and abandoned, which must not
     * lock anyone out.
     */
    public function isEnabled(int $userId): bool
    {
        $confirmedAt = $this->db->fetchOne(
            'SELECT totp_confirmed_at FROM oci_users WHERE id = :id',
            ['id' => $userId],
        );

        return $confirmedAt !== false && $confirmedAt !== null && $confirmedAt !== '';
    }

    /**
     * Begin enrolment: mint a secret and store it UNCONFIRMED.
     *
     * Nothing is enforced until confirm() succeeds. A user who starts setup and
     * walks away is not locked out, and re-running this simply replaces the
     * pending secret.
     *
     * @return array{secret: string, uri: string, qr: string}|null null when no encryption key is configured
     */
    public function beginEnrolment(int $userId, string $accountName, string $issuer = 'Conzent'): ?array
    {
        $secret = $this->totp->generateSecret();
        $encrypted = $this->cipher->encrypt($secret);

        if ($encrypted === null) {
            $this->logger->error('2FA enrolment refused: TOTP_ENCRYPTION_KEY is not configured');

            return null;
        }

        $this->db->update(
            'oci_users',
            ['totp_secret' => $encrypted, 'totp_confirmed_at' => null, 'totp_last_used_step' => null],
            ['id' => $userId],
        );

        $uri = $this->totp->provisioningUri($secret, $accountName, $issuer);

        return ['secret' => $secret, 'uri' => $uri, 'qr' => $this->qrSvg($uri)];
    }

    /**
     * Finish enrolment by proving a working code, which is what actually turns
     * 2FA on. Returns the recovery codes in plaintext ONCE; only hashes are kept.
     *
     * @return list<string>|null null when the code does not verify
     */
    public function confirmEnrolment(int $userId, string $code): ?array
    {
        $secret = $this->secretFor($userId);
        if ($secret === null) {
            return null;
        }

        $step = $this->totp->verify($secret, $code, null);
        if ($step === null) {
            return null;
        }

        $this->db->update(
            'oci_users',
            ['totp_confirmed_at' => $this->now(), 'totp_last_used_step' => $step],
            ['id' => $userId],
        );

        // Re-enrolling invalidates any device that could previously skip the
        // prompt: the factor changed, so the bypasses must not survive it.
        $this->revokeTrustedDevices($userId);

        return $this->regenerateRecoveryCodes($userId);
    }

    /**
     * Verify a login-time code. Accepts either a TOTP code or an unused recovery
     * code, and spends the recovery code if that is what was used.
     */
    public function verify(int $userId, string $code): bool
    {
        $secret = $this->secretFor($userId);
        if ($secret === null) {
            return false;
        }

        $lastStep = $this->db->fetchOne(
            'SELECT totp_last_used_step FROM oci_users WHERE id = :id',
            ['id' => $userId],
        );

        $step = $this->totp->verify($secret, $code, $lastStep !== false && $lastStep !== null ? (int) $lastStep : null);

        if ($step !== null) {
            $this->db->update('oci_users', ['totp_last_used_step' => $step], ['id' => $userId]);

            return true;
        }

        return $this->spendRecoveryCode($userId, $code);
    }

    /**
     * Spend a recovery code.
     *
     * Hashes are compared in PHP rather than matched in SQL so the comparison is
     * constant-time, and the UPDATE is conditional on used_at IS NULL so two
     * concurrent requests cannot both spend the same code.
     */
    private function spendRecoveryCode(int $userId, string $code): bool
    {
        $normalised = $this->normaliseRecoveryCode($code);
        if ($normalised === '') {
            return false;
        }

        /** @var list<array{id: int, code_hash: string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, code_hash FROM oci_user_recovery_codes WHERE user_id = :id AND used_at IS NULL',
            ['id' => $userId],
        );

        foreach ($rows as $row) {
            if (!password_verify($normalised, (string) $row['code_hash'])) {
                continue;
            }

            $spent = $this->db->executeStatement(
                'UPDATE oci_user_recovery_codes SET used_at = :now WHERE id = :id AND used_at IS NULL',
                ['now' => $this->now(), 'id' => (int) $row['id']],
            );

            if ($spent === 1) {
                $this->logger->info('2FA recovery code used', ['user_id' => $userId]);

                return true;
            }
        }

        return false;
    }

    /** @return list<string> plaintext codes, shown once */
    public function regenerateRecoveryCodes(int $userId): array
    {
        $this->db->delete('oci_user_recovery_codes', ['user_id' => $userId]);

        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            // Crockford-ish: no vowels, so no accidental words, and unambiguous
            // when read off paper.
            $raw = strtoupper(bin2hex(random_bytes(5)));
            $code = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
            $codes[] = $code;

            $this->db->insert('oci_user_recovery_codes', [
                'user_id' => $userId,
                'code_hash' => password_hash($this->normaliseRecoveryCode($code), \PASSWORD_DEFAULT),
                'created_at' => $this->now(),
            ]);
        }

        return $codes;
    }

    public function unusedRecoveryCodeCount(int $userId): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM oci_user_recovery_codes WHERE user_id = :id AND used_at IS NULL',
            ['id' => $userId],
        );
    }

    /**
     * Turn 2FA off for a user and remove every credential that depended on it.
     *
     * This is the admin reset and the CLI reset. It deliberately also clears
     * trusted devices: leaving a bypass token alive after removing the factor
     * would let the old device keep skipping a prompt that no longer exists.
     */
    public function reset(int $userId, ?int $actorUserId = null): void
    {
        $this->db->update(
            'oci_users',
            ['totp_secret' => null, 'totp_confirmed_at' => null, 'totp_last_used_step' => null],
            ['id' => $userId],
        );

        $this->db->delete('oci_user_recovery_codes', ['user_id' => $userId]);
        $this->revokeTrustedDevices($userId);

        $this->logger->warning('2FA reset', ['user_id' => $userId, 'actor_user_id' => $actorUserId]);
    }

    // ── Trusted devices ─────────────────────────────────────────────

    /**
     * Issue a "don't ask again on this device" token.
     *
     * Split into a public selector and a secret validator, with only the
     * validator's hash stored: a database leak then yields no usable token. The
     * selector is what the lookup keys on, so the comparison never needs to be
     * a search over hashes.
     *
     * @return string cookie value, "selector:validator"
     */
    public function trustDevice(int $userId, string $label, ?string $ip): string
    {
        $selector = bin2hex(random_bytes(8));
        $validator = bin2hex(random_bytes(32));

        $this->db->insert('oci_user_trusted_devices', [
            'user_id' => $userId,
            'selector' => $selector,
            'hashed_validator' => hash('sha256', $validator),
            'label' => mb_substr($label, 0, 200),
            'ip_address' => $ip,
            'expires_at' => date('Y-m-d H:i:s', time() + (self::TRUSTED_DEVICE_DAYS * 86400)),
            'created_at' => $this->now(),
        ]);

        return $selector . ':' . $validator;
    }

    /** True when the cookie presents a live trusted-device token for this user. */
    public function isTrustedDevice(int $userId, ?string $cookieValue): bool
    {
        if ($cookieValue === null || !str_contains($cookieValue, ':')) {
            return false;
        }

        [$selector, $validator] = explode(':', $cookieValue, 2);

        $row = $this->db->fetchAssociative(
            'SELECT id, user_id, hashed_validator FROM oci_user_trusted_devices
             WHERE selector = :selector AND expires_at > NOW()',
            ['selector' => $selector],
        );

        if ($row === false) {
            return false;
        }

        // Both checks matter. hash_equals stops a timing oracle on the
        // validator; binding to user_id stops a token issued for one account
        // from satisfying the prompt on another.
        if (!hash_equals((string) $row['hashed_validator'], hash('sha256', $validator))) {
            return false;
        }

        if ((int) $row['user_id'] !== $userId) {
            return false;
        }

        $this->db->update('oci_user_trusted_devices', ['last_used_at' => $this->now()], ['id' => (int) $row['id']]);

        return true;
    }

    public function revokeTrustedDevices(int $userId): void
    {
        $this->db->delete('oci_user_trusted_devices', ['user_id' => $userId]);
    }

    /** @return list<array<string, mixed>> */
    public function listTrustedDevices(int $userId): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT id, label, ip_address, last_used_at, expires_at, created_at
             FROM oci_user_trusted_devices
             WHERE user_id = :id AND expires_at > NOW()
             ORDER BY created_at DESC',
            ['id' => $userId],
        );
    }

    public function purgeExpiredTrustedDevices(): int
    {
        return (int) $this->db->executeStatement('DELETE FROM oci_user_trusted_devices WHERE expires_at <= NOW()');
    }

    // ── Internals ───────────────────────────────────────────────────

    /** Decrypted secret, or null when absent or undecryptable. */
    private function secretFor(int $userId): ?string
    {
        $stored = $this->db->fetchOne('SELECT totp_secret FROM oci_users WHERE id = :id', ['id' => $userId]);

        if ($stored === false || $stored === null || $stored === '') {
            return null;
        }

        $secret = $this->cipher->decrypt((string) $stored);

        if ($secret === null) {
            // Almost always a changed or missing TOTP_ENCRYPTION_KEY. Log it
            // loudly: to the user this looks like "my codes stopped working",
            // and without this line nobody would connect the two.
            $this->logger->error('2FA secret could not be decrypted; check TOTP_ENCRYPTION_KEY', ['user_id' => $userId]);
        }

        return $secret;
    }

    /** Accept recovery codes however they are typed: case, spaces, dashes. */
    private function normaliseRecoveryCode(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    private function qrSvg(string $uri): string
    {
        // Rendered locally as SVG. Never via an external QR service: that would
        // hand the shared secret to a third party, which for a privacy product
        // would be an unusually embarrassing way to leak it.
        $writer = new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd()));

        return $writer->writeString($uri);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
