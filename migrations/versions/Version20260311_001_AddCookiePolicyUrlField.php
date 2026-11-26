<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Add cookie_policy_url banner field for GDPR and CCPA templates.
 *
 * This field was missing from the initial seed, causing the
 * [conzent_cookie_notice_cookie_policy_url] placeholder to remain
 * unresolved in generated scripts.
 */
final class Version20260311_001_AddCookiePolicyUrlField extends Migration
{
    public function getDescription(): string
    {
        return 'Add cookie_policy_url banner field for GDPR and CCPA templates';
    }

    public function up(): void
    {
        // GDPR: insert cookie_policy_url after cookie_policy_label (sort_order 6)
        // in the cookie_notice category for template 1
        $this->sql("
            INSERT INTO oci_banner_fields (field_category_id, field_key, field_type, default_value, sort_order)
            SELECT id, 'cookie_policy_url', 'text', '', 6
            FROM oci_banner_field_categories
            WHERE template_id = 1 AND category_key = 'cookie_notice'
            LIMIT 1
        ");

        // CCPA: insert cookie_policy_url after cookie_policy_label (sort_order 4)
        // in the cookie_notice category for template 2
        $this->sql("
            INSERT INTO oci_banner_fields (field_category_id, field_key, field_type, default_value, sort_order)
            SELECT id, 'cookie_policy_url', 'text', '', 4
            FROM oci_banner_field_categories
            WHERE template_id = 2 AND category_key = 'cookie_notice'
            LIMIT 1
        ");
    }

    public function down(): void
    {
        $this->sql("DELETE FROM oci_banner_fields WHERE field_key = 'cookie_policy_url'");
    }
}
