<?php

declare(strict_types=1);

namespace OCI\Identity\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use RuntimeException;
use ZipArchive;

/**
 * Imports a conzent-account-export/2 zip into this installation — the other
 * half of "your export restores 1:1 into a self-hosted install". Open core.
 *
 * Shared reference ids (languages, cookie categories, banner templates and
 * fields) differ between installations, so rows are remapped by natural
 * keys using the reference/ tables the export carries: languages by
 * lang_code, categories by slug, templates by banner_slug, fields by
 * (banner_slug, field_key). Missing languages/categories are created;
 * missing banner templates abort (they are seeded structure).
 *
 * Failure safety: the user row is created first and every imported row
 * hangs off it through ON DELETE CASCADE — on any error the user is
 * deleted and the database returns to its prior state.
 *
 * Deliberately not imported: scan history (oci_scans/oci_scan_cookies —
 * tied to the source install's scan infrastructure) and A/B variant ids
 * (cloud-module data; variant references are cleared to NULL).
 */
final class AccountImportService
{
    private const CHUNK = 2000;
    private const CONSENT_MAP_TABLE = '_oci_import_consent_map';

    /** @var array<string, array<string, bool>> */
    private array $columnCache = [];

    /** @var array<string, int> */
    private array $counts = [];

    /** @var list<string> */
    private array $notes = [];

    public function __construct(
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{user_id: int, email: string, sites: array<int, int>, counts: array<string, int>, notes: list<string>}
     */
    public function import(string $zipPath, ?string $newEmail = null): array
    {
        $this->counts = [];
        $this->notes = [];

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("Cannot open {$zipPath}");
        }

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        if (!\is_array($manifest) || ($manifest['format'] ?? '') !== 'conzent-account-export/2') {
            throw new RuntimeException(
                'Unsupported export format "' . ($manifest['format'] ?? 'none') . '" — generate a fresh export (format conzent-account-export/2, which carries the reference tables an import needs).',
            );
        }

        // ── User first: everything cascades from this row on failure ─────
        $userRow = $this->readSingleRow($zip, 'oci_users.jsonl');
        if ($userRow === null) {
            throw new RuntimeException('Export contains no user row');
        }

        $email = $newEmail ?? (string) ($userRow['email'] ?? '');
        if ($email === '') {
            throw new RuntimeException('No email in export and none given via --new-email');
        }
        if ($this->db->fetchOne('SELECT id FROM oci_users WHERE email = :e', ['e' => $email]) !== false) {
            throw new RuntimeException("A user with email {$email} already exists — pass --new-email=<address> to import under a different one.");
        }

        unset($userRow['id'], $userRow['password'], $userRow['remember_token'], $userRow['remember_token_hash']);
        $userRow['email'] = $email;
        // username carries its own unique constraint and is email-shaped in
        // this schema — keep it aligned with the (possibly remapped) email.
        if (\array_key_exists('username', $userRow)) {
            $userRow['username'] = $email;
        }
        $userRow['password'] = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);
        if (($userRow['role'] ?? '') === 'admin') {
            $userRow['role'] = 'user';
            $this->notes[] = 'The exported account had the admin role; imported as a regular user. Promote deliberately if intended.';
        }
        $this->notes[] = "The password was NOT imported. Set one with: php bin/oci user:password --email={$email}";

        $this->db->insert('oci_users', $this->onlyExistingColumns('oci_users', $userRow));
        $newUserId = (int) $this->db->lastInsertId();
        $this->counts['oci_users'] = 1;

        try {
            $result = $this->importEverything($zip, $manifest, $newUserId);
        } catch (\Throwable $e) {
            $this->db->executeStatement('DELETE FROM oci_users WHERE id = :id', ['id' => $newUserId]);
            $this->db->executeStatement('DROP TEMPORARY TABLE IF EXISTS ' . self::CONSENT_MAP_TABLE);
            $this->logger->error('Account import failed and was rolled back', ['error' => $e->getMessage()]);
            throw new RuntimeException('Import failed and was fully rolled back (cascade delete): ' . $e->getMessage(), 0, $e);
        }

        $zip->close();

        return [
            'user_id' => $newUserId,
            'email' => $email,
            'sites' => $result,
            'counts' => $this->counts,
            'notes' => $this->notes,
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<int, int> old site id => new site id
     */
    private function importEverything(ZipArchive $zip, array $manifest, int $newUserId): array
    {
        // ── Reference maps (old source id → this install's id) ───────────
        $langMap = $this->buildLanguageMap($zip);
        $catMap = $this->buildCategoryMap($zip);
        [$templateMap, $fieldMap] = $this->buildBannerMaps($zip);

        // ── Companies ────────────────────────────────────────────────────
        $this->importTable($zip, 'oci_user_companies', ['user_id' => [null => $newUserId]], []);

        // ── Sites ────────────────────────────────────────────────────────
        $siteMap = [];
        foreach ($this->rows($zip, 'oci_sites.jsonl') as $row) {
            $oldId = (int) $row['id'];
            $domain = (string) ($row['domain'] ?? '');
            $key = (string) ($row['website_key'] ?? '');

            if ($this->db->fetchOne('SELECT id FROM oci_sites WHERE domain = :d AND deleted_at IS NULL', ['d' => $domain]) !== false) {
                throw new RuntimeException("Site domain already exists here: {$domain}");
            }
            if ($key !== '' && $this->db->fetchOne('SELECT id FROM oci_sites WHERE website_key = :k', ['k' => $key]) !== false) {
                throw new RuntimeException("Website key already exists here: {$key} ({$domain})");
            }

            unset($row['id']);
            $row['user_id'] = $newUserId;
            $this->db->insert('oci_sites', $this->onlyExistingColumns('oci_sites', $row));
            $siteMap[$oldId] = (int) $this->db->lastInsertId();
            $this->counts['oci_sites'] = ($this->counts['oci_sites'] ?? 0) + 1;
        }
        $this->notes[] = 'Website keys were preserved — existing embed snippets keep working once they point at this installation.';

        // ── Site children (simple site_id remap + reference remaps) ──────
        $this->importTable($zip, 'oci_associated_sites', ['site_id' => $siteMap], []);
        $this->importTable($zip, 'oci_site_urls', ['site_id' => $siteMap], []);
        $this->importTable($zip, 'oci_site_languages', ['site_id' => $siteMap, 'language_id' => $langMap], []);
        $sccMap = $this->importTable($zip, 'oci_site_cookie_categories', ['site_id' => $siteMap, 'category_id' => $catMap], []);
        $this->importTable($zip, 'oci_site_cookie_category_translations', ['site_cookie_category_id' => $sccMap, 'language_id' => $langMap], []);
        $cookieMap = $this->importTable($zip, 'oci_site_cookies', ['site_id' => $siteMap, 'category_id' => $catMap], ['category_id']);
        $this->importTable($zip, 'oci_site_cookie_translations', ['site_cookie_id' => $cookieMap, 'language_id' => $langMap], []);
        $layoutMap = $this->importTable($zip, 'oci_custom_layouts', ['site_id' => $siteMap], []);
        $this->importTable($zip, 'oci_site_privacy_frameworks', ['site_id' => $siteMap], []);
        $this->importTable($zip, 'oci_cookie_policies', ['site_id' => $siteMap], []);
        $this->importTable($zip, 'oci_privacy_policies', ['site_id' => $siteMap], []);

        // ── Banners and their children ───────────────────────────────────
        $bannerMap = $this->importTable($zip, 'oci_site_banners', [
            'site_id' => $siteMap,
            'banner_template_id' => $templateMap,
            'custom_layout_id' => $layoutMap,
        ], ['custom_layout_id']);
        $this->importTable($zip, 'oci_site_banner_settings', ['site_banner_id' => $bannerMap], []);
        $this->importTable($zip, 'oci_site_banner_field_translations', [
            'site_banner_id' => $bannerMap,
            'field_id' => $fieldMap,
            'language_id' => $langMap,
        ], []);

        // ── Consent trail (chunked, with a DB-side id map) ───────────────
        $this->importConsents($zip, $siteMap);
        $this->importTable($zip, 'oci_consent_daily_stats', ['site_id' => $siteMap, 'variant_id' => []], ['variant_id']);
        $this->importTable($zip, 'oci_cookie_observations', ['site_id' => $siteMap], []);

        if (($manifest['tables']['oci_scans'] ?? 0) > 0) {
            $this->notes[] = 'Scan history was not imported — it is tied to the source installation\'s scan infrastructure. Run a fresh scan here.';
        }

        return $siteMap;
    }

    /**
     * Generic streamed table import with column remapping.
     *
     * $remaps maps a column to an old→new id map. A remap of [null => X]
     * forces the value X. If the mapped value is missing: columns listed in
     * $nullableOnMiss are set NULL, anything else skips the row (counted).
     *
     * @param array<string, array<int|string|null, int>|array<int, int>> $remaps
     * @param list<string> $nullableOnMiss
     * @return array<int, int> old row id => new row id
     */
    private function importTable(ZipArchive $zip, string $table, array $remaps, array $nullableOnMiss): array
    {
        if (!$this->tableExists($table)) {
            return [];
        }

        $map = [];
        $skipped = 0;

        foreach ($this->rows($zip, $table . '.jsonl') as $row) {
            $oldId = (int) ($row['id'] ?? 0);
            unset($row['id']);

            $skip = false;
            foreach ($remaps as $column => $idMap) {
                if (!\array_key_exists($column, $row)) {
                    continue;
                }
                if (isset($idMap[null])) {
                    $row[$column] = $idMap[null];
                    continue;
                }
                $old = $row[$column];
                if ($old === null || $old === '') {
                    continue;
                }
                $new = $idMap[(int) $old] ?? null;
                if ($new !== null) {
                    $row[$column] = $new;
                } elseif (\in_array($column, $nullableOnMiss, true)) {
                    $row[$column] = null;
                } else {
                    $skip = true;
                    break;
                }
            }

            if ($skip) {
                $skipped++;
                continue;
            }

            $this->db->insert($table, $this->onlyExistingColumns($table, $row));
            if ($oldId > 0) {
                $map[$oldId] = (int) $this->db->lastInsertId();
            }
            $this->counts[$table] = ($this->counts[$table] ?? 0) + 1;
        }

        if ($skipped > 0) {
            $this->notes[] = "{$table}: {$skipped} row(s) skipped (unmappable reference id).";
        }

        return $map;
    }

    /**
     * Consents can be millions of rows: the old→new id map goes into a
     * temporary table instead of PHP memory, and consent categories join
     * against it chunk by chunk.
     *
     * @param array<int, int> $siteMap
     */
    private function importConsents(ZipArchive $zip, array $siteMap): void
    {
        if (!$this->tableExists('oci_consents')) {
            return;
        }

        $this->db->executeStatement('DROP TEMPORARY TABLE IF EXISTS ' . self::CONSENT_MAP_TABLE);
        $this->db->executeStatement(
            'CREATE TEMPORARY TABLE ' . self::CONSENT_MAP_TABLE . ' (old_id BIGINT UNSIGNED PRIMARY KEY, new_id BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB',
        );

        foreach ($this->rows($zip, 'oci_consents.jsonl') as $row) {
            $oldId = (int) $row['id'];
            unset($row['id']);
            $row['site_id'] = $siteMap[(int) $row['site_id']] ?? null;
            if ($row['site_id'] === null) {
                continue;
            }
            if (\array_key_exists('variant_id', $row)) {
                $row['variant_id'] = null;
            }

            $this->db->insert('oci_consents', $this->onlyExistingColumns('oci_consents', $row));
            $this->db->insert(self::CONSENT_MAP_TABLE, ['old_id' => $oldId, 'new_id' => (int) $this->db->lastInsertId()]);
            $this->counts['oci_consents'] = ($this->counts['oci_consents'] ?? 0) + 1;
        }

        // Categories: resolve new consent ids in chunks against the map table.
        $buffer = [];
        $flush = function () use (&$buffer): void {
            if ($buffer === []) {
                return;
            }
            $oldIds = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['consent_id'], $buffer)));
            $pairs = $this->db->fetchAllKeyValue(
                'SELECT old_id, new_id FROM ' . self::CONSENT_MAP_TABLE . ' WHERE old_id IN (:ids)',
                ['ids' => $oldIds],
                ['ids' => ArrayParameterType::INTEGER],
            );
            foreach ($buffer as $row) {
                $new = $pairs[(int) $row['consent_id']] ?? null;
                if ($new === null) {
                    continue;
                }
                unset($row['id']);
                $row['consent_id'] = (int) $new;
                $this->db->insert('oci_consent_categories', $this->onlyExistingColumns('oci_consent_categories', $row));
                $this->counts['oci_consent_categories'] = ($this->counts['oci_consent_categories'] ?? 0) + 1;
            }
            $buffer = [];
        };

        foreach ($this->rows($zip, 'oci_consent_categories.jsonl') as $row) {
            $buffer[] = $row;
            if (\count($buffer) >= self::CHUNK) {
                $flush();
            }
        }
        $flush();

        $this->db->executeStatement('DROP TEMPORARY TABLE IF EXISTS ' . self::CONSENT_MAP_TABLE);
    }

    // ── Reference maps ───────────────────────────────────────────────────

    /** @return array<int, int> */
    private function buildLanguageMap(ZipArchive $zip): array
    {
        $target = $this->db->fetchAllKeyValue('SELECT lang_code, id FROM oci_languages');
        $map = [];
        foreach ($this->rows($zip, 'reference/oci_languages.jsonl') as $row) {
            $code = (string) $row['lang_code'];
            if (!isset($target[$code])) {
                $this->db->insert('oci_languages', ['lang_code' => $code, 'lang_name' => (string) ($row['lang_name'] ?? strtoupper($code)), 'is_default' => 0]);
                $target[$code] = (int) $this->db->lastInsertId();
                $this->notes[] = "Language '{$code}' was missing here and has been created.";
            }
            $map[(int) $row['id']] = (int) $target[$code];
        }

        return $map;
    }

    /** @return array<int, int> */
    private function buildCategoryMap(ZipArchive $zip): array
    {
        $target = $this->db->fetchAllKeyValue('SELECT slug, id FROM oci_cookie_categories');
        $map = [];
        foreach ($this->rows($zip, 'reference/oci_cookie_categories.jsonl') as $row) {
            $slug = (string) $row['slug'];
            if (!isset($target[$slug])) {
                $insert = $row;
                unset($insert['id']);
                $this->db->insert('oci_cookie_categories', $this->onlyExistingColumns('oci_cookie_categories', $insert));
                $target[$slug] = (int) $this->db->lastInsertId();
                $this->notes[] = "Cookie category '{$slug}' was missing here and has been created.";
            }
            $map[(int) $row['id']] = (int) $target[$slug];
        }

        return $map;
    }

    /**
     * @return array{0: array<int, int>, 1: array<int, int>} [templateMap, fieldMap]
     */
    private function buildBannerMaps(ZipArchive $zip): array
    {
        $targetTemplates = $this->db->fetchAllKeyValue('SELECT banner_slug, id FROM oci_banner_templates');

        $templateMap = [];
        $sourceTemplateSlug = [];
        foreach ($this->rows($zip, 'reference/oci_banner_templates.jsonl') as $row) {
            $slug = (string) $row['banner_slug'];
            $sourceTemplateSlug[(int) $row['id']] = $slug;
            if (!isset($targetTemplates[$slug])) {
                throw new RuntimeException("Banner template '{$slug}' does not exist on this installation — run migrations first.");
            }
            $templateMap[(int) $row['id']] = (int) $targetTemplates[$slug];
        }

        // Source: field id → (template slug, field key)
        $sourceCatTemplate = [];
        foreach ($this->rows($zip, 'reference/oci_banner_field_categories.jsonl') as $row) {
            $sourceCatTemplate[(int) $row['id']] = (int) ($row['template_id'] ?? 0);
        }

        // Target: (template slug, field key) → field id
        $targetFields = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT bf.id, bf.field_key, bt.banner_slug
             FROM oci_banner_fields bf
             INNER JOIN oci_banner_field_categories bfc ON bfc.id = bf.field_category_id
             INNER JOIN oci_banner_templates bt ON bt.id = bfc.template_id',
        ) as $row) {
            $targetFields[$row['banner_slug'] . '|' . $row['field_key']] = (int) $row['id'];
        }

        $fieldMap = [];
        foreach ($this->rows($zip, 'reference/oci_banner_fields.jsonl') as $row) {
            $templateId = $sourceCatTemplate[(int) ($row['field_category_id'] ?? 0)] ?? 0;
            $slug = $sourceTemplateSlug[$templateId] ?? '';
            $new = $targetFields[$slug . '|' . (string) $row['field_key']] ?? null;
            if ($new !== null) {
                $fieldMap[(int) $row['id']] = $new;
            }
        }

        return [$templateMap, $fieldMap];
    }

    // ── Plumbing ─────────────────────────────────────────────────────────

    /**
     * @return \Generator<array<string, mixed>>
     */
    private function rows(ZipArchive $zip, string $name): \Generator
    {
        $stream = $zip->getStream($name);
        if ($stream === false) {
            return;
        }
        while (($line = fgets($stream)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (\is_array($row)) {
                yield $row;
            }
        }
        fclose($stream);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readSingleRow(ZipArchive $zip, string $name): ?array
    {
        foreach ($this->rows($zip, $name) as $row) {
            return $row;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function onlyExistingColumns(string $table, array $row): array
    {
        if (!isset($this->columnCache[$table])) {
            $cols = $this->db->fetchFirstColumn(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t',
                ['t' => $table],
            );
            $this->columnCache[$table] = array_fill_keys(array_map('strval', $cols), true);
        }

        return array_intersect_key($row, $this->columnCache[$table]);
    }

    private function tableExists(string $table): bool
    {
        return (bool) $this->db->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t',
            ['t' => $table],
        );
    }
}
