<?php

declare(strict_types=1);

namespace OCI\Identity\Service;

/**
 * Authenticated symmetric encryption for secrets held at rest.
 *
 * AES-256-GCM via openssl, which is always available, so this adds no
 * extension requirement for self-hosted installs. The key comes from
 * TOTP_ENCRYPTION_KEY; any string is accepted and normalised to 32 bytes with
 * SHA-256.
 *
 * Ciphertext layout, base64 encoded: [12-byte IV][16-byte GCM tag][ciphertext].
 *
 * Deliberately duplicated rather than shared with the cloud ABTest TokenCipher:
 * that class lives under src/Modules/, which is stripped from the open-source
 * publish, and no published file may reference it. Two-factor authentication
 * has to work in the self-hosted edition.
 *
 * **This fails closed.** With no key configured, encrypt() returns null and the
 * caller refuses to enable 2FA rather than writing a plaintext TOTP secret to
 * the database. A silent downgrade here would mean one database leak defeats
 * every enrolled user's second factor at once, which is the exact outcome 2FA
 * exists to prevent.
 */
final class SecretCipher
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LEN = 12;
    private const TAG_LEN = 16;
    private const ENV_KEY = 'TOTP_ENCRYPTION_KEY';

    public function isConfigured(): bool
    {
        return $this->key() !== null;
    }

    /** Base64 ciphertext, or null when no key is configured. */
    public function encrypt(string $plaintext): ?string
    {
        $key = $this->key();
        if ($key === null) {
            return null;
        }

        $iv = random_bytes(self::IV_LEN);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            \OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LEN,
        );

        if ($ciphertext === false) {
            return null;
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Null on any failure: no key, malformed payload, or a failed authentication
     * tag. A rotated or lost key lands here, which surfaces to the user as "your
     * code is not accepted" rather than as a crash.
     */
    public function decrypt(string $encoded): ?string
    {
        $key = $this->key();
        if ($key === null) {
            return null;
        }

        $raw = base64_decode($encoded, true);
        if ($raw === false || \strlen($raw) <= self::IV_LEN + self::TAG_LEN) {
            return null;
        }

        $plaintext = openssl_decrypt(
            substr($raw, self::IV_LEN + self::TAG_LEN),
            self::CIPHER,
            $key,
            \OPENSSL_RAW_DATA,
            substr($raw, 0, self::IV_LEN),
            substr($raw, self::IV_LEN, self::TAG_LEN),
        );

        return $plaintext === false ? null : $plaintext;
    }

    private function key(): ?string
    {
        $configured = (string) ($_ENV[self::ENV_KEY] ?? getenv(self::ENV_KEY) ?: '');

        return $configured === '' ? null : hash('sha256', $configured, true);
    }
}
