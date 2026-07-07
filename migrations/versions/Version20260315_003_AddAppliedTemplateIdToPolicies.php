<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Track which template was applied to each site policy so templates can
 * display the list of sites they're applied to.
 */
final class Version20260315_003_AddAppliedTemplateIdToPolicies extends Migration
{
    public function getDescription(): string
    {
        return 'Add applied_template_id to cookie_policies and privacy_policies';
    }

    public function up(): void
    {
        $this->sql("
            ALTER TABLE `oci_cookie_policies`
            ADD COLUMN `applied_template_id` INT UNSIGNED NULL DEFAULT NULL AFTER `show_heading`
        ");

        $this->sql("
            ALTER TABLE `oci_privacy_policies`
            ADD COLUMN `applied_template_id` INT UNSIGNED NULL DEFAULT NULL AFTER `show_heading`
        ");
    }

    public function down(): void
    {
        $this->sql('ALTER TABLE `oci_cookie_policies` DROP COLUMN `applied_template_id`');
        $this->sql('ALTER TABLE `oci_privacy_policies` DROP COLUMN `applied_template_id`');
    }
}
