<?php

declare(strict_types=1);

namespace OCI\Banner\Service;

use Doctrine\DBAL\Connection;
use OCI\Modules\Blog\Service\OpenRouterClient;
use Psr\Log\LoggerInterface;

/**
 * Seeds banner field translations for all supported languages.
 *
 * Uses OpenRouter AI to translate English defaults, caches results to a PHP
 * file (resources/data/banner-field-translations.php), and inserts them into
 * oci_banner_field_translations so copyDefaultBannerTranslations() works for
 * every language out of the box.
 */
final class BannerTranslationSeeder
{
    private const MODEL = 'google/gemini-2.0-flash-001';
    private const CACHE_FILE = 'resources/data/banner-field-translations.php';

    /** Fields that should not be translated (URLs, empty defaults). */
    private const SKIP_FIELDS = ['cookie_policy_url', 'google_privacy_url'];

    public function __construct(
        private readonly Connection $db,
        private readonly OpenRouterClient $ai,
        private readonly LoggerInterface $logger,
        private readonly string $basePath,
    ) {}

    /**
     * Get all translatable fields for a template, keyed by field_key.
     *
     * @return array<string, string> [field_key => default_value]
     */
    public function getTranslatableFields(string $templateSlug): array
    {
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT bf.field_key, bf.default_value
            FROM oci_banner_fields bf
            INNER JOIN oci_banner_field_categories bfc ON bfc.id = bf.field_category_id
            INNER JOIN oci_banner_templates bt ON bt.id = bfc.template_id
            WHERE bt.banner_slug = :slug
            ORDER BY bfc.sort_order, bf.sort_order
        SQL, ['slug' => $templateSlug]);

        $fields = [];
        foreach ($rows as $row) {
            $key = $row['field_key'];
            $val = trim($row['default_value'] ?? '');

            if ($val === '' || \in_array($key, self::SKIP_FIELDS, true)) {
                continue;
            }

            $fields[$key] = $val;
        }

        return $fields;
    }

    /**
     * Get all target languages (everything except English).
     *
     * @return array<string, string> [lang_code => lang_name]
     */
    public function getTargetLanguages(): array
    {
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT lang_code, lang_name
            FROM oci_languages
            WHERE lang_code != 'en'
            ORDER BY lang_name
        SQL);

        $languages = [];
        foreach ($rows as $row) {
            $languages[$row['lang_code']] = $row['lang_name'];
        }

        return $languages;
    }

    /**
     * Translate all fields for a single language using AI.
     *
     * @param array<string, string> $fields [field_key => english_default]
     * @return array<string, string>|null [field_key => translated_text] or null on failure
     */
    public function translateForLanguage(string $langCode, string $langName, array $fields): ?array
    {
        $fieldEntries = [];
        foreach ($fields as $key => $value) {
            $fieldEntries[] = "{$key}: {$value}";
        }

        $prompt = <<<PROMPT
Translate the following cookie consent banner UI texts from English to {$langName} ({$langCode}).
These are for a GDPR/CCPA cookie consent banner on a website.

Rules:
1. Return ONLY a JSON object mapping each field key to its translation
2. Preserve ALL HTML tags, IDs, classes, aria-label attributes, and {placeholders} exactly as-is
3. Only translate human-readable text content, not HTML attributes, URLs, or {placeholder} tokens
4. Use formal/polite register appropriate for legal consent text on a website
5. Keep translations concise for button labels (1-3 words)
6. Do not add explanations or comments

Fields:
PROMPT;
        $prompt .= implode("\n", $fieldEntries);

        $response = $this->ai->chatCompletion(
            model: self::MODEL,
            messages: [['role' => 'user', 'content' => $prompt]],
            temperature: 0.3,
            jsonMode: true,
            maxTokens: 8192,
        );

        if ($response === null || $response === '') {
            $this->logger->warning("Translation failed for {$langCode} ({$langName})");
            return null;
        }

        $translations = json_decode($response, true);
        if (!\is_array($translations)) {
            $this->logger->warning("Invalid JSON response for {$langCode}: {$response}");
            return null;
        }

        // Validate: ensure all expected keys are present
        $result = [];
        $fieldKeys = array_keys($fields);
        foreach ($fieldKeys as $key) {
            $result[$key] = isset($translations[$key]) ? trim((string) $translations[$key]) : '';
        }

        // Post-validate HTML fields: check that HTML tags are preserved
        foreach ($result as $key => $value) {
            if ($value === '') {
                continue;
            }
            $sourceHtml = $fields[$key] ?? '';
            if (str_contains($sourceHtml, '<button') || str_contains($sourceHtml, '<p>')) {
                if (preg_match_all('/<(button|p|\/p|\/button)[^>]*>/', $sourceHtml, $srcMatches)
                    && preg_match_all('/<(button|p|\/p|\/button)[^>]*>/', $value, $tgtMatches)) {
                    if (\count($srcMatches[0]) !== \count($tgtMatches[0])) {
                        $this->logger->warning("HTML mismatch for {$langCode}/{$key}, falling back to English");
                        $result[$key] = $sourceHtml;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Load cached translations from the PHP file.
     *
     * @return array<string, array<string, array<string, string>>>
     */
    public function loadCachedTranslations(): array
    {
        $path = $this->basePath . '/' . self::CACHE_FILE;

        if (!file_exists($path)) {
            return [];
        }

        $data = require $path;

        return \is_array($data) ? $data : [];
    }

    /**
     * Save translations to the cache file.
     *
     * @param array<string, array<string, array<string, string>>> $translations
     */
    public function saveCachedTranslations(array $translations): void
    {
        $path = $this->basePath . '/' . self::CACHE_FILE;

        $content = "<?php\n\n"
            . "// Auto-generated by: php bin/oci banners:seed-translations --generate\n"
            . "// Generated: " . date('Y-m-d\\TH:i:sP') . "\n"
            . "// Do not edit manually — re-run the command to regenerate.\n\n"
            . "return " . $this->exportArray($translations) . ";\n";

        file_put_contents($path, $content);
    }

    /**
     * Insert cached translations into oci_banner_field_translations.
     *
     * @param array<string, array<string, array<string, string>>> $translations
     * @return array{inserted: int, updated: int, skipped: int}
     */
    public function insertIntoDatabase(array $translations, bool $dryRun = false): array
    {
        $stats = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($translations as $templateSlug => $languages) {
            foreach ($languages as $langCode => $fields) {
                foreach ($fields as $fieldKey => $label) {
                    if ($label === '') {
                        $stats['skipped']++;
                        continue;
                    }

                    if ($dryRun) {
                        $stats['inserted']++;
                        continue;
                    }

                    $affected = $this->db->executeStatement(<<<'SQL'
                        INSERT INTO oci_banner_field_translations (field_id, language_id, label)
                        SELECT bf.id, l.id, :label
                        FROM oci_banner_fields bf
                        INNER JOIN oci_banner_field_categories bfc ON bfc.id = bf.field_category_id
                        INNER JOIN oci_banner_templates bt ON bt.id = bfc.template_id
                        CROSS JOIN oci_languages l
                        WHERE bf.field_key = :fieldKey
                          AND bt.banner_slug = :templateSlug
                          AND l.lang_code = :langCode
                        ON DUPLICATE KEY UPDATE label = VALUES(label)
                    SQL, [
                        'label' => $label,
                        'fieldKey' => $fieldKey,
                        'templateSlug' => $templateSlug,
                        'langCode' => $langCode,
                    ]);

                    if ($affected === 1) {
                        $stats['inserted']++;
                    } elseif ($affected === 2) {
                        $stats['updated']++;
                    } else {
                        $stats['skipped']++;
                    }
                }
            }
        }

        return $stats;
    }

    /**
     * Backfill existing site banners where the value still matches the English default.
     *
     * @param array<string, array<string, array<string, string>>> $translations
     * @return array{updated: int, skipped: int}
     */
    public function backfillSiteBanners(array $translations, bool $dryRun = false): array
    {
        $stats = ['updated' => 0, 'skipped' => 0];

        foreach ($translations as $templateSlug => $languages) {
            foreach ($languages as $langCode => $fields) {
                foreach ($fields as $fieldKey => $translatedValue) {
                    if ($translatedValue === '') {
                        continue;
                    }

                    if ($dryRun) {
                        $count = (int) $this->db->fetchOne(<<<'SQL'
                            SELECT COUNT(*)
                            FROM oci_site_banner_field_translations sbft
                            INNER JOIN oci_banner_fields bf ON bf.id = sbft.field_id
                            INNER JOIN oci_banner_field_categories bfc ON bfc.id = bf.field_category_id
                            INNER JOIN oci_banner_templates bt ON bt.id = bfc.template_id
                            INNER JOIN oci_languages l ON l.id = sbft.language_id
                            WHERE bf.field_key = :fieldKey
                              AND bt.banner_slug = :templateSlug
                              AND l.lang_code = :langCode
                              AND sbft.value = bf.default_value
                        SQL, [
                            'fieldKey' => $fieldKey,
                            'templateSlug' => $templateSlug,
                            'langCode' => $langCode,
                        ]);
                        $stats['updated'] += $count;
                        continue;
                    }

                    $affected = $this->db->executeStatement(<<<'SQL'
                        UPDATE oci_site_banner_field_translations sbft
                        INNER JOIN oci_banner_fields bf ON bf.id = sbft.field_id
                        INNER JOIN oci_banner_field_categories bfc ON bfc.id = bf.field_category_id
                        INNER JOIN oci_banner_templates bt ON bt.id = bfc.template_id
                        INNER JOIN oci_languages l ON l.id = sbft.language_id
                        SET sbft.value = :translatedValue
                        WHERE bf.field_key = :fieldKey
                          AND bt.banner_slug = :templateSlug
                          AND l.lang_code = :langCode
                          AND sbft.value = bf.default_value
                    SQL, [
                        'translatedValue' => $translatedValue,
                        'fieldKey' => $fieldKey,
                        'templateSlug' => $templateSlug,
                        'langCode' => $langCode,
                    ]);

                    $stats['updated'] += $affected;
                }
            }
        }

        return $stats;
    }

    /**
     * Get English cookie category data for translation.
     *
     * @return array<string, array{name: string, description: string}>
     */
    public function getCookieCategories(): array
    {
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT cc.slug, cct.name, cct.description
            FROM oci_cookie_categories cc
            INNER JOIN oci_cookie_category_translations cct ON cct.category_id = cc.id
            INNER JOIN oci_languages l ON l.id = cct.language_id
            WHERE l.lang_code = 'en'
            ORDER BY cc.sort_order
        SQL);

        $cats = [];
        foreach ($rows as $row) {
            $cats[$row['slug']] = [
                'name' => $row['name'],
                'description' => $row['description'],
            ];
        }

        return $cats;
    }

    /**
     * Get languages that are missing cookie category translations.
     *
     * @return array<string, string> [lang_code => lang_name]
     */
    public function getLanguagesMissingCategoryTranslations(): array
    {
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT l.lang_code, l.lang_name
            FROM oci_languages l
            WHERE l.lang_code != 'en'
              AND l.id NOT IN (SELECT DISTINCT language_id FROM oci_cookie_category_translations)
            ORDER BY l.lang_name
        SQL);

        $langs = [];
        foreach ($rows as $row) {
            $langs[$row['lang_code']] = $row['lang_name'];
        }

        return $langs;
    }

    /**
     * Translate cookie categories for a language.
     *
     * @param array<string, array{name: string, description: string}> $categories
     * @return array<string, array{name: string, description: string}>|null
     */
    public function translateCategoriesForLanguage(string $langCode, string $langName, array $categories): ?array
    {
        $fieldEntries = [];
        foreach ($categories as $slug => $data) {
            $fieldEntries[] = "{$slug}_name: {$data['name']}";
            $fieldEntries[] = "{$slug}_description: {$data['description']}";
        }

        $prompt = "Translate the following cookie category names and descriptions from English to {$langName} ({$langCode}).\n"
            . "These are for a GDPR cookie consent banner.\n\n"
            . "Rules:\n"
            . "1. Return ONLY a JSON object mapping each key to its translation\n"
            . "2. Use formal register appropriate for legal consent text\n"
            . "3. Keep category names concise (1-2 words)\n\n"
            . "Fields:\n" . implode("\n", $fieldEntries);

        $response = $this->ai->chatCompletion(
            model: self::MODEL,
            messages: [['role' => 'user', 'content' => $prompt]],
            temperature: 0.3,
            jsonMode: true,
            maxTokens: 4096,
        );

        if ($response === null || $response === '') {
            return null;
        }

        $translations = json_decode($response, true);
        if (!\is_array($translations)) {
            return null;
        }

        $result = [];
        foreach ($categories as $slug => $data) {
            $result[$slug] = [
                'name' => trim((string) ($translations["{$slug}_name"] ?? '')),
                'description' => trim((string) ($translations["{$slug}_description"] ?? '')),
            ];
        }

        return $result;
    }

    /**
     * Insert cookie category translations for a language.
     *
     * @param array<string, array{name: string, description: string}> $translations
     */
    public function insertCategoryTranslations(string $langCode, array $translations): int
    {
        $inserted = 0;

        foreach ($translations as $slug => $data) {
            if ($data['name'] === '' || $data['description'] === '') {
                continue;
            }

            $affected = $this->db->executeStatement(<<<'SQL'
                INSERT INTO oci_cookie_category_translations (category_id, language_id, name, description)
                SELECT cc.id, l.id, :name, :description
                FROM oci_cookie_categories cc
                CROSS JOIN oci_languages l
                WHERE cc.slug = :slug AND l.lang_code = :langCode
                  AND NOT EXISTS (
                    SELECT 1 FROM oci_cookie_category_translations cct
                    WHERE cct.category_id = cc.id AND cct.language_id = l.id
                  )
            SQL, [
                'name' => $data['name'],
                'description' => $data['description'],
                'slug' => $slug,
                'langCode' => $langCode,
            ]);

            $inserted += $affected;
        }

        return $inserted;
    }

    /**
     * Export a PHP array as a nicely formatted string.
     */
    private function exportArray(array $data, int $depth = 0): string
    {
        $indent = str_repeat('    ', $depth);
        $innerIndent = str_repeat('    ', $depth + 1);
        $lines = ["["];

        foreach ($data as $key => $value) {
            $exportedKey = var_export($key, true);

            if (\is_array($value)) {
                $lines[] = "{$innerIndent}{$exportedKey} => " . $this->exportArray($value, $depth + 1) . ",";
            } else {
                $exportedValue = var_export($value, true);
                $lines[] = "{$innerIndent}{$exportedKey} => {$exportedValue},";
            }
        }

        $lines[] = "{$indent}]";

        return implode("\n", $lines);
    }
}
