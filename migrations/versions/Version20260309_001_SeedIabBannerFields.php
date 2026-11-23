<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Seed IAB TCF v2.2 banner fields for the GDPR template.
 *
 * These fields provide the translatable labels used in the IAB consent UI
 * (notice description, preference description, tab labels, section titles,
 * vendor details). Without them, [conzent_iab_*] placeholders appear raw
 * in the rendered consent banner.
 */
final class Version20260309_001_SeedIabBannerFields extends Migration
{
    public function getDescription(): string
    {
        return 'Seed IAB TCF banner fields for the GDPR template';
    }

    public function up(): void
    {
        // ── Widen label column to support long HTML content ──
        $this->sql("ALTER TABLE oci_banner_field_translations MODIFY label TEXT NOT NULL");

        // ── Add IAB category for GDPR template ──
        $this->sql("INSERT IGNORE INTO oci_banner_field_categories (template_id, category_key, category_name, sort_order) VALUES (1, 'iab', 'IAB TCF', 5)");

        // ── IAB fields (GDPR template only) ──
        $fields = [
            ['iab_notice_description', 'textarea', '<p>We and our <button id=\"cnzIABNoticeButton\" class=\"cnz-iab-dec-btn\" aria-label=\"partners\">{vendor_count} partners</button> use cookies and other tracking technologies to improve your experience on our website. We may store and/or access information on a device and process personal data, such as your IP address and browsing data, for personalised advertising and content, advertising and content measurement, audience research and services development. Additionally, we may utilize precise geolocation data and identification through device scanning.</p><p>Please note that your consent will be valid across all our subdomains. You can change or withdraw your consent at any time by clicking the \"Consent Preferences\" button at the bottom of your screen. We respect your choices and are committed to providing you with a transparent and secure browsing experience.</p><p>Cookie Policy</p>', 0],
            ['iab_preference_description', 'textarea', '<p>Customize your consent preferences for Cookie Categories and advertising tracking preferences for Purposes & Features and Vendors below. You can give granular consent for each <button id=\"cnzIABPreferenceButton\" class=\"cnz-iab-dec-btn\" aria-label=\"Third Party Vendor\">Third Party Vendor</button> and <button id=\"cnzIABGACMPreferenceButton\" class=\"cnz-iab-dec-btn\" aria-label=\"Google Ad Tech Provider\">Google Ad Tech Provider</button>. Most vendors require consent for personal data processing, while some rely on legitimate interest. However, you have the right to object to their use of legitimate interest.</p>', 1],
            ['iab_nav_item_cookie_categories', 'text', 'Cookie Categories', 2],
            ['iab_nav_item_purposes_n_features', 'text', 'Purposes & Features', 3],
            ['iab_nav_item_vendors', 'text', 'Vendors', 4],
            ['iab_common_purposes', 'text', 'Purposes', 5],
            ['iab_common_special_purposes', 'text', 'Special Purposes', 6],
            ['iab_common_features', 'text', 'Features', 7],
            ['iab_common_special_features', 'text', 'Special Features', 8],
            ['iab_vendors_third_party_title', 'text', 'Third Party Vendors', 9],
            ['iab_vendors_google_ad_title', 'text', 'Google Ad Tech Providers', 10],
            ['iab_common_consent', 'text', 'Consent', 11],
            ['iab_common_legitimate_interest', 'text', 'Legitimate Interest', 12],
            ['iab_purpose_n_feature_illustration_subtitle', 'text', 'Illustrations', 13],
            ['iab_purpose_n_feature_vendors_seeking_consent', 'text', 'Number of Vendors seeking consent', 14],
            ['iab_purpose_n_feature_vendors_seeking_combained', 'text', 'Number of Vendors seeking consent or relying on legitimate interest', 15],
            ['iab_vendors_categories_of_data_subtitle', 'text', 'Categories of data', 16],
            ['iab_vendors_device_storage_overview_title', 'text', 'Device Storage Information', 17],
            ['iab_vendors_tracking_method_subtitle', 'text', 'Tracking method', 18],
            ['iab_vendors_tracking_method_cookie_message', 'text', 'Cookies', 19],
            ['iab_vendors_tracking_method_others_message', 'text', 'Other methods', 20],
            ['iab_vendors_maximum_duration_of_cookies_subtitle', 'text', 'Maximum duration of cookies', 21],
            ['iab_vendors_maximum_duration_of_cookies_unit', 'text', 'seconds', 22],
            ['iab_vendors_cookie_refreshed_message', 'text', 'Cookies are refreshed each session', 23],
            ['iab_vendors_cookie_not_refreshed_message', 'text', 'Cookies are not refreshed', 24],
            ['iab_vendors_privacy_policy_link_subtitle', 'text', 'Privacy policy', 25],
            ['iab_vendors_legitimate_interest_link_subtitle', 'text', 'Legitimate interest claim', 26],
            ['iab_vendors_retention_period_of_data_subtitle', 'text', 'Retention period of data', 27],
            ['iab_vendors_retention_period_of_data_unit', 'text', 'days', 28],
        ];

        foreach ($fields as [$fieldKey, $fieldType, $defaultValue, $sortOrder]) {
            $escapedValue = str_replace("'", "''", $defaultValue);
            $this->sql(sprintf(
                "INSERT INTO oci_banner_fields (field_category_id, field_key, field_type, default_value, sort_order)
                 SELECT id, '%s', '%s', '%s', %d
                 FROM oci_banner_field_categories
                 WHERE template_id = 1 AND category_key = 'iab'
                 LIMIT 1",
                $fieldKey,
                $fieldType,
                $escapedValue,
                $sortOrder,
            ));
        }

        // ── Seed English translations ──
        $this->sql("
            INSERT INTO oci_banner_field_translations (field_id, language_id, label)
            SELECT bf.id, l.id, bf.default_value
            FROM oci_banner_fields bf
            INNER JOIN oci_banner_field_categories bfc ON bfc.id = bf.field_category_id
            CROSS JOIN oci_languages l
            WHERE bfc.category_key = 'iab' AND bfc.template_id = 1 AND l.lang_code = 'en'
            AND NOT EXISTS (
                SELECT 1 FROM oci_banner_field_translations bft
                WHERE bft.field_id = bf.id AND bft.language_id = l.id
            )
        ");

        // ── Copy to existing site banners ──
        $this->sql("
            INSERT IGNORE INTO oci_site_banner_field_translations (site_banner_id, field_id, language_id, value)
            SELECT sb.id, bf.id, sl.language_id, bf.default_value
            FROM oci_site_banners sb
            INNER JOIN oci_banner_field_categories bfc ON bfc.template_id = sb.banner_template_id
            INNER JOIN oci_banner_fields bf ON bf.field_category_id = bfc.id
            INNER JOIN oci_site_languages sl ON sl.site_id = sb.site_id
            WHERE bfc.category_key = 'iab' AND bfc.template_id = 1
            AND NOT EXISTS (
                SELECT 1 FROM oci_site_banner_field_translations sbft
                WHERE sbft.site_banner_id = sb.id AND sbft.field_id = bf.id AND sbft.language_id = sl.language_id
            )
        ");
    }

    public function down(): void
    {
        // Remove IAB site banner translations
        $this->sql("
            DELETE sbft FROM oci_site_banner_field_translations sbft
            INNER JOIN oci_banner_fields bf ON bf.id = sbft.field_id
            INNER JOIN oci_banner_field_categories bfc ON bfc.id = bf.field_category_id
            WHERE bfc.category_key = 'iab' AND bfc.template_id = 1
        ");

        // Remove IAB field translations
        $this->sql("
            DELETE bft FROM oci_banner_field_translations bft
            INNER JOIN oci_banner_fields bf ON bf.id = bft.field_id
            INNER JOIN oci_banner_field_categories bfc ON bfc.id = bf.field_category_id
            WHERE bfc.category_key = 'iab' AND bfc.template_id = 1
        ");

        // Remove IAB fields
        $this->sql("
            DELETE bf FROM oci_banner_fields bf
            INNER JOIN oci_banner_field_categories bfc ON bfc.id = bf.field_category_id
            WHERE bfc.category_key = 'iab' AND bfc.template_id = 1
        ");

        // Remove IAB category
        $this->sql("DELETE FROM oci_banner_field_categories WHERE template_id = 1 AND category_key = 'iab'");
    }
}
