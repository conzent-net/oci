<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Seed banner field categories and fields from legacy data.
 *
 * Populates oci_banner_field_categories and oci_banner_fields for both
 * GDPR (template 1) and CCPA (template 2) banner templates.
 */
final class Version20260308_001_SeedBannerFields extends Migration
{
    public function getDescription(): string
    {
        return 'Seed banner field categories and fields for GDPR and CCPA templates';
    }

    public function up(): void
    {
        // ── Seed banner templates (required before categories/fields) ──
        $this->sql("INSERT IGNORE INTO oci_banner_templates (id, banner_name, banner_slug, is_default, is_active, cookie_laws) VALUES
            (1, 'GDPR Banner', 'gdpr', 1, 1, 'gdpr'),
            (2, 'CCPA Banner', 'ccpa', 0, 1, 'ccpa')
        ");

        // ── GDPR template (id=1) categories ──────────────────
        $gdprCategories = [
            ['template_id' => 1, 'category_key' => 'cookie_notice',         'category_name' => 'Cookie Notice',          'sort_order' => 0],
            ['template_id' => 1, 'category_key' => 'preference_center',     'category_name' => 'Preference Center',      'sort_order' => 1],
            ['template_id' => 1, 'category_key' => 'cookie_list',           'category_name' => 'Cookie List',             'sort_order' => 2],
            ['template_id' => 1, 'category_key' => 'revisit_consent_button','category_name' => 'Revisit Consent Button',  'sort_order' => 3],
            ['template_id' => 1, 'category_key' => 'blocked_content',       'category_name' => 'Blocked Content',         'sort_order' => 4],
        ];

        // ── CCPA template (id=2) categories ──────────────────
        $ccpaCategories = [
            ['template_id' => 2, 'category_key' => 'cookie_notice',         'category_name' => 'Cookie Notice',          'sort_order' => 0],
            ['template_id' => 2, 'category_key' => 'opt_out_center',        'category_name' => 'Opt-out Center',          'sort_order' => 1],
            ['template_id' => 2, 'category_key' => 'cookie_list',           'category_name' => 'Cookie List',             'sort_order' => 2],
            ['template_id' => 2, 'category_key' => 'revisit_consent_button','category_name' => 'Revisit Consent Button',  'sort_order' => 3],
            ['template_id' => 2, 'category_key' => 'blocked_content',       'category_name' => 'Blocked Content',         'sort_order' => 4],
        ];

        $allCategories = array_merge($gdprCategories, $ccpaCategories);
        $catIds = [];

        foreach ($allCategories as $cat) {
            $this->sql(sprintf(
                "INSERT INTO oci_banner_field_categories (template_id, category_key, category_name, sort_order) VALUES (%d, '%s', '%s', %d)",
                $cat['template_id'],
                $cat['category_key'],
                $cat['category_name'],
                $cat['sort_order'],
            ));
        }

        // Now insert fields — we use a subquery to resolve the category_id
        $gdprFields = [
            // Cookie Notice
            ['cookie_notice', 1, 'banner_title',       'text',     'We value your privacy', 0],
            ['cookie_notice', 1, 'message',             'textarea', 'We use cookies to customize our content and ads, to provide social media features and to analyze our traffic.', 1],
            ['cookie_notice', 1, 'accept_all_button',   'text',     'Accept All', 2],
            ['cookie_notice', 1, 'reject_all_button',   'text',     'Reject All', 3],
            ['cookie_notice', 1, 'customize_button',    'text',     'Customize', 4],
            ['cookie_notice', 1, 'cookie_policy_label', 'text',     'Cookie Policy', 5],
            // Preference Center
            ['preference_center', 1, 'preference_title',     'text',     'Customize Consent Preferences', 0],
            ['preference_center', 1, 'overview',              'textarea', 'We use cookies to help you navigate efficiently and perform certain functions.', 1],
            ['preference_center', 1, 'save_preferences',      'text',     'Save My Preferences', 2],
            ['preference_center', 1, 'show_more_button',      'text',     'Show more', 3],
            ['preference_center', 1, 'show_less_button',      'text',     'Show less', 4],
            ['preference_center', 1, 'google_privacy_message','textarea', 'For more information on how Google\'s third-party cookies are used, please see Google\'s privacy policy.', 5],
            ['preference_center', 1, 'google_privacy_label',  'text',     'Google Privacy Policy', 6],
            ['preference_center', 1, 'google_privacy_url',    'text',     'https://business.safety.google/privacy', 7],
            // Cookie List
            ['cookie_list', 1, 'cookie',                    'text', 'Cookie', 0],
            ['cookie_list', 1, 'description',               'text', 'Description', 1],
            ['cookie_list', 1, 'always_active_text',        'text', 'Always Active', 2],
            ['cookie_list', 1, 'no_cookie_to_display_text', 'text', 'No cookies to display.', 3],
            ['cookie_list', 1, 'duration',                  'text', 'Duration', 4],
            // Revisit
            ['revisit_consent_button', 1, 'text_on_hover',        'text', 'Consent Preferences', 0],
            // Blocked Content
            ['blocked_content', 1, 'alt_text_blocked_content', 'text', 'Please accept cookies to access this content', 0],
        ];

        $ccpaFields = [
            // Cookie Notice
            ['cookie_notice', 2, 'title',              'text',     'We value your privacy', 0],
            ['cookie_notice', 2, 'message',             'textarea', 'This website or its third-party tools process personal data and use cookies or other identifiers.', 1],
            ['cookie_notice', 2, 'do_not_sell_link',    'text',     'Do Not Sell or Share My Personal Information', 2],
            ['cookie_notice', 2, 'cookie_policy_label', 'text',     'Cookie Policy', 3],
            // Opt-out Center
            ['opt_out_center', 2, 'title',              'text',     'Opt-out Preferences', 0],
            ['opt_out_center', 2, 'overview',           'textarea', 'We use third-party cookies that help us analyze how you use this website.', 1],
            ['opt_out_center', 2, 'cancel_button',      'text',     'Cancel', 2],
            ['opt_out_center', 2, 'show_more_button',   'text',     'Show more', 3],
            ['opt_out_center', 2, 'show_less_button',   'text',     'Show less', 4],
            ['opt_out_center', 2, 'save_preferences',   'text',     'Save My Preferences', 5],
            // Cookie List
            ['cookie_list', 2, 'cookie',      'text', 'Cookie', 0],
            ['cookie_list', 2, 'duration',    'text', 'Duration', 1],
            ['cookie_list', 2, 'description', 'text', 'Description', 2],
            // Revisit
            ['revisit_consent_button', 2, 'text_on_hover',        'text', 'Consent Preferences', 0],
            // Blocked Content
            ['blocked_content', 2, 'alt_text_blocked_content', 'text', 'Please accept cookies to access this content', 0],
        ];

        $allFields = array_merge($gdprFields, $ccpaFields);

        foreach ($allFields as $f) {
            [$catKey, $templateId, $fieldKey, $fieldType, $defaultValue, $sortOrder] = $f;
            $escapedValue = str_replace("'", "''", $defaultValue);
            $this->sql(sprintf(
                "INSERT INTO oci_banner_fields (field_category_id, field_key, field_type, default_value, sort_order)
                 SELECT id, '%s', '%s', '%s', %d
                 FROM oci_banner_field_categories
                 WHERE template_id = %d AND category_key = '%s'
                 LIMIT 1",
                $fieldKey,
                $fieldType,
                $escapedValue,
                $sortOrder,
                $templateId,
                $catKey,
            ));
        }

        // Also seed English translations for the default fields
        $this->sql("
            INSERT INTO oci_banner_field_translations (field_id, language_id, label)
            SELECT bf.id, l.id, bf.default_value
            FROM oci_banner_fields bf
            CROSS JOIN oci_languages l
            WHERE l.lang_code = 'en'
            AND NOT EXISTS (
                SELECT 1 FROM oci_banner_field_translations bft
                WHERE bft.field_id = bf.id AND bft.language_id = l.id
            )
        ");

        // Copy field translations to existing site banners that are missing them
        $this->sql("
            INSERT IGNORE INTO oci_site_banner_field_translations (site_banner_id, field_id, language_id, value)
            SELECT sb.id, bf.id, sl.language_id, bf.default_value
            FROM oci_site_banners sb
            INNER JOIN oci_banner_field_categories bfc ON bfc.template_id = sb.banner_template_id
            INNER JOIN oci_banner_fields bf ON bf.field_category_id = bfc.id
            INNER JOIN oci_site_languages sl ON sl.site_id = sb.site_id
            WHERE NOT EXISTS (
                SELECT 1 FROM oci_site_banner_field_translations sbft
                WHERE sbft.site_banner_id = sb.id AND sbft.field_id = bf.id AND sbft.language_id = sl.language_id
            )
        ");
    }

    public function down(): void
    {
        $this->sql('DELETE FROM oci_site_banner_field_translations');
        $this->sql('DELETE FROM oci_banner_field_translations');
        $this->sql('DELETE FROM oci_banner_fields');
        $this->sql('DELETE FROM oci_banner_field_categories');
    }
}
