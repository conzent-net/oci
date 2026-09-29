<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Creates the oci_cookie_reclass_requests table.
 *
 * Users can request that a Conzent-classified cookie be moved to a different
 * category. Requests are reviewed by staff in the admin queue; approving one
 * updates the global cookie database for every site.
 */
final class Version20260706_001_CreateCookieReclassRequests extends Migration
{
    public function getDescription(): string
    {
        return 'Create oci_cookie_reclass_requests table for cookie reclassification requests';
    }

    public function up(): void
    {
        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_cookie_reclass_requests` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `site_id` INT UNSIGNED NOT NULL,
                `user_id` INT UNSIGNED NOT NULL,
                `cookie_name` VARCHAR(255) NOT NULL,
                `cookie_domain` VARCHAR(255) NULL DEFAULT NULL,
                `current_category_slug` VARCHAR(60) NULL DEFAULT NULL COMMENT 'snapshot of the current classification',
                `requested_category_id` INT UNSIGNED NOT NULL,
                `reason` TEXT NULL DEFAULT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending, approved, rejected',
                `review_note` TEXT NULL DEFAULT NULL,
                `reviewed_by` INT UNSIGNED NULL DEFAULT NULL,
                `reviewed_at` DATETIME NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_oci_reclass_status` (`status`),
                INDEX `idx_oci_reclass_site` (`site_id`),
                CONSTRAINT `fk_oci_reclass_site` FOREIGN KEY (`site_id`) REFERENCES `oci_sites` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_oci_reclass_user` FOREIGN KEY (`user_id`) REFERENCES `oci_users` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_oci_reclass_cat` FOREIGN KEY (`requested_category_id`) REFERENCES `oci_cookie_categories` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropIfExists('oci_cookie_reclass_requests');
    }
}
