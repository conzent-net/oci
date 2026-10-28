<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Creates the oci_agency_invites table for agency-to-user invitations.
 */
final class Version20260309_002_CreateAgencyInvitesTable extends Migration
{
    public function getDescription(): string
    {
        return 'Create oci_agency_invites table for agency invitation workflow';
    }

    public function up(): void
    {
        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_agency_invites` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `agency_id` INT UNSIGNED NOT NULL,
                `target_user_id` INT UNSIGNED NOT NULL,
                `token` VARCHAR(128) NOT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending, accepted, declined',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `expires_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                INDEX `idx_oci_agency_invites_token` (`token`),
                INDEX `idx_oci_agency_invites_target` (`target_user_id`),
                INDEX `idx_oci_agency_invites_agency` (`agency_id`),
                CONSTRAINT `fk_oci_agency_invites_agency` FOREIGN KEY (`agency_id`) REFERENCES `oci_agencies` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_oci_agency_invites_user` FOREIGN KEY (`target_user_id`) REFERENCES `oci_users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropIfExists('oci_agency_invites');
    }
}
