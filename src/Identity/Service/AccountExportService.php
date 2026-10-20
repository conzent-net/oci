<?php

declare(strict_types=1);

namespace OCI\Identity\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use RuntimeException;
use ZipArchive;

/**
 * Exports everything the platform holds for one account into a zip of
 * JSONL files (one row per line, one file per table) plus a manifest.
 *
 * The tables are the same schema the Apache 2.0 open core ships, so the
 * export is not just readable — it maps 1:1 onto a self-hosted OCI
 * installation. Part of the open core: both editions ship it.
 *
 * Large tables (consents and their satellites) are walked with keyset
 * pagination so an account with millions of consent records exports in
 * bounded memory.
 */
final class AccountExportService
{
    private const CHUNK = 5000;

    /** Columns that must never leave the system, per table. */
    private const STRIP = [
        'oci_users' => ['password', 'remember_token', 'remember_token_hash'],
    ];

    /** @var list<string> Temp files pending cleanup after the zip closes. */
    private array $tempFiles = [];

    public function __construct(
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{path: string, tables: array<string, int>, sites: int}
     */
    public function export(int $userId, string $outDir): array
    {
        $user = $this->db->fetchAssociative('SELECT * FROM oci_users WHERE id = :id', ['id' => $userId]);
        if ($user === false) {
            throw new RuntimeException("User {$userId} not found");
        }

        if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
            throw new RuntimeException("Cannot create output directory {$outDir}");
        }

        $siteIds = array_map(
            'intval',
            $this->db->fetchFirstColumn('SELECT id FROM oci_sites WHERE user_id = :uid', ['uid' => $userId]),
        );

        $zipPath = rtrim($outDir, '/\\') . '/conzent-export-user' . $userId . '-' . date('Ymd-His') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot create zip {$zipPath}");
        }

        $counts = [];

        // ── Account tables ───────────────────────────────────────────────
        $counts['oci_users'] = $this->addRows($zip, 'oci_users', [$this->strip('oci_users', $user)]);
        $counts['oci_user_companies'] = $this->addScoped($zip, 'oci_user_companies', 'user_id', [$userId]);

        // ── Site-scoped tables ───────────────────────────────────────────
        $counts['oci_sites'] = $this->addScoped($zip, 'oci_sites', 'user_id', [$userId]);

        foreach ([
            'oci_associated_sites', 'oci_site_languages', 'oci_site_urls',
            'oci_site_banners',
            'oci_site_cookies', 'oci_site_cookie_categories', 'oci_custom_layouts',
            'oci_site_privacy_frameworks', 'oci_scans',
            'oci_cookie_policies', 'oci_privacy_policies',
        ] as $table) {
            $counts[$table] = $this->addScoped($zip, $table, 'site_id', $siteIds);
        }

        // ── Children keyed to site-scoped parents ────────────────────────
        $bannerIds = $this->idsFor('oci_site_banners', 'site_id', $siteIds);
        $counts['oci_site_banner_settings'] = $this->addScoped($zip, 'oci_site_banner_settings', 'site_banner_id', $bannerIds);
        $counts['oci_site_banner_field_translations'] = $this->addScoped($zip, 'oci_site_banner_field_translations', 'site_banner_id', $bannerIds);

        $cookieIds = $this->idsFor('oci_site_cookies', 'site_id', $siteIds);
        $counts['oci_site_cookie_translations'] = $this->addScoped($zip, 'oci_site_cookie_translations', 'site_cookie_id', $cookieIds);

        $categoryIds = $this->idsFor('oci_site_cookie_categories', 'site_id', $siteIds);
        $counts['oci_site_cookie_category_translations'] = $this->addScoped($zip, 'oci_site_cookie_category_translations', 'site_cookie_category_id', $categoryIds);

        $scanIds = $this->idsFor('oci_scans', 'site_id', $siteIds);
        $counts['oci_scan_cookies'] = $this->addScoped($zip, 'oci_scan_cookies', 'scan_id', $scanIds);

        // ── Consent audit trail (chunked) ────────────────────────────────
        $counts['oci_consents'] = $this->addScoped($zip, 'oci_consents', 'site_id', $siteIds);
        $counts['oci_consent_categories'] = $this->addConsentCategories($zip, $siteIds);
        $counts['oci_consent_daily_stats'] = $this->addScoped($zip, 'oci_consent_daily_stats', 'site_id', $siteIds);
        $counts['oci_cookie_observations'] = $this->addScoped($zip, 'oci_cookie_observations', 'site_id', $siteIds);

        // ── Reference tables ─────────────────────────────────────────────
        // Cross-install ids differ (languages, categories, banner fields), so
        // the import remaps by natural keys. Shipping the SOURCE's reference
        // rows makes the export self-describing: old id → natural key comes
        // from these files, natural key → new id from the target install.
        $reference = [];
        foreach ([
            'oci_languages', 'oci_cookie_categories', 'oci_cookie_category_translations',
            'oci_banner_templates', 'oci_banner_field_categories', 'oci_banner_fields',
        ] as $table) {
            $reference[$table] = $this->addFullTable($zip, $table, 'reference/');
        }
        $reference = array_filter($reference, static fn (?int $c): bool => $c !== null);

        $counts = array_filter($counts, static fn (?int $c): bool => $c !== null);

        $zip->addFromString('manifest.json', json_encode([
            'format' => 'conzent-account-export/2',
            'generated_at' => date('c'),
            'user_id' => $userId,
            'user_email' => $user['email'] ?? null,
            'sites' => \count($siteIds),
            'tables' => $counts,
            'reference_tables' => $reference,
            'notes' => [
                'One JSONL file per table: one JSON object per line, column names as keys.',
                'The schema is the public Conzent OCI schema (github.com/conzent-net/oci, migrations/versions/) — this export maps 1:1 onto a self-hosted installation.',
                'reference/ holds the source install\'s shared reference rows (languages, categories, banner templates/fields) so an importer can remap ids by natural keys.',
                'Import with: php bin/oci account:import --file=<this zip>',
                'Consent-log ip_address values are pseudonyms (keyed hash or truncation); raw IPs are not stored and therefore not exported.',
                'Password hashes and session tokens are excluded.',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // ZipArchive reads addFile() sources during close(), so the temp
        // files must outlive it — this is what keeps memory bounded.
        $zip->close();
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];

        $this->logger->info('Account export generated', ['user_id' => $userId, 'path' => $zipPath]);

        return ['path' => $zipPath, 'tables' => $counts, 'sites' => \count($siteIds)];
    }

    /**
     * @param list<int> $ids
     * @return int|null Row count, or null when the table does not exist on this install
     */
    private function addScoped(ZipArchive $zip, string $table, string $column, array $ids): ?int
    {
        if (!$this->tableExists($table)) {
            return null;
        }
        if ($ids === []) {
            return $this->addRows($zip, $table, []);
        }

        [$path, $tmp] = $this->openTemp();
        $count = 0;
        $lastId = 0;

        do {
            $rows = $this->db->fetchAllAssociative(
                'SELECT * FROM ' . $table . ' WHERE ' . $column . ' IN (:ids) AND id > :lastId ORDER BY id ASC LIMIT ' . self::CHUNK,
                ['ids' => $ids, 'lastId' => $lastId],
                ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER, 'lastId' => ParameterType::INTEGER],
            );

            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                fwrite($tmp, json_encode($this->strip($table, $row), JSON_UNESCAPED_SLASHES) . "\n");
                $count++;
            }
        } while (\count($rows) === self::CHUNK);

        fclose($tmp);
        $zip->addFile($path, $table . '.jsonl');

        return $count;
    }

    /**
     * oci_consent_categories has no site_id — walk it via its parent in
     * chunks so huge consent tables never load an id list into memory.
     */
    private function addConsentCategories(ZipArchive $zip, array $siteIds): ?int
    {
        if (!$this->tableExists('oci_consent_categories') || $siteIds === []) {
            return $this->tableExists('oci_consent_categories') ? $this->addRows($zip, 'oci_consent_categories', []) : null;
        }

        [$path, $tmp] = $this->openTemp();
        $count = 0;
        $lastId = 0;

        do {
            $rows = $this->db->fetchAllAssociative(
                'SELECT cc.* FROM oci_consent_categories cc
                 INNER JOIN oci_consents c ON c.id = cc.consent_id
                 WHERE c.site_id IN (:ids) AND cc.id > :lastId
                 ORDER BY cc.id ASC LIMIT ' . self::CHUNK,
                ['ids' => $siteIds, 'lastId' => $lastId],
                ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER, 'lastId' => ParameterType::INTEGER],
            );

            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                fwrite($tmp, json_encode($row, JSON_UNESCAPED_SLASHES) . "\n");
                $count++;
            }
        } while (\count($rows) === self::CHUNK);

        fclose($tmp);
        $zip->addFile($path, 'oci_consent_categories.jsonl');

        return $count;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function addRows(ZipArchive $zip, string $table, array $rows): int
    {
        $lines = '';
        foreach ($rows as $row) {
            $lines .= json_encode($row, JSON_UNESCAPED_SLASHES) . "\n";
        }
        $zip->addFromString($table . '.jsonl', $lines);

        return \count($rows);
    }

    /**
     * Full dump of a shared reference table into the given zip folder.
     */
    private function addFullTable(ZipArchive $zip, string $table, string $prefix): ?int
    {
        if (!$this->tableExists($table)) {
            return null;
        }

        [$path, $tmp] = $this->openTemp();
        $count = 0;
        $lastId = 0;

        do {
            $rows = $this->db->fetchAllAssociative(
                'SELECT * FROM ' . $table . ' WHERE id > :lastId ORDER BY id ASC LIMIT ' . self::CHUNK,
                ['lastId' => $lastId],
                ['lastId' => ParameterType::INTEGER],
            );

            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                fwrite($tmp, json_encode($row, JSON_UNESCAPED_SLASHES) . "\n");
                $count++;
            }
        } while (\count($rows) === self::CHUNK);

        fclose($tmp);
        $zip->addFile($path, $prefix . $table . '.jsonl');

        return $count;
    }

    /**
     * @return array{0: string, 1: resource}
     */
    private function openTemp(): array
    {
        $path = tempnam(sys_get_temp_dir(), 'ociexp');
        if ($path === false) {
            throw new RuntimeException('Cannot create temp file for export');
        }
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Cannot open temp file for export');
        }
        $this->tempFiles[] = $path;

        return [$path, $handle];
    }

    /**
     * @param list<int> $parentIds
     * @return list<int>
     */
    private function idsFor(string $table, string $column, array $parentIds): array
    {
        if ($parentIds === [] || !$this->tableExists($table)) {
            return [];
        }

        return array_map('intval', $this->db->fetchFirstColumn(
            'SELECT id FROM ' . $table . ' WHERE ' . $column . ' IN (:ids)',
            ['ids' => $parentIds],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        ));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function strip(string $table, array $row): array
    {
        foreach (self::STRIP[$table] ?? [] as $column) {
            unset($row[$column]);
        }

        return $row;
    }

    private function tableExists(string $table): bool
    {
        return (bool) $this->db->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t',
            ['t' => $table],
        );
    }
}
