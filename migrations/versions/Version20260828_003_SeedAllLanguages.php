<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Seed every language the shipped translation caches cover.
 *
 * A migration-only install ended up with ~38 language rows while
 * resources/data/banner-field-translations.php ships real translations for 86
 * codes — and the seeders skip any language without an oci_languages row, so
 * Dutch, French, Norwegian Bokmål, Finnish, Swedish and others silently never
 * existed on a fresh self-hosted install. Cloud (legacy-imported) already has
 * these rows, so this is a near-no-op there.
 *
 * After migrating, run:
 *   php bin/oci banners:seed-translations --insert
 *   php bin/oci banners:seed-translations --categories
 *   php bin/oci cookies:translate
 */
final class Version20260828_003_SeedAllLanguages extends Migration
{
    private const LANGUAGES = [
        'af' => 'Afrikaans', 'am' => 'Amharic', 'ar' => 'Arabic', 'az' => 'Azerbaijani',
        'be' => 'Belarusian', 'bg' => 'Bulgarian', 'bn' => 'Bengali', 'bs' => 'Bosnian',
        'ca' => 'Catalan', 'cs' => 'Czech', 'cy' => 'Welsh', 'da' => 'Danish',
        'de' => 'German', 'el' => 'Greek', 'en' => 'English', 'es' => 'Spanish',
        'et' => 'Estonian', 'eu' => 'Basque', 'fa' => 'Persian', 'fi' => 'Finnish',
        'fr' => 'French', 'ga' => 'Irish', 'gl' => 'Galician', 'gu' => 'Gujarati',
        'ha' => 'Hausa', 'he' => 'Hebrew', 'hi' => 'Hindi', 'hr' => 'Croatian',
        'hu' => 'Hungarian', 'hy' => 'Armenian', 'id' => 'Indonesian', 'ig' => 'Igbo',
        'is' => 'Icelandic', 'it' => 'Italian', 'ja' => 'Japanese', 'ka' => 'Georgian',
        'kk' => 'Kazakh', 'km' => 'Khmer', 'kn' => 'Kannada', 'ko' => 'Korean',
        'ku' => 'Kurdish', 'ky' => 'Kyrgyz', 'lb' => 'Luxembourgish', 'lo' => 'Lao',
        'lt' => 'Lithuanian', 'lv' => 'Latvian', 'mg' => 'Malagasy', 'mk' => 'Macedonian',
        'ml' => 'Malayalam', 'mn' => 'Mongolian', 'mr' => 'Marathi', 'ms' => 'Malay',
        'mt' => 'Maltese', 'my' => 'Burmese', 'nb' => 'Norwegian Bokmål', 'ne' => 'Nepali',
        'nl' => 'Dutch', 'nn' => 'Norwegian Nynorsk', 'or' => 'Odia', 'pa' => 'Punjabi',
        'pl' => 'Polish', 'ps' => 'Pashto', 'pt' => 'Portuguese', 'ro' => 'Romanian',
        'ru' => 'Russian', 'sd' => 'Sindhi', 'si' => 'Sinhala', 'sk' => 'Slovak',
        'sl' => 'Slovenian', 'so' => 'Somali', 'sq' => 'Albanian', 'sr' => 'Serbian',
        'sv' => 'Swedish', 'sw' => 'Swahili', 'ta' => 'Tamil', 'te' => 'Telugu',
        'th' => 'Thai', 'tl' => 'Filipino', 'tr' => 'Turkish', 'uk' => 'Ukrainian',
        'ur' => 'Urdu', 'uz' => 'Uzbek', 'vi' => 'Vietnamese', 'yo' => 'Yoruba',
        'zh' => 'Chinese', 'zu' => 'Zulu',
    ];

    public function getDescription(): string
    {
        return 'Seed all 86 languages the shipped translation caches cover (fresh installs had ~38)';
    }

    public function up(): void
    {
        foreach (self::LANGUAGES as $code => $name) {
            $this->db->executeStatement(
                'INSERT INTO oci_languages (lang_code, lang_name, is_default)
                 SELECT :code, :name, 0
                 WHERE NOT EXISTS (SELECT 1 FROM oci_languages WHERE lang_code = :code2)',
                ['code' => $code, 'name' => $name, 'code2' => $code],
            );
        }

        // Guarantee a system default exists (fresh installs default to English)
        $hasDefault = $this->db->fetchOne('SELECT COUNT(*) FROM oci_languages WHERE is_default = 1');
        if ((int) $hasDefault === 0) {
            $this->db->executeStatement("UPDATE oci_languages SET is_default = 1 WHERE lang_code = 'en' LIMIT 1");
        }
    }

    public function down(): void
    {
        // Deliberately empty: removing languages would cascade away real
        // translations and site language assignments.
    }
}
