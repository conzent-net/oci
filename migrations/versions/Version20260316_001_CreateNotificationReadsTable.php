<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Create table to track which notifications each user has read.
 * One row per read notification — INSERT on read.
 */
final class Version20260316_001_CreateNotificationReadsTable extends Migration
{
    public function getDescription(): string
    {
        return 'Create oci_notification_reads table for per-user notification read tracking';
    }

    public function up(): void
    {
        $this->sql("
            CREATE TABLE IF NOT EXISTS `oci_notification_reads` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `notification_slug` VARCHAR(255) NOT NULL,
                `read_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_user_notification` (`user_id`, `notification_slug`),
                INDEX `idx_user` (`user_id`),
                CONSTRAINT `fk_notif_read_user` FOREIGN KEY (`user_id`)
                    REFERENCES `oci_users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropIfExists('oci_notification_reads');
    }
}
