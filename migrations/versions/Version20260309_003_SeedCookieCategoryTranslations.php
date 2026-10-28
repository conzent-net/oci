<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Seed cookie category translations for English and Danish.
 *
 * The oci_cookie_categories table has 7 default categories but was missing
 * translations in oci_cookie_category_translations, causing the consent
 * script's cookieTypes to be empty (no categories displayed in the banner).
 *
 * Looks up actual category and language IDs from the database rather than
 * hardcoding them, since legacy import may produce different auto-increment IDs.
 */
final class Version20260309_003_SeedCookieCategoryTranslations extends Migration
{
    public function getDescription(): string
    {
        return 'Seed English and Danish cookie category translations';
    }

    public function up(): void
    {
        // Only insert if translations don't already exist
        $count = (int) $this->db->fetchOne('SELECT COUNT(*) FROM oci_cookie_category_translations');
        if ($count > 0) {
            return;
        }

        // Look up actual category IDs by slug
        $categories = $this->db->fetchAllAssociative(
            'SELECT id, slug FROM oci_cookie_categories ORDER BY sort_order ASC'
        );
        $catMap = [];
        foreach ($categories as $cat) {
            $catMap[$cat['slug']] = (int) $cat['id'];
        }

        if (empty($catMap)) {
            // No categories exist — seed them first
            $defaultCats = [
                ['slug' => 'necessary', 'type' => 1, 'sort_order' => 1, 'is_active' => 1, 'default_consent' => 'accepted'],
                ['slug' => 'functional', 'type' => 2, 'sort_order' => 2, 'is_active' => 1, 'default_consent' => null],
                ['slug' => 'preferences', 'type' => 2, 'sort_order' => 3, 'is_active' => 1, 'default_consent' => null],
                ['slug' => 'analytics', 'type' => 3, 'sort_order' => 4, 'is_active' => 1, 'default_consent' => null],
                ['slug' => 'performance', 'type' => 2, 'sort_order' => 5, 'is_active' => 1, 'default_consent' => null],
                ['slug' => 'marketing', 'type' => 4, 'sort_order' => 6, 'is_active' => 1, 'default_consent' => null],
                ['slug' => 'unclassified', 'type' => 2, 'sort_order' => 7, 'is_active' => 1, 'default_consent' => null],
            ];
            foreach ($defaultCats as $dc) {
                $this->db->insert('oci_cookie_categories', $dc);
                $catMap[$dc['slug']] = (int) $this->db->lastInsertId();
            }
        }

        // Look up actual language IDs by code
        $languages = $this->db->fetchAllAssociative(
            'SELECT id, lang_code FROM oci_languages WHERE lang_code IN (\'en\', \'da\')'
        );
        $langMap = [];
        foreach ($languages as $lang) {
            $langMap[$lang['lang_code']] = (int) $lang['id'];
        }

        if (empty($langMap)) {
            // No languages exist — seed English and Danish
            $this->db->insert('oci_languages', ['lang_code' => 'en', 'lang_name' => 'English', 'is_default' => 1]);
            $langMap['en'] = (int) $this->db->lastInsertId();
            $this->db->insert('oci_languages', ['lang_code' => 'da', 'lang_name' => 'Dansk', 'is_default' => 0]);
            $langMap['da'] = (int) $this->db->lastInsertId();
        }

        // Translation data: slug => [en_name, en_desc, da_name, da_desc]
        $translations = [
            'necessary' => [
                'Necessary',
                'Necessary cookies are essential for the website to function properly. These cookies ensure basic functionalities and security features of the website.',
                'Nødvendige',
                'Nødvendige cookies er essentielle for at hjemmesiden kan fungere korrekt. Disse cookies sikrer grundlæggende funktionaliteter og sikkerhedsfunktioner på hjemmesiden.',
            ],
            'functional' => [
                'Functional',
                'Functional cookies help to perform certain functionalities like sharing the content of the website on social media platforms, collect feedbacks, and other third-party features.',
                'Funktionelle',
                'Funktionelle cookies hjælper med at udføre visse funktioner som deling af hjemmesidens indhold på sociale medier, indsamling af feedback og andre tredjepartsfunktioner.',
            ],
            'preferences' => [
                'Preferences',
                'Preference cookies are used to store user preferences to provide content that is customized and convenient for the users, like the language of the website or the location of the visitor.',
                'Præferencer',
                'Præference-cookies bruges til at gemme brugerpræferencer for at levere indhold, der er tilpasset og bekvemt for brugerne, såsom hjemmesidens sprog eller den besøgendes placering.',
            ],
            'analytics' => [
                'Analytics',
                'Analytical cookies are used to understand how visitors interact with the website. These cookies help provide information on metrics such as the number of visitors, bounce rate, traffic source, etc.',
                'Analyse',
                'Analytiske cookies bruges til at forstå, hvordan besøgende interagerer med hjemmesiden. Disse cookies hjælper med at give information om målinger som antal besøgende, afvisningsprocent, trafikkilde osv.',
            ],
            'performance' => [
                'Performance',
                'Performance cookies are used to understand and analyze the key performance indexes of the website which helps in delivering a better user experience for the visitors.',
                'Ydeevne',
                'Ydeevne-cookies bruges til at forstå og analysere hjemmesidens vigtigste præstationsindekser, hvilket hjælper med at levere en bedre brugeroplevelse for de besøgende.',
            ],
            'marketing' => [
                'Marketing',
                'Marketing cookies are used to provide visitors with relevant ads and marketing campaigns. These cookies track visitors across websites and collect information to provide customized ads.',
                'Marketing',
                'Marketing-cookies bruges til at give besøgende relevante annoncer og marketingkampagner. Disse cookies sporer besøgende på tværs af hjemmesider og indsamler information for at levere tilpassede annoncer.',
            ],
            'unclassified' => [
                'Unclassified',
                'Unclassified cookies are cookies that we are in the process of classifying, together with the providers of individual cookies.',
                'Uklassificerede',
                'Uklassificerede cookies er cookies, som vi er i gang med at klassificere sammen med udbyderne af de enkelte cookies.',
            ],
        ];

        foreach ($translations as $slug => [$enName, $enDesc, $daName, $daDesc]) {
            $catId = $catMap[$slug] ?? null;
            if ($catId === null) {
                continue;
            }

            // English translation
            if (isset($langMap['en'])) {
                $exists = $this->db->fetchOne(
                    'SELECT id FROM oci_cookie_category_translations WHERE category_id = ? AND language_id = ?',
                    [$catId, $langMap['en']],
                );
                if ($exists === false) {
                    $this->db->insert('oci_cookie_category_translations', [
                        'category_id' => $catId,
                        'language_id' => $langMap['en'],
                        'name' => $enName,
                        'description' => $enDesc,
                    ]);
                }
            }

            // Danish translation
            if (isset($langMap['da'])) {
                $exists = $this->db->fetchOne(
                    'SELECT id FROM oci_cookie_category_translations WHERE category_id = ? AND language_id = ?',
                    [$catId, $langMap['da']],
                );
                if ($exists === false) {
                    $this->db->insert('oci_cookie_category_translations', [
                        'category_id' => $catId,
                        'language_id' => $langMap['da'],
                        'name' => $daName,
                        'description' => $daDesc,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $this->sql('DELETE FROM oci_cookie_category_translations');
    }
}
