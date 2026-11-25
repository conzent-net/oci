<?php

declare(strict_types=1);

namespace OCI\Admin\Service;

use Doctrine\DBAL\Connection;
use OCI\Admin\Repository\BackupRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Creates a restorable archive of this install, from inside the app.
 *
 * Deliberately shells out to the real mariadb-dump binary (added to
 * docker/php/Dockerfile on 2026-09-04) rather than reconstructing a dump
 * through DBAL. The archive has to be byte-compatible with what
 * scripts/restore.sh and RestoreService consume, and mysqldump output cannot
 * be reliably split on ';' when triggers, routines or string literals are in
 * play — so the real client is needed in both directions anyway.
 *
 * Archive layout (format 2):
 *   databases/<name>.sql   one dump per non-system database on this server
 *   sites_data.tar.gz      generated consent scripts
 *   manifest.txt           provenance: format, database list, migration count
 *   env                    ONLY when explicitly opted in (see below)
 *
 * Format 1 — a single top-level database.sql, what scripts/backup.sh writes —
 * is still readable on restore. Format 2 exists because an install can carry
 * more than one database (the OCI schema plus, say, a v1 consent store), and a
 * backup that silently covers one of them is exactly the half-truth this whole
 * feature exists to remove. It is also what makes an archive portable enough to
 * restore onto a different server rather than only back onto this one.
 *
 * Configuration is excluded by default. An archive is downloadable over HTTP
 * by any admin, and .env carries live Stripe, Cloudflare and Missive
 * credentials plus TOTP_ENCRYPTION_KEY — including it by default would turn
 * one compromised admin session into a full credential breach.
 */
final class BackupService
{
    /** A dump smaller than this is a failure wearing a disguise. */
    private const MIN_DUMP_BYTES = 1000;

    /** Archive layout version written into the manifest. */
    public const ARCHIVE_FORMAT = 2;

    /** Never dumped: server-internal, and restoring them onto another host is harmful. */
    private const SYSTEM_SCHEMAS = ['information_schema', 'performance_schema', 'mysql', 'sys'];

    /**
     * Backup history is excluded from the dump, deliberately.
     *
     * It describes what THIS machine has backed up — operational metadata about
     * the host, not application data. Including it caused two concrete
     * problems: an archive contained its own run row frozen at 'running', so
     * restoring erased the record of the very backup you restored from and left
     * a row that convinced the queue a backup was permanently in flight. And
     * restoring onto a second server imported a history of backups that server
     * never took.
     *
     * Structure is preserved by the migration; only the rows are left behind.
     */
    private const HISTORY_TABLES = ['oci_backups', 'oci_backup_destinations'];

    public function __construct(
        private readonly Connection $db,
        private readonly BackupRepositoryInterface $backups,
        private readonly ElasticsearchArchiver $elasticsearch,
        private readonly LoggerInterface $logger,
        private readonly string $basePath,
    ) {
    }

    public function backupDir(): string
    {
        $configured = trim((string) ($_ENV['BACKUP_DIR'] ?? ''));

        return $configured !== '' ? rtrim($configured, '/') : $this->basePath . '/backups';
    }

    /**
     * @param 'scheduled'|'manual'|'pre_restore' $trigger
     *
     * @return array{id: int, path: string, size: int, sha256: string}
     */
    public function create(string $trigger = 'manual', bool $includeConfig = false): array
    {
        $dir = $this->backupDir();
        if (!is_dir($dir) && !mkdir($dir, 0o750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create backup directory: ' . $dir);
        }

        $stamp = (new \DateTimeImmutable())->format('Ymd-His');
        $filename = 'conzent-' . $stamp . '.tar.gz';
        $path = $dir . '/' . $filename;

        $id = $this->backups->start($filename, $path, $trigger, $includeConfig);

        $stage = $this->makeStagingDir();

        try {
            $dumped = $this->dumpAllDatabases($stage);
            $this->archiveSitesData($stage . '/sites_data.tar.gz');
            $search = $this->archiveElasticsearch($stage . '/elasticsearch');
            $migrationCount = $this->migrationCount();
            $this->writeManifest($stage . '/manifest.txt', $dumped, $search, $migrationCount, $includeConfig);

            if ($includeConfig) {
                $envPath = $this->basePath . '/.env';
                if (is_file($envPath)) {
                    copy($envPath, $stage . '/env');
                    chmod($stage . '/env', 0o600);
                }
            }

            $this->seal($stage, $path);

            $size = (int) filesize($path);
            $sha = hash_file('sha256', $path);

            $this->backups->finish($id, [
                'size_bytes' => $size,
                'sha256' => $sha,
                'table_count' => $dumped['tables'],
                'database_names' => json_encode($dumped['names'], JSON_THROW_ON_ERROR),
                'format' => self::ARCHIVE_FORMAT,
                'migration_count' => $migrationCount,
            ]);

            $this->stampHeartbeat();

            $this->logger->info('Backup created', [
                'id' => $id, 'file' => $filename, 'bytes' => $size, 'trigger' => $trigger,
            ]);

            return ['id' => $id, 'path' => $path, 'size' => $size, 'sha256' => (string) $sha];
        } catch (\Throwable $e) {
            $this->backups->fail($id, $e->getMessage());
            @unlink($path);
            $this->logger->error('Backup failed', ['id' => $id, 'error' => $e->getMessage()]);

            throw $e;
        } finally {
            $this->removeTree($stage);
        }
    }

    /**
     * Every non-system database on this server, app database first.
     *
     * Enumerated rather than configured. A list you have to remember to update
     * is a list that goes stale silently, and the failure only shows up on the
     * day you restore and find something missing.
     *
     * @return list<string>
     */
    public function discoverDatabases(): array
    {
        $app = $this->connectionParams()['dbname'];

        $all = $this->db->fetchFirstColumn(
            'SELECT schema_name FROM information_schema.schemata ORDER BY schema_name',
        );

        $names = [];
        foreach ($all as $name) {
            $name = (string) $name;
            if (!\in_array(strtolower($name), self::SYSTEM_SCHEMAS, true)) {
                $names[] = $name;
            }
        }

        // App database first so a partial restore still yields a working app.
        usort($names, static fn (string $a, string $b): int => ($a === $app ? -1 : 0) <=> ($b === $app ? -1 : 0));

        if ($names === []) {
            throw new \RuntimeException('No user databases found on this server — refusing to write an empty backup.');
        }

        return $names;
    }

    /**
     * Dump every discovered database into databases/<name>.sql.
     *
     * The password goes in a 0600 defaults-file, never on the command line —
     * anything passed as an argument is visible to every process on the box
     * through `ps`.
     *
     * @return array{names: list<string>, tables: int, skipped_routines: list<string>}
     */
    private function dumpAllDatabases(string $stage): array
    {
        $dir = $stage . '/databases';
        if (!mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create the databases directory.');
        }

        $c = $this->connectionParams();
        $cnf = $this->writeDefaultsFile($c);
        $names = $this->discoverDatabases();
        $tables = 0;

        $skippedRoutines = [];

        try {
            foreach ($names as $name) {
                $target = $dir . '/' . $name . '.sql';

                [$code, $stderr] = $this->runDump($cnf, $name, $target, true);

                // MariaDB refuses SHOW FUNCTION STATUS when mysql.proc still has
                // an older server's column layout — the fingerprint of a server
                // upgraded without mariadb-upgrade. Only --routines needs it, so
                // retry without and record the gap rather than losing the whole
                // backup over stored procedures the app does not use.
                if ($code !== 0 && $this->isStaleProcTable($stderr)) {
                    $this->logger->warning('Dumping without routines: mysql.proc needs mariadb-upgrade', [
                        'database' => $name,
                    ]);
                    [$code, $stderr] = $this->runDump($cnf, $name, $target, false);
                    if ($code === 0) {
                        $skippedRoutines[] = $name;
                    }
                }

                if ($code !== 0) {
                    // Only stderr. The dump file itself holds live user rows —
                    // quoting its tail here would put password hashes and email
                    // addresses into an on-screen error, the oci_backups row and
                    // the log.
                    throw new \RuntimeException("mariadb-dump failed for '{$name}' (exit {$code}): " . $stderr);
                }

                $this->assertUsableDump($target, $name);

                $tables += (int) $this->db->fetchOne(
                    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :db',
                    ['db' => $name],
                );
            }
        } finally {
            @unlink($cnf);
        }

        return ['names' => $names, 'tables' => $tables, 'skipped_routines' => $skippedRoutines];
    }

    /**
     * Run one dump. stdout goes to the archive, stderr to a separate file —
     * never `2>&1`, which both corrupts the .sql with diagnostic text and makes
     * it impossible to quote an error without quoting customer data.
     *
     * @return array{0: int, 1: string} exit code, stderr
     */
    private function runDump(string $cnf, string $database, string $target, bool $withRoutines): array
    {
        $errFile = tempnam(sys_get_temp_dir(), 'ocidmp');
        if ($errFile === false) {
            throw new \RuntimeException('Could not create a temporary file for dump diagnostics.');
        }

        try {
            $isAppDb = $database === $this->connectionParams()['dbname'];

            // --ignore-table drops the table entirely, CREATE included. On a
            // fresh server that would be fatal in a way that is easy to miss:
            // the restored oci_migration_versions already lists
            // CreateBackupTables as applied, so migrations would never recreate
            // it and the app would come up without its history table. The
            // structure is therefore appended separately below.
            $ignore = '';
            if ($isAppDb) {
                foreach (self::HISTORY_TABLES as $t) {
                    $ignore .= ' --ignore-table=' . escapeshellarg($database . '.' . $t);
                }
            }

            $cmd = sprintf(
                'mariadb-dump --defaults-extra-file=%s --single-transaction --quick %s%s --triggers --no-tablespaces --default-character-set=utf8mb4 %s > %s 2> %s',
                escapeshellarg($cnf),
                $withRoutines ? '--routines --events' : '',
                $ignore,
                escapeshellarg($database),
                escapeshellarg($target),
                escapeshellarg($errFile),
            );

            exec($cmd, $ignored, $code);

            // Structure only, no rows: the archive can rebuild the history
            // tables on a fresh server without importing another machine's
            // backup log.
            if ($code === 0 && $isAppDb) {
                $structure = sprintf(
                    'mariadb-dump --defaults-extra-file=%s --no-data --no-tablespaces --default-character-set=utf8mb4 %s %s >> %s 2>> %s',
                    escapeshellarg($cnf),
                    escapeshellarg($database),
                    implode(' ', array_map('escapeshellarg', self::HISTORY_TABLES)),
                    escapeshellarg($target),
                    escapeshellarg($errFile),
                );
                exec($structure, $ignored2, $structureCode);

                if ($structureCode !== 0) {
                    $code = $structureCode;
                }
            }

            $stderr = trim((string) @file_get_contents($errFile));

            return [$code, mb_substr($stderr, 0, 1000)];
        } finally {
            @unlink($errFile);
        }
    }

    private function isStaleProcTable(string $stderr): bool
    {
        return str_contains($stderr, 'mysql.proc')
            || str_contains($stderr, 'Column count of mysql.proc is wrong')
            || str_contains($stderr, '(1558)');
    }

    private function assertUsableDump(string $target, string $name): void
    {
        $bytes = is_file($target) ? (int) filesize($target) : 0;
        if ($bytes < self::MIN_DUMP_BYTES) {
            throw new \RuntimeException("Dump of '{$name}' is only {$bytes} bytes — that is not a real dump.");
        }

        // mariadb-dump only writes this trailer after a clean finish, so its
        // absence means the dump was truncated even though the exit code was 0.
        if (!str_contains($this->tail($target), 'Dump completed')) {
            throw new \RuntimeException("Dump of '{$name}' is truncated — no \"Dump completed\" trailer.");
        }
    }

    private function archiveSitesData(string $target): void
    {
        $dir = $this->basePath . '/public/sites_data';
        if (!is_dir($dir)) {
            return;
        }

        exec(sprintf('tar czf %s -C %s . 2>&1', escapeshellarg($target), escapeshellarg($dir)), $out, $code);

        // Generated scripts are rebuildable with `bin/oci scripts:regenerate`,
        // so a failure here is worth recording but must not lose the database.
        if ($code !== 0) {
            $this->logger->warning('Could not archive sites_data', ['exit' => $code]);
            @unlink($target);
        }
    }

    /**
     * Elasticsearch holds KB articles and chat transcripts as the source of
     * truth — nothing reindexes them — so an archive without it is not a
     * complete copy of this install.
     *
     * Absence is not failure: most installs have no Elasticsearch at all, and
     * they skip this silently. A configured-but-unreachable cluster IS a
     * failure worth failing the backup over, because a green backup that
     * quietly dropped the knowledge base is the exact lie being designed out.
     *
     * @return array{configured: bool, indices: list<string>, documents: int}
     */
    private function archiveElasticsearch(string $dir): array
    {
        if (!$this->elasticsearch->isConfigured()) {
            return ['configured' => false, 'indices' => [], 'documents' => 0];
        }

        if (!$this->elasticsearch->isAvailable()) {
            throw new \RuntimeException(
                'Elasticsearch is configured (' . $this->elasticsearch->baseUrl() . ') but did not respond. '
                . 'It holds the knowledge base and chat transcripts, which nothing else can rebuild, so the '
                . 'backup is stopping rather than writing an archive that silently omits them. '
                . 'Bring it up, or clear ELASTICSEARCH_URL if this install genuinely does not use it.',
            );
        }

        $result = $this->elasticsearch->dumpTo($dir);

        $this->logger->info('Elasticsearch archived', [
            'indices' => $result['indices'],
            'documents' => $result['documents'],
        ]);

        return ['configured' => true, 'indices' => $result['indices'], 'documents' => $result['documents']];
    }

    private function migrationCount(): int
    {
        try {
            return (int) $this->db->fetchOne('SELECT COUNT(*) FROM oci_migration_versions');
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @param array{names: list<string>, tables: int, skipped_routines: list<string>} $dumped
     * @param array{configured: bool, indices: list<string>, documents: int}          $search
     */
    private function writeManifest(string $target, array $dumped, array $search, int $migrationCount, bool $includesConfig): void
    {
        $lines = [
            '# Conzent OCI backup',
            'format=' . self::ARCHIVE_FORMAT,
            'created_at=' . gmdate('Y-m-d\TH:i:s\Z'),
            'app_url=' . ($_ENV['APP_URL'] ?? ''),
            'app_database=' . $this->connectionParams()['dbname'],
            // The whole set, so an archive landing on another server can say
            // what it holds without being opened.
            'databases=' . implode(',', $dumped['names']),
            'tables=' . $dumped['tables'],
            // Read by the restore screen to warn when an archive predates the
            // running code by enough migrations to be worth thinking about.
            'migrations=' . $migrationCount,
            'includes_config=' . ($includesConfig ? '1' : '0'),
            'host=' . gethostname(),
            // Stated either way. "This install has no Elasticsearch" and "the
            // archive is missing it" must never look identical to whoever is
            // reading this at 3am.
            'elasticsearch=' . ($search['configured'] ? 'included' : 'not in use'),
            'elasticsearch_indices=' . implode(',', $search['indices']),
            'elasticsearch_documents=' . $search['documents'],
        ];

        // An archive that quietly omits something is the failure mode this
        // whole feature exists to remove, so say it here and in the run row.
        if ($dumped['skipped_routines'] !== []) {
            $lines[] = 'routines_skipped=' . implode(',', $dumped['skipped_routines']);
            $lines[] = '# Stored routines were NOT captured for the databases above:';
            $lines[] = '# mysql.proc has an older server\'s column layout and needs';
            $lines[] = '# mariadb-upgrade. Tables, data and triggers are complete.';
        }

        file_put_contents($target, implode("\n", $lines) . "\n");
    }

    private function seal(string $stage, string $path): void
    {
        exec(sprintf('tar czf %s -C %s . 2>&1', escapeshellarg($path), escapeshellarg($stage)), $out, $code);

        if ($code !== 0 || !is_file($path)) {
            throw new \RuntimeException('Failed to write archive: ' . implode("\n", $out));
        }

        chmod($path, 0o600);
    }

    /**
     * Keep the legacy heartbeat file current so BackupFreshnessService still
     * works for installs whose backups run from a host script.
     */
    private function stampHeartbeat(): void
    {
        $varDir = $this->basePath . '/var';
        if (is_dir($varDir) && is_writable($varDir)) {
            @file_put_contents($varDir . '/backup-last-success', gmdate('Y-m-d\TH:i:s\Z'));
        }
    }

    /** @return array{host: string, port: int, user: string, password: string, dbname: string} */
    private function connectionParams(): array
    {
        $url = (string) ($_ENV['DATABASE_URL'] ?? 'mysql://oci:oci@mariadb:3306/oci?charset=utf8mb4');
        $p = parse_url($url);

        if ($p === false || !isset($p['host'])) {
            throw new \RuntimeException('DATABASE_URL could not be parsed.');
        }

        return [
            'host' => (string) $p['host'],
            'port' => (int) ($p['port'] ?? 3306),
            'user' => rawurldecode((string) ($p['user'] ?? 'oci')),
            'password' => rawurldecode((string) ($p['pass'] ?? '')),
            'dbname' => trim((string) ($p['path'] ?? '/oci'), '/'),
        ];
    }

    /** @param array{host: string, port: int, user: string, password: string, dbname: string} $c */
    private function writeDefaultsFile(array $c): string
    {
        $cnf = tempnam(sys_get_temp_dir(), 'ocibk');
        if ($cnf === false) {
            throw new \RuntimeException('Could not create a temporary defaults file.');
        }

        chmod($cnf, 0o600);
        file_put_contents($cnf, sprintf(
            "[client]\nhost=%s\nport=%d\nuser=%s\npassword=%s\n",
            $c['host'],
            $c['port'],
            $c['user'],
            $c['password'],
        ));

        return $cnf;
    }

    private function tail(string $file, int $bytes = 2000): string
    {
        if (!is_file($file)) {
            return '';
        }

        $size = (int) filesize($file);
        $fh = fopen($file, 'rb');
        if ($fh === false) {
            return '';
        }

        if ($size > $bytes) {
            fseek($fh, -$bytes, SEEK_END);
        }
        $data = (string) stream_get_contents($fh);
        fclose($fh);

        return $data;
    }

    private function makeStagingDir(): string
    {
        $dir = sys_get_temp_dir() . '/oci-backup-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create staging directory.');
        }

        return $dir;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
