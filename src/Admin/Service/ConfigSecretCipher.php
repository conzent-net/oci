<?php

declare(strict_types=1);

namespace OCI\Admin\Service;

/**
 * Encrypts destination credentials before they go into oci_configuration.
 *
 * sodium_crypto_secretbox, bundled with PHP 8.3 — no new dependency. The key is
 * derived from APP_SECRET, which has a load-bearing consequence worth stating
 * plainly rather than discovering later:
 *
 * The ciphertext lives in the database and therefore travels inside every
 * archive. APP_SECRET lives in .env, which is excluded from archives by
 * default. So **restoring onto a fresh host will not decrypt these
 * credentials** — the rows arrive, the values do not.
 *
 * That is the correct trade, not an oversight. These credentials grant WRITE
 * access to the very storage holding the backup. If a stolen archive carried
 * usable keys to its own storage, an attacker with one old backup could delete
 * every other one. The UI treats "configured but undecryptable" as a normal
 * state prompting re-entry, because after a genuine disaster recovery it is
 * the expected experience.
 */
final class ConfigSecretCipher
{
    private const PREFIX = 'sb1:';

    public function isAvailable(): bool
    {
        return \function_exists('sodium_crypto_secretbox') && $this->secret() !== '';
    }

    public function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        if (!$this->isAvailable()) {
            throw new \RuntimeException(
                'Cannot store this credential: APP_SECRET is not set, so there is no key to encrypt it with. '
                . 'Refusing to write it in plain text.',
            );
        }

        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->key());

        return self::PREFIX . base64_encode($nonce . $cipher);
    }

    /**
     * Null means "there is a value here but this host cannot read it" — a state
     * the UI must show as re-enter-me rather than as an error, since it is what
     * a restore onto a new server always produces.
     */
    public function decrypt(string $stored): ?string
    {
        if ($stored === '') {
            return '';
        }

        if (!str_starts_with($stored, self::PREFIX) || !$this->isAvailable()) {
            return null;
        }

        $raw = base64_decode(substr($stored, \strlen(self::PREFIX)), true);
        if ($raw === false || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $nonce = substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key());

        return $plain === false ? null : $plain;
    }

    /** True when a stored value exists but this host holds the wrong key. */
    public function isUndecryptable(string $stored): bool
    {
        return $stored !== '' && $this->decrypt($stored) === null;
    }

    private function key(): string
    {
        // APP_SECRET is an arbitrary-length string; secretbox needs exactly 32
        // bytes, so it is hashed rather than truncated.
        return sodium_crypto_generichash($this->secret(), '', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    private function secret(): string
    {
        return trim((string) ($_ENV['APP_SECRET'] ?? ''));
    }
}
