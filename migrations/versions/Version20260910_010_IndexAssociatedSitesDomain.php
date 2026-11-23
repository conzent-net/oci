<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * The plugin claim looks a domain up among associated domains as well as
 * primary ones. oci_associated_sites had no index on `domain`, so that lookup
 * was a table scan on every claim.
 */
final class Version20260910_010_IndexAssociatedSitesDomain extends Migration
{
    public function getDescription(): string
    {
        return 'Index oci_associated_sites.domain for the plugin claim lookup';
    }

    public function up(): void
    {
        $this->sql('ALTER TABLE `oci_associated_sites` ADD INDEX IF NOT EXISTS `idx_oci_assoc_domain` (`domain`)');
    }

    public function down(): void
    {
        $this->sql('ALTER TABLE `oci_associated_sites` DROP INDEX IF EXISTS `idx_oci_assoc_domain`');
    }
}
