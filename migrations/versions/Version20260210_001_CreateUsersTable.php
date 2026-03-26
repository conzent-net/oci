<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Identity domain: users, user_companies, user_sessions, api_keys.
 */
final class Version20260210_001_CreateUsersTable extends Migration
{
    public function getDescription(): string
    {
        return 'Create oci_users, oci_user_companies, oci_user_sessions, oci_api_keys tables';
    }

    public function up(): void
    {
        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_users` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `legacy_id` INT UNSIGNED NULL DEFAULT NULL,
                `username` VARCHAR(60) NOT NULL,
                `email` VARCHAR(255) NOT NULL,
                `first_name` VARCHAR(100) NOT NULL DEFAULT '',
                `last_name` VARCHAR(100) NOT NULL DEFAULT '',
                `password` VARCHAR(255) NOT NULL,
                `role` VARCHAR(20) NOT NULL DEFAULT 'customer' COMMENT 'admin, agency, reseller, customer',
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `is_enterprise` TINYINT(1) NOT NULL DEFAULT 0,
                `account_id` VARCHAR(50) NULL DEFAULT NULL,
                `price_model` VARCHAR(50) NULL DEFAULT NULL,
                `last_login_at` DATETIME NULL DEFAULT NULL,
                `last_login_ip` VARCHAR(45) NULL DEFAULT NULL,
                `login_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `deleted_at` DATETIME NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_oci_users_email` (`email`),
                UNIQUE KEY `uq_oci_users_username` (`username`),
                INDEX `idx_oci_users_legacy` (`legacy_id`),
                INDEX `idx_oci_users_role` (`role`),
                INDEX `idx_oci_users_deleted` (`deleted_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_user_companies` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `company_name` VARCHAR(200) NULL DEFAULT NULL,
                `vat_number` VARCHAR(30) NULL DEFAULT NULL,
                `address` VARCHAR(255) NULL DEFAULT NULL,
                `zip` VARCHAR(20) NULL DEFAULT NULL,
                `city` VARCHAR(100) NULL DEFAULT NULL,
                `state` VARCHAR(100) NULL DEFAULT NULL,
                `country_code` VARCHAR(10) NULL DEFAULT NULL,
                `phone` VARCHAR(30) NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_oci_user_companies_user` (`user_id`),
                CONSTRAINT `fk_oci_company_user` FOREIGN KEY (`user_id`) REFERENCES `oci_users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_user_sessions` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `session_id` VARCHAR(64) NOT NULL,
                `hashed_validator` VARCHAR(128) NOT NULL,
                `is_persistent` TINYINT(1) NOT NULL DEFAULT 0,
                `ip_address` VARCHAR(45) NOT NULL,
                `user_agent` VARCHAR(500) NULL DEFAULT NULL,
                `expires_at` DATETIME NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_oci_sessions_sid` (`session_id`),
                INDEX `idx_oci_sessions_user` (`user_id`),
                INDEX `idx_oci_sessions_expires` (`expires_at`),
                CONSTRAINT `fk_oci_session_user` FOREIGN KEY (`user_id`) REFERENCES `oci_users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_api_keys` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `api_key` VARCHAR(255) NOT NULL,
                `name` VARCHAR(100) NOT NULL DEFAULT 'Default',
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `last_used_at` DATETIME NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_oci_api_keys_key` (`api_key`),
                INDEX `idx_oci_api_keys_user` (`user_id`),
                CONSTRAINT `fk_oci_apikey_user` FOREIGN KEY (`user_id`) REFERENCES `oci_users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropIfExists('oci_api_keys');
        $this->dropIfExists('oci_user_sessions');
        $this->dropIfExists('oci_user_companies');
        $this->dropIfExists('oci_users');
    }
}
