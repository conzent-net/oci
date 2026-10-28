<?php

declare(strict_types=1);

namespace OCI\Admin\Service;

use Doctrine\DBAL\Connection;

/**
 * Reads and writes the offsite destination settings.
 *
 * One JSON blob per destination in oci_configuration (scope 'system'), with
 * the secret fields encrypted by ConfigSecretCipher before they are stored.
 *
 * Secrets are never returned to the browser. The UI receives a boolean saying
 * whether each one is set, and submitting a blank field leaves the stored value
 * alone — so editing a bucket name does not silently wipe the key.
 */
final class BackupDestinationConfig
{
    private const KEY_PREFIX = 'backup_dest_';

    /** Encrypted at rest and never sent to the browser. */
    private const SECRET_FIELDS = ['password', 'private_key', 'passphrase', 'access_key', 'secret_key'];

    private const SFTP_FIELDS = ['enabled', 'host', 'port', 'username', 'password', 'private_key', 'passphrase', 'path'];
    private const S3_FIELDS = ['enabled', 'endpoint', 'region', 'bucket', 'prefix', 'access_key', 'secret_key', 'path_style'];

    public function __construct(
        private readonly Connection $db,
        private readonly ConfigSecretCipher $cipher,
    ) {
    }

    /** @return array<string, string> raw stored values, secrets still encrypted */
    public function raw(string $destination): array
    {
        $stored = $this->db->fetchOne(
            "SELECT config_value FROM oci_configuration WHERE scope = 'system' AND scope_id IS NULL AND config_key = :k",
            ['k' => self::KEY_PREFIX . $destination],
        );

        if (!\is_string($stored) || $stored === '') {
            return [];
        }

        try {
            /** @var array<string, string> $decoded */
            $decoded = json_decode($stored, true, 8, JSON_THROW_ON_ERROR);

            return \is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            return [];
        }
    }

    /**
     * Safe to hand to a template: no secret values, only whether each is set
     * and whether this host can still read it.
     *
     * @return array<string, mixed>
     */
    public function forDisplay(string $destination): array
    {
        $raw = $this->raw($destination);
        $out = [];

        foreach ($this->fieldsFor($destination) as $field) {
            if (\in_array($field, self::SECRET_FIELDS, true)) {
                $stored = (string) ($raw[$field] ?? '');
                $out[$field . '_set'] = $stored !== '';
                // After a restore onto a new host the ciphertext arrives but
                // APP_SECRET does not, so this is a normal state, not an error.
                $out[$field . '_unreadable'] = $this->cipher->isUndecryptable($stored);
            } else {
                $out[$field] = (string) ($raw[$field] ?? '');
            }
        }

        return $out;
    }

    /**
     * Merge submitted values over what is stored. A blank secret means "leave
     * it as it is"; clearing one is an explicit action.
     *
     * @param array<string, string> $submitted
     */
    public function save(string $destination, array $submitted): void
    {
        $current = $this->raw($destination);

        foreach ($this->fieldsFor($destination) as $field) {
            if (!\array_key_exists($field, $submitted)) {
                continue;
            }

            $value = trim((string) $submitted[$field]);

            if (\in_array($field, self::SECRET_FIELDS, true)) {
                if ($value === '') {
                    continue; // untouched
                }
                if ($value === '__clear__') {
                    unset($current[$field]);
                    continue;
                }
                $current[$field] = $this->cipher->encrypt($value);
                continue;
            }

            $current[$field] = $value;
        }

        $this->write(self::KEY_PREFIX . $destination, json_encode($current, JSON_THROW_ON_ERROR));
    }

    /** @return list<string> */
    private function fieldsFor(string $destination): array
    {
        return match ($destination) {
            'sftp' => self::SFTP_FIELDS,
            's3' => self::S3_FIELDS,
            default => [],
        };
    }

    /**
     * UPDATE-then-INSERT, never ON DUPLICATE KEY: the unique index does not
     * dedupe rows whose scope_id is NULL, so the upsert form silently appends.
     * See Version20260904_003 for what that cost elsewhere.
     */
    private function write(string $key, string $value): void
    {
        $updated = $this->db->executeStatement(
            "UPDATE oci_configuration SET config_value = :v WHERE scope = 'system' AND scope_id IS NULL AND config_key = :k",
            ['v' => $value, 'k' => $key],
        );

        if ($updated === 0) {
            $this->db->executeStatement(
                "INSERT INTO oci_configuration (scope, scope_id, config_key, config_value) VALUES ('system', NULL, :k, :v)",
                ['k' => $key, 'v' => $value],
            );
        }
    }
}
