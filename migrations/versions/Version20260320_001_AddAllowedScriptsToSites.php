<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Add allowed_scripts column to oci_sites for user-defined script whitelist.
 *
 * Stores a JSON array of URL patterns (partial matches) that the consent
 * banner should never block — e.g. Alpine.js CDN, jQuery CDN, or any
 * functional third-party script the site owner considers essential.
 */
final class Version20260320_001_AddAllowedScriptsToSites extends Migration
{
    public function getDescription(): string
    {
        return 'Add allowed_scripts (JSON) column to oci_sites for script whitelist';
    }

    public function up(): void
    {
        $this->sql("ALTER TABLE `oci_sites`
            ADD COLUMN `allowed_scripts` TEXT NULL COMMENT 'json' AFTER `disable_on_pages`");
    }

    public function down(): void
    {
        $this->sql('ALTER TABLE `oci_sites` DROP COLUMN `allowed_scripts`');
    }
}
