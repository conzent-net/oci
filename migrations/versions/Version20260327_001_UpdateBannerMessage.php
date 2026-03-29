<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Update the default banner message text and backfill existing sites.
 */
final class Version20260327_001_UpdateBannerMessage extends Migration
{
    private const NEW_MESSAGE = 'We use cookies to personalize our content and ads, provide social media features, and analyze our traffic. We also share information about your use of our website with our social media, advertising, and analytics partners. Our partners may combine this data with other information you have provided to them or that they have collected from your use of their services.';

    public function getDescription(): string
    {
        return 'Update default banner cookie notice message text';
    }

    public function up(): void
    {
        // 1. Update the default value in oci_banner_fields
        $this->db->executeStatement(
            "UPDATE oci_banner_fields bf
             INNER JOIN oci_banner_field_categories bfc ON bfc.id = bf.field_category_id
             INNER JOIN oci_banner_templates bt ON bt.id = bfc.template_id
             SET bf.default_value = ?
             WHERE bf.field_key = 'message'
               AND bfc.category_key = 'cookie_notice'
               AND bt.banner_slug = 'gdpr'",
            [self::NEW_MESSAGE]
        );

        // 2. Update the English label in oci_banner_field_translations
        $this->db->executeStatement(
            "UPDATE oci_banner_field_translations bft
             INNER JOIN oci_banner_fields bf ON bf.id = bft.field_id
             INNER JOIN oci_banner_field_categories bfc ON bfc.id = bf.field_category_id
             INNER JOIN oci_banner_templates bt ON bt.id = bfc.template_id
             INNER JOIN oci_languages l ON l.id = bft.language_id
             SET bft.label = ?
             WHERE bf.field_key = 'message'
               AND bfc.category_key = 'cookie_notice'
               AND bt.banner_slug = 'gdpr'
               AND l.lang_code = 'en'",
            [self::NEW_MESSAGE]
        );

        // 3. Backfill all existing site banners (English)
        $this->db->executeStatement(
            "UPDATE oci_site_banner_field_translations sbft
             INNER JOIN oci_banner_fields bf ON bf.id = sbft.field_id
             INNER JOIN oci_banner_field_categories bfc ON bfc.id = bf.field_category_id
             INNER JOIN oci_banner_templates bt ON bt.id = bfc.template_id
             INNER JOIN oci_languages l ON l.id = sbft.language_id
             SET sbft.value = ?
             WHERE bf.field_key = 'message'
               AND bfc.category_key = 'cookie_notice'
               AND bt.banner_slug = 'gdpr'
               AND l.lang_code = 'en'",
            [self::NEW_MESSAGE]
        );
    }

    public function down(): void
    {
        // No rollback — previous message text is not preserved
    }
}
