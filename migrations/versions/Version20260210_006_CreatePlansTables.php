<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Monetization domain: plans, features, subscriptions, user funds, transactions.
 */
final class Version20260210_006_CreatePlansTables extends Migration
{
    public function getDescription(): string
    {
        return 'Create plans, plan_features, subscriptions, user_plans, funds, transactions tables';
    }

    public function up(): void
    {
        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_plans` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `legacy_id` INT UNSIGNED NULL DEFAULT NULL,
                `plan_name` VARCHAR(100) NOT NULL,
                `plan_slug` VARCHAR(80) NOT NULL,
                `plan_description` TEXT NULL DEFAULT NULL,
                `duration_months` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                `duration_type` VARCHAR(20) NOT NULL DEFAULT 'monthly',
                `monthly_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `yearly_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `stripe_monthly_price_id` VARCHAR(100) NULL DEFAULT NULL,
                `stripe_yearly_price_id` VARCHAR(100) NULL DEFAULT NULL,
                `is_trial` TINYINT(1) NOT NULL DEFAULT 0,
                `trial_period_days` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `is_default` TINYINT(1) NOT NULL DEFAULT 0,
                `is_recurring` TINYINT(1) NOT NULL DEFAULT 1,
                `is_custom` TINYINT(1) NOT NULL DEFAULT 0,
                `is_lifetime` TINYINT(1) NOT NULL DEFAULT 0,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `pageview_limit` INT UNSIGNED NOT NULL DEFAULT 0,
                `pages_per_scan` INT UNSIGNED NOT NULL DEFAULT 0,
                `max_languages` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                `max_domains` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                `max_users` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `max_layouts` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                `free_months` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `sort_order` SMALLINT NOT NULL DEFAULT 0,
                `created_by` INT UNSIGNED NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_oci_plan_slug` (`plan_slug`),
                INDEX `idx_oci_plan_legacy` (`legacy_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_plan_features` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `feature_key` VARCHAR(80) NOT NULL,
                `feature_name` VARCHAR(200) NOT NULL,
                `description` TEXT NULL DEFAULT NULL,
                `feature_type` VARCHAR(20) NOT NULL DEFAULT 'boolean' COMMENT 'boolean, numeric, text',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_oci_feat_key` (`feature_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_plan_feature_items` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `plan_id` INT UNSIGNED NOT NULL,
                `feature_id` INT UNSIGNED NOT NULL,
                `value` VARCHAR(255) NOT NULL DEFAULT '1',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_oci_pfi` (`plan_id`, `feature_id`),
                CONSTRAINT `fk_oci_pfi_plan` FOREIGN KEY (`plan_id`) REFERENCES `oci_plans` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_oci_pfi_feat` FOREIGN KEY (`feature_id`) REFERENCES `oci_plan_features` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_subscriptions` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `legacy_id` INT UNSIGNED NULL DEFAULT NULL,
                `user_id` INT UNSIGNED NOT NULL,
                `site_id` INT UNSIGNED NOT NULL,
                `plan_id` INT UNSIGNED NOT NULL,
                `item_name` VARCHAR(200) NULL DEFAULT NULL,
                `order_id` VARCHAR(100) NOT NULL,
                `external_subscription_id` VARCHAR(200) NULL DEFAULT NULL,
                `payment_method` VARCHAR(40) NOT NULL DEFAULT 'unknown',
                `currency` VARCHAR(10) NOT NULL DEFAULT 'EUR',
                `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `vat_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `billing_cycle` VARCHAR(20) NOT NULL DEFAULT 'monthly',
                `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
                `plan_status` VARCHAR(30) NOT NULL DEFAULT 'new',
                `invoice_id` VARCHAR(100) NULL DEFAULT NULL,
                `customer_email` VARCHAR(255) NULL DEFAULT NULL,
                `expires_at` DATETIME NOT NULL,
                `cancelled_at` DATETIME NULL DEFAULT NULL,
                `cancel_requested_at` DATETIME NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_oci_sub_legacy` (`legacy_id`),
                INDEX `idx_oci_sub_user` (`user_id`),
                INDEX `idx_oci_sub_site` (`site_id`),
                INDEX `idx_oci_sub_status` (`status`),
                INDEX `idx_oci_sub_expires` (`expires_at`),
                CONSTRAINT `fk_oci_sub_user` FOREIGN KEY (`user_id`) REFERENCES `oci_users` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_oci_sub_site` FOREIGN KEY (`site_id`) REFERENCES `oci_sites` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_oci_sub_plan` FOREIGN KEY (`plan_id`) REFERENCES `oci_plans` (`id`) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_user_plans` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `site_id` INT UNSIGNED NOT NULL,
                `plan_id` INT UNSIGNED NOT NULL,
                `subscription_id` INT UNSIGNED NULL DEFAULT NULL,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `activated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `expires_at` DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                INDEX `idx_oci_up_user` (`user_id`),
                INDEX `idx_oci_up_site` (`site_id`),
                CONSTRAINT `fk_oci_up_user` FOREIGN KEY (`user_id`) REFERENCES `oci_users` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_oci_up_site` FOREIGN KEY (`site_id`) REFERENCES `oci_sites` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_oci_up_plan` FOREIGN KEY (`plan_id`) REFERENCES `oci_plans` (`id`) ON DELETE RESTRICT,
                CONSTRAINT `fk_oci_up_sub` FOREIGN KEY (`subscription_id`) REFERENCES `oci_subscriptions` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_user_plan_features` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_plan_id` INT UNSIGNED NOT NULL,
                `feature_id` INT UNSIGNED NOT NULL,
                `value` VARCHAR(255) NOT NULL DEFAULT '1',
                `override_reason` VARCHAR(200) NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_oci_upf` (`user_plan_id`, `feature_id`),
                CONSTRAINT `fk_oci_upf_up` FOREIGN KEY (`user_plan_id`) REFERENCES `oci_user_plans` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_oci_upf_feat` FOREIGN KEY (`feature_id`) REFERENCES `oci_plan_features` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_user_funds` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `balance` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `currency` VARCHAR(10) NOT NULL DEFAULT 'EUR',
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_oci_funds_user` (`user_id`),
                CONSTRAINT `fk_oci_funds_user` FOREIGN KEY (`user_id`) REFERENCES `oci_users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_transactions` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `subscription_id` INT UNSIGNED NULL DEFAULT NULL,
                `type` VARCHAR(30) NOT NULL COMMENT 'payment, refund, credit, debit, top_up',
                `amount` DECIMAL(10,2) NOT NULL,
                `currency` VARCHAR(10) NOT NULL DEFAULT 'EUR',
                `description` VARCHAR(500) NULL DEFAULT NULL,
                `external_reference` VARCHAR(200) NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_oci_tx_user` (`user_id`),
                INDEX `idx_oci_tx_sub` (`subscription_id`),
                INDEX `idx_oci_tx_created` (`created_at`),
                CONSTRAINT `fk_oci_tx_user` FOREIGN KEY (`user_id`) REFERENCES `oci_users` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_oci_tx_sub` FOREIGN KEY (`subscription_id`) REFERENCES `oci_subscriptions` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropIfExists('oci_transactions');
        $this->dropIfExists('oci_user_funds');
        $this->dropIfExists('oci_user_plan_features');
        $this->dropIfExists('oci_user_plans');
        $this->dropIfExists('oci_subscriptions');
        $this->dropIfExists('oci_plan_feature_items');
        $this->dropIfExists('oci_plan_features');
        $this->dropIfExists('oci_plans');
    }
}
