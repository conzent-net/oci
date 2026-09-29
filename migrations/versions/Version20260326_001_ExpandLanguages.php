<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Expand supported languages from 51 to ~90.
 *
 * - Removes invalid 'in' ("India") entry (not an ISO 639-1 language code)
 * - Adds ~40 missing world languages (Indian, African, Turkic, SE Asian, etc.)
 * - Seeds banner field translations and cookie category translations for new languages
 *   via the cached translation file (resources/data/banner-field-translations.php)
 */
final class Version20260326_001_ExpandLanguages extends Migration
{
    public function getDescription(): string
    {
        return 'Remove invalid "in" language, add ~40 missing world languages';
    }

    public function up(): void
    {
        // 1. Remove the invalid 'in' = "India" entry (cascades to translations)
        $inId = $this->db->fetchOne("SELECT id FROM oci_languages WHERE lang_code = 'in'");
        if ($inId !== false) {
            // Delete any translations/site_languages that reference it first
            $this->db->executeStatement(
                'DELETE FROM oci_banner_field_translations WHERE language_id = ?',
                [(int) $inId],
            );
            $this->db->executeStatement(
                'DELETE FROM oci_cookie_category_translations WHERE language_id = ?',
                [(int) $inId],
            );
            $this->db->executeStatement(
                'DELETE FROM oci_site_languages WHERE language_id = ?',
                [(int) $inId],
            );
            $this->db->executeStatement(
                "DELETE FROM oci_languages WHERE lang_code = 'in'",
            );
        }

        // 2. Add missing languages (INSERT IGNORE to skip any that already exist)
        $languages = [
            // Indian languages
            ['bn', 'Bengali'],
            ['gu', 'Gujarati'],
            ['kn', 'Kannada'],
            ['ml', 'Malayalam'],
            ['mr', 'Marathi'],
            ['or', 'Odia'],
            ['pa', 'Punjabi'],
            ['te', 'Telugu'],
            ['ur', 'Urdu'],

            // Persian / Middle East
            ['fa', 'Persian'],
            ['ku', 'Kurdish'],
            ['ps', 'Pashto'],

            // African
            ['af', 'Afrikaans'],
            ['am', 'Amharic'],
            ['ha', 'Hausa'],
            ['ig', 'Igbo'],
            ['so', 'Somali'],
            ['sw', 'Swahili'],
            ['yo', 'Yoruba'],
            ['zu', 'Zulu'],

            // Turkic
            ['az', 'Azerbaijani'],
            ['kk', 'Kazakh'],
            ['ky', 'Kyrgyz'],
            ['uz', 'Uzbek'],

            // SE Asian
            ['km', 'Khmer'],
            ['lo', 'Lao'],
            ['my', 'Burmese'],
            ['ne', 'Nepali'],
            ['mn', 'Mongolian'],

            // European
            ['be', 'Belarusian'],
            ['hy', 'Armenian'],
            ['ka', 'Georgian'],
            ['lb', 'Luxembourgish'],
            ['nn', 'Norwegian Nynorsk'],

            // Other
            ['mg', 'Malagasy'],
            ['sd', 'Sindhi'],
        ];

        foreach ($languages as [$code, $name]) {
            $exists = $this->db->fetchOne(
                'SELECT id FROM oci_languages WHERE lang_code = ?',
                [$code],
            );

            if ($exists === false) {
                $this->db->insert('oci_languages', [
                    'lang_code' => $code,
                    'lang_name' => $name,
                    'is_default' => 0,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Re-add 'in' if needed
        $this->db->executeStatement(
            "INSERT IGNORE INTO oci_languages (lang_code, lang_name, is_default) VALUES ('in', 'India', 0)",
        );

        // Remove added languages
        $codes = [
            'bn', 'gu', 'kn', 'ml', 'mr', 'or', 'pa', 'te', 'ur',
            'fa', 'ku', 'ps',
            'af', 'am', 'ha', 'ig', 'so', 'sw', 'yo', 'zu',
            'az', 'kk', 'ky', 'uz',
            'km', 'lo', 'my', 'ne', 'mn',
            'be', 'hy', 'ka', 'lb', 'nn',
            'mg', 'sd',
        ];

        $placeholders = implode(',', array_fill(0, \count($codes), '?'));
        $this->db->executeStatement(
            "DELETE FROM oci_languages WHERE lang_code IN ({$placeholders})",
            $codes,
        );
    }
}
