<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * A pageview allowance per site.
 *
 * The monthly allowance used to be one number per plan, counted over every
 * site an account owns. It is now counted per site, and a site can carry a
 * larger allowance than the plan's base. 0 means "the plan's base allowance",
 * so every existing site keeps what its plan gives it.
 */
final class Version20261122_001_SitePageviewTier extends Migration
{
    public function getDescription(): string
    {
        return 'Add oci_sites.pageview_tier (monthly pageview allowance per site)';
    }

    public function up(): void
    {
        $this->sql("ALTER TABLE `oci_sites`
            ADD COLUMN IF NOT EXISTS `pageview_tier` INT UNSIGNED NOT NULL DEFAULT 0
            COMMENT 'Monthly pageview allowance for this site; 0 = the plan base'");
    }

    public function down(): void
    {
        $this->sql('ALTER TABLE `oci_sites` DROP COLUMN IF EXISTS `pageview_tier`');
    }
}
