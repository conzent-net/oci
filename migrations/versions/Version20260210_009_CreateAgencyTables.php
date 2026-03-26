<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Agency domain: agencies, customers, commissions.
 */
final class Version20260210_009_CreateAgencyTables extends Migration
{
    public function getDescription(): string
    {
        return 'Create agencies, agency_customers, agency_commissions tables';
    }

    public function up(): void
    {
        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_agencies` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `legacy_id` INT UNSIGNED NULL DEFAULT NULL,
                `user_id` INT UNSIGNED NOT NULL,
                `name` VARCHAR(200) NOT NULL,
                `address` VARCHAR(255) NULL DEFAULT NULL,
                `zip` VARCHAR(20) NULL DEFAULT NULL,
                `city` VARCHAR(100) NULL DEFAULT NULL,
                `state` VARCHAR(100) NULL DEFAULT NULL,
                `country_code` VARCHAR(10) NULL DEFAULT NULL,
                `vat_number` VARCHAR(30) NULL DEFAULT NULL,
                `contact_name` VARCHAR(200) NULL DEFAULT NULL,
                `contact_email` VARCHAR(255) NULL DEFAULT NULL,
                `invoice_email` VARCHAR(255) NULL DEFAULT NULL,
                `iban` VARCHAR(50) NULL DEFAULT NULL,
                `swift` VARCHAR(20) NULL DEFAULT NULL,
                `account_reg` VARCHAR(50) NULL DEFAULT NULL,
                `account_number` VARCHAR(50) NULL DEFAULT NULL,
                `commission_pct` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `agency_type` VARCHAR(20) NOT NULL DEFAULT 'agency' COMMENT 'agency, reseller',
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_oci_agency_legacy` (`legacy_id`),
                INDEX `idx_oci_agency_user` (`user_id`),
                CONSTRAINT `fk_oci_agency_user` FOREIGN KEY (`user_id`) REFERENCES `oci_users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_agency_customers` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `agency_id` INT UNSIGNED NOT NULL,
                `customer_user_id` INT UNSIGNED NOT NULL,
                `date_from` DATE NULL DEFAULT NULL,
                `date_to` DATE NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_oci_agcust` (`agency_id`, `customer_user_id`),
                CONSTRAINT `fk_oci_agcust_agency` FOREIGN KEY (`agency_id`) REFERENCES `oci_agencies` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_oci_agcust_user` FOREIGN KEY (`customer_user_id`) REFERENCES `oci_users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_agency_commissions` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `agency_id` INT UNSIGNED NOT NULL,
                `subscription_id` INT UNSIGNED NOT NULL,
                `amount` DECIMAL(10,2) NOT NULL,
                `currency` VARCHAR(10) NOT NULL DEFAULT 'EUR',
                `status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending, paid, cancelled',
                `paid_at` DATETIME NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_oci_agcomm_agency` (`agency_id`),
                INDEX `idx_oci_agcomm_sub` (`subscription_id`),
                CONSTRAINT `fk_oci_agcomm_agency` FOREIGN KEY (`agency_id`) REFERENCES `oci_agencies` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_oci_agcomm_sub` FOREIGN KEY (`subscription_id`) REFERENCES `oci_subscriptions` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropIfExists('oci_agency_commissions');
        $this->dropIfExists('oci_agency_customers');
        $this->dropIfExists('oci_agencies');
    }
}
