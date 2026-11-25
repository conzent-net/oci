<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * oci_sites.suspended_reason on every install.
 *
 * The column arrived with the cloud billing rewrite, which self-hosted
 * releases leave out, while the agency overview and its issue list read it
 * on both editions. A fresh self-hosted install therefore had no column to
 * read. Installs that already have it are left untouched.
 */
final class Version20261020_001_SuspendedReasonEverywhere extends Migration
{
    public function getDescription(): string
    {
        return 'Add oci_sites.suspended_reason where the cloud billing migration did not';
    }

    public function up(): void
    {
        $this->sql('ALTER TABLE `oci_sites`
            ADD COLUMN IF NOT EXISTS `suspended_reason` VARCHAR(40) NULL DEFAULT NULL AFTER `status`');
    }

    public function down(): void
    {
        // Owned by Version20260315_001 on cloud installs; dropping it here
        // would take it from them too.
    }
}
