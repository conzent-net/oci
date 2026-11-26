<?php

declare(strict_types=1);

namespace OCI\Admin\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Restores an archive produced by BackupService — including one taken on a
 * different server.
 *
 * ── Stage, then swap. Never import into the live schema. ────────────────────
 * Each database in the archive is imported into a scratch schema first. Only
 * when that import has finished cleanly are the live tables exchanged for the
 * restored ones, in a single multi-table RENAME TABLE, which MariaDB treats as
 * one atomic metadata operation.
 *
 * This matters because the long, failure-prone part — streaming a large dump —
 * then never touches live data. A crash there is a non-event: the site has been
 * serving the whole time and nothing is half-replaced. Real downtime shrinks
 * from the duration of the import to a sub-second rename. scripts/restore.sh
 * gets the same safety by taking the entire stack offline for the whole import;
 * this cannot do that, because it is running inside the stack it would stop.
 *
 * The displaced live tables are kept in <db>_prerestore_<job> rather than
 * dropped, so "the restore worked but the data is wrong" is recoverable without
 * going back to an archive.
 *
 * Reads both archive layouts: format 2 (databases/<name>.sql) and format 1
 * (a single top-level database.sql, what scripts/backup.sh writes).
 */
final class RestoreService
{
    private const SYSTEM_SCHEMAS = ['information_schema', 'performance_schema', 'mysql', 'sys'];

    public function __construct(
        private readonly Connection $db,
        private readonly ElasticsearchArchiver $elasticsearch,
        private readonly LoggerInterface $logger,
        private readonly string $basePath,
    ) {
    }

    /**
     * Inspect an archive without changing anything.
     *
     * The restore screen shows this before asking for confirmation — you should
     * be able to see what you are about to get back while you can still refuse.
     *
     * @return array{ok: bool, error?: string, format: int, databases: list<string>, manifest: array<string, string>, has_sites_data: bool, includes_config: bool}
     */
    public function inspect(string $archivePath): array
    {
        $empty = ['format' => 0, 'databases' => [], 'manifest' => [], 'has_sites_data' => false, 'includes_config' => false, 'search_indices' => []];

        if (!is_file($archivePath)) {
            return ['ok' => false, 'error' => 'Archive not found.'] + $empty;
        }

        $listing = [];
        exec(sprintf('tar tzf %s 2>&1', escapeshellarg($archivePath)), $listing, $code);
        if ($code !== 0) {
            return ['ok' => false, 'error' => 'Not a readable .tar.gz archive.'] + $empty;
        }

        $databases = [];
        $searchIndices = [];
        $hasSitesData = false;
        $format = 0;

        foreach ($listing as $entry) {
            $entry = ltrim(trim($entry), './');

            if (preg_match('#^databases/(.+)\.sql$#', $entry, $m) === 1) {
                $databases[] = $m[1];
                $format = 2;
            } elseif ($entry === 'database.sql' && $format === 0) {
                $format = 1;
            } elseif (preg_match('#^elasticsearch/(.+)\.ndjson$#', $entry, $e) === 1) {
                $searchIndices[] = $e[1];
            } elseif ($entry === 'sites_data.tar.gz') {
                $hasSitesData = true;
            }
        }

        if ($format === 0) {
            return [
                'ok' => false,
                'error' => 'No database dump inside. This is not a Conzent backup archive.',
            ] + $empty;
        }

        $manifest = $this->readManifest($archivePath);

        if ($format === 1) {
            // Format 1 carries one unnamed dump; the manifest names the schema
            // it came from, and failing that it lands in the app's own.
            $databases = [$manifest['database'] ?? $manifest['app_database'] ?? $this->appDatabase()];
        }

        return [
            'ok' => true,
            'format' => $format,
            'databases' => array_values(array_unique($databases)),
            'manifest' => $manifest,
            'has_sites_data' => $hasSitesData,
            'includes_config' => ($manifest['includes_config'] ?? '0') === '1',
            'search_indices' => $searchIndices,
        ];
    }

    /**
     * Unpack, import into scratch schemas, then swap.
     *
     * @param callable(string, int): void $progress step name, percent
     *
     * @return array{databases: list<string>, prerestore_suffix: string}
     */
    public function restore(string $archivePath, string $jobId, callable $progress): array
    {
        $info = $this->inspect($archivePath);
        if ($info['ok'] !== true) {
            throw new \RuntimeException((string) ($info['error'] ?? 'Archive could not be read.'));
        }

        $stage = $this->makeStagingDir();

        try {
            $progress('unpacking', 5);
            exec(sprintf('tar xzf %s -C %s 2>&1', escapeshellarg($archivePath), escapeshellarg($stage)), $o, $code);
            if ($code !== 0) {
                throw new \RuntimeException('Could not unpack the archive: ' . implode("\n", $o));
            }

            $suffix = 'prerestore_' . $jobId;
            $restored = [];
            $total = max(1, \count($info['databases']));
            $i = 0;

            foreach ($info['databases'] as $name) {
                $this->assertSafeSchemaName($name);

                $sqlFile = $info['format'] === 2
                    ? $stage . '/databases/' . $name . '.sql'
                    : $stage . '/database.sql';

                if (!is_file($sqlFile)) {
                    throw new \RuntimeException("Archive is missing the dump for '{$name}'.");
                }

                $scratch = $name . '_restoring_' . $jobId;

                $progress("importing {$name}", 10 + (int) (60 * $i / $total));
                $this->createSchema($scratch);
                $this->importInto($scratch, $sqlFile, $name);

                $progress("swapping {$name}", 70 + (int) (20 * $i / $total));
                $this->swap($name, $scratch, $suffix);

                $restored[] = $name;
                ++$i;
            }

            if ($info['search_indices'] !== [] && is_dir($stage . '/elasticsearch')) {
                $progress('restoring search indices', 90);
                $this->restoreElasticsearch($stage . '/elasticsearch');
            }

            if ($info['has_sites_data'] && is_file($stage . '/sites_data.tar.gz')) {
                $progress('restoring consent scripts', 92);
                $this->restoreSitesData($stage . '/sites_data.tar.gz');
            }

            $progress('done', 100);

            return ['databases' => $restored, 'prerestore_suffix' => $suffix];
        } finally {
            $this->removeTree($stage);
        }
    }

    /**
     * Exchange live tables for restored ones in one atomic statement.
     *
     * When the target schema does not exist — the normal case when restoring
     * onto a different server — there is nothing to displace, so the scratch
     * schema is simply renamed into place table by table.
     */
    private function swap(string $live, string $scratch, string $suffix): void
    {
        $liveExists = $this->schemaExists($live);
        $parked = $live . '_' . $suffix;

        $scratchTables = $this->tablesIn($scratch);
        if ($scratchTables === []) {
            throw new \RuntimeException("Restored schema for '{$live}' contains no tables.");
        }

        if (!$liveExists) {
            $this->createSchema($live);
        } else {
            $this->createSchema($parked);
        }

        // Only tables the archive actually carries are displaced. Anything live
        // that the archive does not contain is left alone rather than parked
        // out of existence — backup history is excluded from dumps on purpose,
        // and a restore that silently deleted whatever an older archive
        // predates would be a destructive surprise.
        $scratchSet = array_flip($scratchTables);
        $preserved = [];

        $parts = [];
        foreach ($this->tablesIn($live) as $t) {
            if (isset($scratchSet[$t])) {
                $parts[] = sprintf('`%s`.`%s` TO `%s`.`%s`', $live, $t, $parked, $t);
            } else {
                $preserved[] = $t;
            }
        }
        foreach ($scratchTables as $t) {
            $parts[] = sprintf('`%s`.`%s` TO `%s`.`%s`', $scratch, $t, $live, $t);
        }

        // One statement. MariaDB applies a multi-table RENAME atomically, so
        // there is no window where the app sees a half-swapped schema.
        // One statement, elevated credentials.
        $this->cli('RENAME TABLE ' . implode(', ', $parts));

        $this->cli(sprintf('DROP DATABASE IF EXISTS `%s`', $scratch));

        $this->logger->info('Restore swapped schema', [
            'database' => $live,
            'tables' => \count($scratchTables),
            'preserved' => $preserved,
            'previous_kept_as' => $liveExists ? $parked : null,
        ]);
    }

    private function importInto(string $schema, string $sqlFile, string $label): void
    {
        $c = $this->connectionParams();
        $cnf = $this->writeDefaultsFile($c);

        try {
            // The mariadb client aborts on the first SQL error (no --force), so
            // a non-zero exit is a reliable "this import did not complete".
            $cmd = sprintf(
                'mariadb --defaults-extra-file=%s --default-character-set=utf8mb4 %s < %s 2>&1',
                escapeshellarg($cnf),
                escapeshellarg($schema),
                escapeshellarg($sqlFile),
            );

            exec($cmd, $output, $code);

            if ($code !== 0) {
                throw new \RuntimeException(
                    "Import of '{$label}' failed (exit {$code}): " . implode("\n", \array_slice($output, -5)),
                );
            }
        } finally {
            @unlink($cnf);
        }
    }

    /** Drop the parked pre-restore schemas for a job. */
    public function discardPrerestore(string $suffix): int
    {
        $dropped = 0;

        foreach ($this->allSchemas() as $schema) {
            if (str_ends_with($schema, '_' . $suffix)) {
                $this->cli(sprintf('DROP DATABASE IF EXISTS `%s`', $schema));
                ++$dropped;
            }
        }

        return $dropped;
    }

    /** Remove any scratch schema left behind by an interrupted restore. */
    public function cleanupScratch(string $jobId): int
    {
        $dropped = 0;

        foreach ($this->allSchemas() as $schema) {
            if (str_ends_with($schema, '_restoring_' . $jobId)) {
                $this->cli(sprintf('DROP DATABASE IF EXISTS `%s`', $schema));
                ++$dropped;
            }
        }

        return $dropped;
    }

    /**
     * Reload the archived search indices.
     *
     * Fatal when the archive carries indices and this install has no
     * Elasticsearch to put them in: silently discarding the knowledge base
     * would leave a restore that reports success while having thrown away
     * content nothing can rebuild.
     */
    private function restoreElasticsearch(string $dir): void
    {
        if (!$this->elasticsearch->isConfigured()) {
            throw new \RuntimeException(
                'This archive contains Elasticsearch indices but ELASTICSEARCH_URL is not set on this install. '
                . 'They hold the knowledge base and chat transcripts and cannot be rebuilt from the database, so '
                . 'the restore is stopping rather than dropping them. Point ELASTICSEARCH_URL at a cluster and retry.',
            );
        }

        if (!$this->elasticsearch->isAvailable()) {
            throw new \RuntimeException(
                'Elasticsearch is configured but did not respond, and this archive carries indices that would be lost.',
            );
        }

        $result = $this->elasticsearch->restoreFrom($dir);

        $this->logger->info('Elasticsearch restored', [
            'indices' => $result['indices'],
            'documents' => $result['documents'],
        ]);
    }

    private function restoreSitesData(string $tarball): void
    {
        $dir = $this->basePath . '/public/sites_data';
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            $this->logger->warning('Could not create sites_data directory; scripts will be regenerated instead');

            return;
        }

        exec(sprintf('tar xzf %s -C %s 2>&1', escapeshellarg($tarball), escapeshellarg($dir)), $o, $code);

        if ($code !== 0) {
            // Regenerable from the database, so this is a warning, not a failure.
            $this->logger->warning('Could not restore consent scripts', ['exit' => $code]);
        }
    }

    /** @return array<string, string> */
    private function readManifest(string $archivePath): array
    {
        $out = [];
        exec(
            sprintf('tar xzf %s -O ./manifest.txt 2>/dev/null || tar xzf %s -O manifest.txt 2>/dev/null',
                escapeshellarg($archivePath), escapeshellarg($archivePath)),
            $out,
        );

        $manifest = [];
        foreach ($out as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $manifest[trim($k)] = trim($v);
        }

        return $manifest;
    }

    /**
     * A schema name from an archive reaches SQL that cannot be parameterised,
     * so it is validated rather than escaped, and system schemas are refused
     * outright — an archive must never be able to overwrite `mysql`.
     */
    private function assertSafeSchemaName(string $name): void
    {
        if (preg_match('/^[A-Za-z0-9_]{1,60}$/', $name) !== 1) {
            throw new \RuntimeException("Refusing to restore a database with an unsafe name: '{$name}'.");
        }

        if (\in_array(strtolower($name), self::SYSTEM_SCHEMAS, true)) {
            throw new \RuntimeException("Refusing to restore over the system schema '{$name}'.");
        }
    }

    /**
     * Can these credentials actually perform a restore?
     *
     * The application's own database user normally holds ALL on its own schema
     * and USAGE on everything else — enough to run the app, not enough to
     * create the scratch schema a safe restore stages into, and certainly not
     * enough to create a database that does not exist yet on a fresh server.
     *
     * Checked up front so the operator gets a sentence they can act on instead
     * of a raw "Access denied" from three layers down, mid-restore.
     *
     * @return array{ok: bool, message: string, elevated: bool}
     */
    public function preflight(): array
    {
        $elevated = trim((string) ($_ENV['RESTORE_DATABASE_URL'] ?? '')) !== '';
        $probe = 'oci_restore_probe_' . bin2hex(random_bytes(4));

        try {
            $this->cli(sprintf('CREATE DATABASE `%s`', $probe));
            $this->cli(sprintf('DROP DATABASE `%s`', $probe));
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'elevated' => $elevated,
                'message' => 'These database credentials cannot create a database, which a restore needs — '
                    . 'it stages into a scratch schema before swapping so a failed import never touches live data. '
                    . 'Set RESTORE_DATABASE_URL to a user with CREATE and DROP (the MariaDB root user, or one granted '
                    . 'ALL ON *.*), then try again. The application keeps using DATABASE_URL for everything else.',
            ];
        }

        return [
            'ok' => true,
            'elevated' => $elevated,
            'message' => $elevated
                ? 'Using RESTORE_DATABASE_URL. Restore is available.'
                : 'The application database user can create schemas. Restore is available.',
        ];
    }

    /**
     * Run a statement with the restore credentials through the real client.
     *
     * DDL and schema enumeration go through the CLI rather than the app's DBAL
     * connection so that an elevated RESTORE_DATABASE_URL actually applies to
     * them — the DBAL connection is built from DATABASE_URL and stays scoped to
     * the application's own schema.
     *
     * @return list<string>
     */
    private function cli(string $sql, ?string $schema = null): array
    {
        $c = $this->connectionParams();
        $cnf = $this->writeDefaultsFile($c);

        try {
            $cmd = sprintf(
                'mariadb --defaults-extra-file=%s -N -B %s -e %s 2>&1',
                escapeshellarg($cnf),
                $schema === null ? '' : escapeshellarg($schema),
                escapeshellarg($sql),
            );

            exec($cmd, $out, $code);

            if ($code !== 0) {
                throw new \RuntimeException(trim(implode("\n", $out)) ?: ('SQL failed (exit ' . $code . ')'));
            }

            return array_values(array_filter(array_map('trim', $out), static fn (string $l): bool => $l !== ''));
        } finally {
            @unlink($cnf);
        }
    }

    private function createSchema(string $name): void
    {
        $this->assertSafeSchemaName(str_replace(['_restoring_', '_prerestore_'], '_', $name));
        $this->cli(sprintf('CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $name));
    }

    private function schemaExists(string $name): bool
    {
        $rows = $this->cli(sprintf(
            "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = '%s'",
            str_replace("'", '', $name),
        ));

        return ($rows[0] ?? '0') !== '0';
    }

    /** @return list<string> */
    private function allSchemas(): array
    {
        return $this->cli('SELECT schema_name FROM information_schema.schemata');
    }

    /** @return list<string> */
    private function tablesIn(string $schema): array
    {
        return $this->cli(sprintf(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = '%s' AND table_type = 'BASE TABLE'",
            str_replace("'", '', $schema),
        ));
    }

    private function appDatabase(): string
    {
        return $this->connectionParams()['dbname'];
    }

    /** @return array{host: string, port: int, user: string, password: string, dbname: string} */
    private function connectionParams(): array
    {
        // An elevated credential when one is configured; the app's own
        // otherwise. See preflight() for why a restore needs more than the
        // application user usually holds.
        $url = trim((string) ($_ENV['RESTORE_DATABASE_URL'] ?? ''));
        if ($url === '') {
            $url = (string) ($_ENV['DATABASE_URL'] ?? 'mysql://oci:oci@mariadb:3306/oci?charset=utf8mb4');
        }
        $p = parse_url($url);

        if ($p === false || !isset($p['host'])) {
            throw new \RuntimeException('The restore database URL could not be parsed.');
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
        $cnf = tempnam(sys_get_temp_dir(), 'ocirs');
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

    private function makeStagingDir(): string
    {
        $dir = sys_get_temp_dir() . '/oci-restore-' . bin2hex(random_bytes(6));
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
