<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Add variant_id and user_consent_time to oci_consents for A/B testing
 * and client/server time matching (GDPR proof).
 */
final class Version20260310_002_AddConsentTrackingColumns extends Migration
{
    public function getDescription(): string
    {
        return 'Add variant_id and user_consent_time columns to oci_consents';
    }

    public function up(): void
    {
        $this->sql("
            ALTER TABLE `oci_consents`
                ADD COLUMN `variant_id` INT UNSIGNED NULL DEFAULT NULL
                    AFTER `consent_session`,
                ADD COLUMN `user_consent_time` DATETIME NULL DEFAULT NULL
                    COMMENT 'Client-side timestamp for GDPR proof matching'
                    AFTER `consent_date`,
                ADD INDEX `idx_consent_variant` (`variant_id`)
        ");
    }

    public function down(): void
    {
        $this->sql("
            ALTER TABLE `oci_consents`
                DROP INDEX `idx_consent_variant`,
                DROP COLUMN `variant_id`,
                DROP COLUMN `user_consent_time`
        ");
    }
}
