<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Add onboarding_completed_at column to track guided tour completion.
 */
final class Version20260316_002_AddOnboardingToUsers extends Migration
{
    public function getDescription(): string
    {
        return 'Add onboarding_completed_at column to oci_users';
    }

    public function up(): void
    {
        $this->sql("
            ALTER TABLE `oci_users`
            ADD COLUMN `onboarding_completed_at` DATETIME NULL DEFAULT NULL
            AFTER `google_id`
        ");
    }

    public function down(): void
    {
        $this->sql("ALTER TABLE `oci_users` DROP COLUMN `onboarding_completed_at`");
    }
}
