<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Classification provenance + review flags on the global reference tables.
 *
 * AI-assisted classifications record who classified and with what confidence;
 * anything under the 80% bar is flagged for the weekly human review sweep.
 */
final class Version20260829_001_ClassificationReviewColumns extends Migration
{
    public function getDescription(): string
    {
        return 'Add classified_by / ai_confidence / needs_review to global cookie and beacon reference tables';
    }

    public function up(): void
    {
        $this->sql("ALTER TABLE `oci_cookies_global`
            ADD COLUMN IF NOT EXISTS `classified_by` VARCHAR(10) NOT NULL DEFAULT 'human' AFTER `wildcard_match`,
            ADD COLUMN IF NOT EXISTS `ai_confidence` DECIMAL(3,2) NULL AFTER `classified_by`,
            ADD COLUMN IF NOT EXISTS `needs_review` TINYINT(1) NOT NULL DEFAULT 0 AFTER `ai_confidence`,
            ADD INDEX IF NOT EXISTS `idx_needs_review` (`needs_review`)");

        $this->sql("ALTER TABLE `oci_beacons_global`
            ADD COLUMN IF NOT EXISTS `classified_by` VARCHAR(10) NOT NULL DEFAULT 'human' AFTER `description`,
            ADD COLUMN IF NOT EXISTS `ai_confidence` DECIMAL(3,2) NULL AFTER `classified_by`,
            ADD COLUMN IF NOT EXISTS `needs_review` TINYINT(1) NOT NULL DEFAULT 0 AFTER `ai_confidence`,
            ADD INDEX IF NOT EXISTS `idx_needs_review` (`needs_review`)");
    }

    public function down(): void
    {
        $this->sql('ALTER TABLE `oci_cookies_global`
            DROP INDEX IF EXISTS `idx_needs_review`,
            DROP COLUMN IF EXISTS `classified_by`,
            DROP COLUMN IF EXISTS `ai_confidence`,
            DROP COLUMN IF EXISTS `needs_review`');

        $this->sql('ALTER TABLE `oci_beacons_global`
            DROP INDEX IF EXISTS `idx_needs_review`,
            DROP COLUMN IF EXISTS `classified_by`,
            DROP COLUMN IF EXISTS `ai_confidence`,
            DROP COLUMN IF EXISTS `needs_review`');
    }
}
