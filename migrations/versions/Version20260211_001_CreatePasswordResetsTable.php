<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Add oci_password_resets table for forgot-password / reset-password flow.
 */
final class Version20260211_001_CreatePasswordResetsTable extends Migration
{
    public function getDescription(): string
    {
        return 'Create oci_password_resets table';
    }

    public function up(): void
    {
        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_password_resets` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `email` VARCHAR(255) NOT NULL,
                `token` VARCHAR(128) NOT NULL,
                `expires_at` DATETIME NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_oci_pw_reset_email` (`email`),
                INDEX `idx_oci_pw_reset_token` (`token`),
                INDEX `idx_oci_pw_reset_expires` (`expires_at`),
                CONSTRAINT `fk_oci_pw_reset_user` FOREIGN KEY (`user_id`) REFERENCES `oci_users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropIfExists('oci_password_resets');
    }
}
