<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

final class Version20260314_004_AddShowHeadingToPolicyTemplates extends Migration
{
    public function getDescription(): string
    {
        return 'Add show_heading column to cookie and privacy policy template tables';
    }

    public function up(): void
    {
        $this->sql('ALTER TABLE oci_cookie_policy_templates ADD COLUMN show_heading TINYINT(1) NOT NULL DEFAULT 1 AFTER show_audit_table');
        $this->sql('ALTER TABLE oci_privacy_policy_templates ADD COLUMN show_heading TINYINT(1) NOT NULL DEFAULT 1 AFTER heading');
    }

    public function down(): void
    {
        $this->sql('ALTER TABLE oci_cookie_policy_templates DROP COLUMN show_heading');
        $this->sql('ALTER TABLE oci_privacy_policy_templates DROP COLUMN show_heading');
    }
}
