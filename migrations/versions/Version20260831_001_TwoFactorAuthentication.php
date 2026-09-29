<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * TOTP two-factor authentication.
 *
 * Four pieces, and the reasoning for each shape:
 *
 * - `oci_users.totp_secret` holds the shared secret ENCRYPTED at rest, so a
 *   database leak does not hand an attacker every enrolled user's second
 *   factor. `totp_confirmed_at` is what actually switches 2FA on: a secret
 *   exists from the moment enrolment starts, but it must not be enforced until
 *   the user has proved they can generate a code, or they lock themselves out
 *   during setup. `totp_last_used_step` blocks replay of a code inside its own
 *   30-second window.
 *
 * - Recovery codes get their own table rather than a JSON column so that
 *   spending one is a single atomic UPDATE, not a read-modify-write of a blob
 *   that two concurrent requests could both win.
 *
 * - Trusted devices are the "don't ask again on this device" tokens. They are
 *   BYPASS credentials, so they carry an expiry and must be revoked whenever
 *   trust is broken (2FA reset, re-enrolment, password reset).
 *
 * - `oci_user_sessions.two_factor_verified_at` is where the check actually
 *   lives. Marking the SESSION rather than the request is what makes this work
 *   across every login path: remember-me restores from this row and inherits
 *   the stamp instead of re-prompting, and impersonation creates a session that
 *   is pre-stamped because the impersonator already proved themselves.
 */
final class Version20260831_001_TwoFactorAuthentication extends Migration
{
    public function getDescription(): string
    {
        return 'TOTP two-factor authentication: secrets, recovery codes, trusted devices, session verification';
    }

    public function up(): void
    {
        $this->sql("
            ALTER TABLE `oci_users`
            ADD COLUMN `totp_secret` VARCHAR(255) NULL DEFAULT NULL
                COMMENT 'encrypted TOTP shared secret'
                AFTER `password`,
            ADD COLUMN `totp_confirmed_at` DATETIME NULL DEFAULT NULL
                COMMENT 'set once a valid code was entered; NULL means 2FA is not enforced'
                AFTER `totp_secret`,
            ADD COLUMN `totp_last_used_step` BIGINT UNSIGNED NULL DEFAULT NULL
                COMMENT 'last accepted time step, blocks replay within the same window'
                AFTER `totp_confirmed_at`
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_user_recovery_codes` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `code_hash` VARCHAR(255) NOT NULL,
                `used_at` DATETIME NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_recovery_user` (`user_id`, `used_at`),
                CONSTRAINT `fk_recovery_user` FOREIGN KEY (`user_id`)
                    REFERENCES `oci_users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_user_trusted_devices` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `selector` VARCHAR(32) NOT NULL,
                `hashed_validator` VARCHAR(128) NOT NULL,
                `label` VARCHAR(200) NOT NULL DEFAULT '',
                `ip_address` VARCHAR(45) NULL DEFAULT NULL,
                `last_used_at` DATETIME NULL DEFAULT NULL,
                `expires_at` DATETIME NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_trusted_selector` (`selector`),
                INDEX `idx_trusted_user` (`user_id`, `expires_at`),
                CONSTRAINT `fk_trusted_user` FOREIGN KEY (`user_id`)
                    REFERENCES `oci_users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            ALTER TABLE `oci_user_sessions`
            ADD COLUMN `two_factor_verified_at` DATETIME NULL DEFAULT NULL
                COMMENT 'when the second factor was satisfied for this session'
                AFTER `is_persistent`
        ");

        // Every session that exists right now was created before 2FA did, so it
        // predates any secret and must not be logged out by the new gate.
        $this->sql('UPDATE `oci_user_sessions` SET `two_factor_verified_at` = NOW()');
    }

    public function down(): void
    {
        $this->dropIfExists('oci_user_trusted_devices');
        $this->dropIfExists('oci_user_recovery_codes');

        $this->sql('ALTER TABLE `oci_user_sessions` DROP COLUMN `two_factor_verified_at`');
        $this->sql('
            ALTER TABLE `oci_users`
            DROP COLUMN `totp_last_used_step`,
            DROP COLUMN `totp_confirmed_at`,
            DROP COLUMN `totp_secret`
        ');
    }
}
