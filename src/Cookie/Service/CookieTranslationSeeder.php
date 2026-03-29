<?php

declare(strict_types=1);

namespace OCI\Cookie\Service;

use Doctrine\DBAL\Connection;
use OCI\Modules\Blog\Service\OpenRouterClient;
use Psr\Log\LoggerInterface;

/**
 * Translates oci_cookies_global descriptions into all supported languages.
 *
 * Uses OpenRouter AI (Gemini Flash) to translate English cookie descriptions,
 * caches results to a PHP file, and inserts into oci_cookies_global_translations.
 */
final class CookieTranslationSeeder
{
    private const MODEL = 'google/gemini-2.0-flash-001';
    private const CACHE_FILE = 'resources/data/cookie-global-translations.php';
    private const BATCH_SIZE = 30;

    public function __construct(
        private readonly Connection $db,
        private readonly OpenRouterClient $ai,
        private readonly LoggerInterface $logger,
        private readonly string $basePath,
    ) {}

    /**
     * Get all cookies with non-empty English descriptions.
     *
     * @return array<int, string> [cookie_id => description]
     */
    public function getTranslatableCookies(): array
    {
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT id, description
            FROM oci_cookies_global
            WHERE description IS NOT NULL AND description != ''
            ORDER BY id
        SQL);

        $cookies = [];
        foreach ($rows as $row) {
            $cookies[(int) $row['id']] = trim($row['description']);
        }

        return $cookies;
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
     * Translate a batch of cookie descriptions for a single language.
     *
     * @param array<int, string> $descriptions [cookie_id => english_description]
     * @return array<int, string>|null [cookie_id => translated_description]
     */
    public function translateBatch(string $langCode, string $langName, array $descriptions): ?array
    {
        $fieldEntries = [];
        foreach ($descriptions as $id => $desc) {
            $fieldEntries[] = "{$id}: {$desc}";
        }

        $prompt = <<<PROMPT
Translate the following cookie descriptions from English to {$langName} ({$langCode}).
These are cookie descriptions for a GDPR cookie consent banner on a website.

Rules:
1. Return ONLY a JSON object mapping each numeric ID (as a string key) to its translated description
2. Use formal register appropriate for privacy/legal context on a website
3. Keep translations concise — roughly the same length as the English original
4. Do not add explanations or comments
5. Translate ALL entries, do not skip any

Descriptions:
PROMPT;
        $prompt .= implode("\n", $fieldEntries);

        $response = $this->ai->chatCompletion(
            model: self::MODEL,
            messages: [['role' => 'user', 'content' => $prompt]],
            temperature: 0.3,
            jsonMode: true,
            maxTokens: 16384,
        );

        if ($response === null || $response === '') {
            $this->logger->warning("Cookie translation failed for {$langCode} ({$langName})");
            return null;
        }

        $translations = json_decode($response, true);
        if (!\is_array($translations)) {
            $this->logger->warning("Invalid JSON response for cookie translations ({$langCode}): {$response}");
            return null;
        }

        $result = [];
        foreach ($descriptions as $id => $desc) {
            $translated = trim((string) ($translations[(string) $id] ?? ''));
            if ($translated !== '') {
                $result[$id] = $translated;
            }
        }

        return $result;
    }

    /**
     * Generate translations for all languages (or a single one), using batches.
     *
     * @return array<string, array<int, string>> [lang_code => [cookie_id => description]]
     */
    public function generateTranslations(
        ?string $singleLang = null,
        bool $force = false,
        int $delaySeconds = 2,
        ?\Closure $onProgress = null,
    ): array {
        $cookies = $this->getTranslatableCookies();
        if (empty($cookies)) {
            return [];
        }

        $languages = $singleLang !== null
            ? array_filter($this->getTargetLanguages(), fn($code) => $code === $singleLang, ARRAY_FILTER_USE_KEY)
            : $this->getTargetLanguages();

        $cached = $this->loadCachedTranslations();
        $batches = array_chunk($cookies, self::BATCH_SIZE, true);

        $totalLangs = \count($languages);
        $totalBatches = \count($batches);
        $currentLang = 0;

        foreach ($languages as $langCode => $langName) {
            $currentLang++;

            if (!$force && isset($cached[$langCode]) && \count($cached[$langCode]) >= \count($cookies) * 0.9) {
                $onProgress?->call($this, $langCode, $langName, 'skipped (cached)');
                continue;
            }

            $onProgress?->call($this, $langCode, $langName, "starting [{$currentLang}/{$totalLangs}]");

            $langResult = $cached[$langCode] ?? [];
            $batchNum = 0;
            $batchErrors = 0;

            foreach ($batches as $batch) {
                $batchNum++;

                // Skip cookies already translated (unless force)
                if (!$force) {
                    $batch = array_diff_key($batch, $langResult);
                    if (empty($batch)) {
                        continue;
                    }
                }

                $onProgress?->call($this, $langCode, $langName, "  batch {$batchNum}/{$totalBatches} (" . \count($batch) . " cookies)");

                $translated = $this->translateBatch($langCode, $langName, $batch);
                if ($translated !== null) {
                    $langResult = array_replace($langResult, $translated);
                } else {
                    $batchErrors++;
                }

                if ($batchNum < \count($batches) && $delaySeconds > 0) {
                    sleep($delaySeconds);
                }
            }

            $cached[$langCode] = $langResult;
            $errMsg = $batchErrors > 0 ? ", {$batchErrors} batch errors" : '';
            $onProgress?->call($this, $langCode, $langName, "done: " . \count($langResult) . " cookies{$errMsg}");

            // Save after each language to avoid losing progress
            $this->saveCachedTranslations($cached);

            if ($delaySeconds > 0) {
                sleep($delaySeconds);
            }
        }

        return $cached;
    }

    /**
     * Insert cached translations into oci_cookies_global_translations.
     *
     * @param array<string, array<int, string>> $translations
     * @return array{inserted: int, updated: int, skipped: int}
     */
    public function insertIntoDatabase(array $translations, bool $dryRun = false): array
    {
        $stats = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($translations as $langCode => $cookies) {
            foreach ($cookies as $cookieId => $description) {
                if ($description === '') {
                    $stats['skipped']++;
                    continue;
                }

                if ($dryRun) {
                    $stats['inserted']++;
                    continue;
                }

                $affected = $this->db->executeStatement(<<<'SQL'
                    INSERT INTO oci_cookies_global_translations (cookie_id, language_id, description)
                    SELECT :cookieId, l.id, :description
                    FROM oci_languages l
                    WHERE l.lang_code = :langCode
                    ON DUPLICATE KEY UPDATE description = VALUES(description)
                SQL, [
                    'cookieId' => $cookieId,
                    'description' => $description,
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

        return $stats;
    }

    /**
     * @return array<string, array<int, string>>
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
     * @param array<string, array<int, string>> $translations
     */
    public function saveCachedTranslations(array $translations): void
    {
        $path = $this->basePath . '/' . self::CACHE_FILE;

        $content = "<?php\n\n"
            . "// Auto-generated by: php bin/oci cookies:translate --generate\n"
            . "// Generated: " . date('Y-m-d\\TH:i:sP') . "\n"
            . "// Do not edit manually — re-run the command to regenerate.\n\n"
            . "return " . $this->exportArray($translations) . ";\n";

        file_put_contents($path, $content);
    }

    private function exportArray(array $data, int $depth = 0): string
    {
        $indent = str_repeat('    ', $depth);
        $innerIndent = str_repeat('    ', $depth + 1);
        $lines = ['['];

        foreach ($data as $key => $value) {
            $exportedKey = var_export($key, true);

            if (\is_array($value)) {
                $lines[] = "{$innerIndent}{$exportedKey} => " . $this->exportArray($value, $depth + 1) . ',';
            } else {
                $exportedValue = var_export($value, true);
                $lines[] = "{$innerIndent}{$exportedKey} => {$exportedValue},";
            }
        }

        $lines[] = "{$indent}]";

        return implode("\n", $lines);
    }
}
