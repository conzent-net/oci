<?php

declare(strict_types=1);

namespace OCI\Admin\Service;

use Psr\Log\LoggerInterface;

/**
 * Dumps and reloads Elasticsearch indices as NDJSON inside a backup archive.
 *
 * Elasticsearch is a source of truth here, not a derived index. KnowledgeBase-
 * Service writes KB articles and chat transcripts straight into it and there is
 * no reindex command anywhere in bin/oci, so losing the cluster loses that
 * content outright. An archive that omits it would be exactly the kind of
 * quiet half-coverage this feature exists to end.
 *
 * Deliberately speaks raw HTTP rather than reusing ElasticsearchClient: that
 * class is a Cloud-edition orphan that scripts/publish.php strips from the
 * public repository, and BackupService must keep working in a self-hosted
 * build. All this needs is ELASTICSEARCH_URL and curl.
 *
 * Absence is not failure. An install without Elasticsearch — every self-hosted
 * one — skips this silently, and the manifest records what was and was not
 * captured either way.
 */
final class ElasticsearchArchiver
{
    /** Per scroll page. Large enough to be quick, small enough to stay in memory. */
    private const PAGE_SIZE = 500;

    private const SCROLL_TTL = '2m';

    /** Bulk-index this many documents per request when restoring. */
    private const BULK_SIZE = 500;

    private const TIMEOUT_SECONDS = 30;

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function baseUrl(): string
    {
        return rtrim((string) ($_ENV['ELASTICSEARCH_URL'] ?? ''), '/');
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl() !== '';
    }

    public function isAvailable(): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        try {
            [$code] = $this->request('GET', '/_cluster/health', null, 5);

            return $code >= 200 && $code < 300;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Every index that is not an Elasticsearch internal one.
     *
     * Enumerated rather than configured, for the same reason the database list
     * is: a hardcoded list goes stale silently and you find out on the day you
     * restore.
     *
     * @return list<string>
     */
    public function indices(): array
    {
        [$code, $body] = $this->request('GET', '/_cat/indices?format=json&h=index');

        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException('Elasticsearch refused the index listing (HTTP ' . $code . ').');
        }

        /** @var list<array{index?: string}> $rows */
        $rows = json_decode($body, true, 8, JSON_THROW_ON_ERROR) ?: [];

        $names = [];
        foreach ($rows as $row) {
            $name = (string) ($row['index'] ?? '');
            // Dot-prefixed indices are the cluster's own bookkeeping and
            // restoring them onto another cluster is at best useless.
            if ($name !== '' && !str_starts_with($name, '.')) {
                $names[] = $name;
            }
        }

        sort($names);

        return $names;
    }

    /**
     * Write one NDJSON file per index into $dir.
     *
     * @return array{indices: list<string>, documents: int}
     */
    public function dumpTo(string $dir): array
    {
        if (!is_dir($dir) && !mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create the elasticsearch directory.');
        }

        $indices = $this->indices();
        $total = 0;
        $written = [];

        foreach ($indices as $index) {
            $count = $this->dumpIndex($index, $dir . '/' . $index . '.ndjson');
            $total += $count;
            $written[] = $index;
        }

        return ['indices' => $written, 'documents' => $total];
    }

    private function dumpIndex(string $index, string $target): int
    {
        $fh = fopen($target, 'wb');
        if ($fh === false) {
            throw new \RuntimeException("Could not open {$target} for writing.");
        }

        $written = 0;

        try {
            // The mapping goes in first so a restore onto an empty cluster
            // recreates field types rather than letting ES guess them.
            [$code, $mappingBody] = $this->request('GET', '/' . rawurlencode($index) . '/_mapping');
            if ($code >= 200 && $code < 300) {
                /** @var array<string, array{mappings?: array<string, mixed>}> $m */
                $m = json_decode($mappingBody, true, 32, JSON_THROW_ON_ERROR) ?: [];
                fwrite($fh, json_encode([
                    '__meta' => 'mapping',
                    'index' => $index,
                    'mappings' => $m[$index]['mappings'] ?? new \stdClass(),
                ], JSON_THROW_ON_ERROR) . "\n");
            }

            [$code, $body] = $this->request(
                'POST',
                '/' . rawurlencode($index) . '/_search?scroll=' . self::SCROLL_TTL,
                ['size' => self::PAGE_SIZE, 'query' => ['match_all' => new \stdClass()]],
            );

            if ($code < 200 || $code >= 300) {
                throw new \RuntimeException("Elasticsearch refused a scroll on '{$index}' (HTTP {$code}).");
            }

            $scrollId = null;

            while (true) {
                /** @var array{_scroll_id?: string, hits?: array{hits?: list<array<string, mixed>>}} $page */
                $page = json_decode($body, true, 64, JSON_THROW_ON_ERROR) ?: [];
                $scrollId = $page['_scroll_id'] ?? $scrollId;
                $hits = $page['hits']['hits'] ?? [];

                if ($hits === []) {
                    break;
                }

                foreach ($hits as $hit) {
                    fwrite($fh, json_encode([
                        '_id' => $hit['_id'] ?? null,
                        '_source' => $hit['_source'] ?? new \stdClass(),
                    ], JSON_THROW_ON_ERROR) . "\n");
                    ++$written;
                }

                if ($scrollId === null) {
                    break;
                }

                [$code, $body] = $this->request('POST', '/_search/scroll', [
                    'scroll' => self::SCROLL_TTL,
                    'scroll_id' => $scrollId,
                ]);

                if ($code < 200 || $code >= 300) {
                    break;
                }
            }

            if ($scrollId !== null) {
                // Leaving scroll contexts open holds cluster memory.
                $this->request('DELETE', '/_search/scroll', ['scroll_id' => [$scrollId]]);
            }
        } finally {
            fclose($fh);
        }

        return $written;
    }

    /**
     * Reload every NDJSON file in $dir. Existing indices are replaced.
     *
     * @return array{indices: list<string>, documents: int}
     */
    public function restoreFrom(string $dir): array
    {
        $files = glob($dir . '/*.ndjson') ?: [];
        $restored = [];
        $documents = 0;

        foreach ($files as $file) {
            $index = basename($file, '.ndjson');
            $documents += $this->restoreIndex($index, $file);
            $restored[] = $index;
        }

        return ['indices' => $restored, 'documents' => $documents];
    }

    private function restoreIndex(string $index, string $file): int
    {
        $fh = fopen($file, 'rb');
        if ($fh === false) {
            throw new \RuntimeException("Could not read {$file}.");
        }

        $written = 0;
        $bulk = '';
        $inBatch = 0;

        try {
            // Replace outright. A partial overlay would leave documents from
            // whatever was here before mixed in with the restored set, which is
            // not what "restore" means to anyone.
            $this->request('DELETE', '/' . rawurlencode($index));

            $first = fgets($fh);
            $mappings = null;
            if (\is_string($first)) {
                /** @var array{__meta?: string, mappings?: array<string, mixed>} $decoded */
                $decoded = json_decode(trim($first), true, 64, JSON_THROW_ON_ERROR) ?: [];
                if (($decoded['__meta'] ?? '') === 'mapping') {
                    $mappings = $decoded['mappings'] ?? null;
                } else {
                    rewind($fh);
                }
            }

            $create = $mappings === null || $mappings === [] ? [] : ['mappings' => $mappings];
            $this->request('PUT', '/' . rawurlencode($index), $create === [] ? null : $create);

            while (($line = fgets($fh)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                /** @var array{_id?: string, _source?: array<string, mixed>} $doc */
                $doc = json_decode($line, true, 64, JSON_THROW_ON_ERROR) ?: [];

                $action = ['index' => ['_index' => $index]];
                if (isset($doc['_id']) && $doc['_id'] !== null) {
                    $action['index']['_id'] = $doc['_id'];
                }

                $bulk .= json_encode($action, JSON_THROW_ON_ERROR) . "\n";
                $bulk .= json_encode($doc['_source'] ?? new \stdClass(), JSON_THROW_ON_ERROR) . "\n";
                ++$inBatch;
                ++$written;

                if ($inBatch >= self::BULK_SIZE) {
                    $this->flushBulk($bulk, $index);
                    $bulk = '';
                    $inBatch = 0;
                }
            }

            if ($inBatch > 0) {
                $this->flushBulk($bulk, $index);
            }

            $this->request('POST', '/' . rawurlencode($index) . '/_refresh');
        } finally {
            fclose($fh);
        }

        return $written;
    }

    private function flushBulk(string $ndjson, string $index): void
    {
        [$code, $body] = $this->rawRequest('POST', '/_bulk', $ndjson, 'application/x-ndjson');

        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException("Bulk index into '{$index}' failed (HTTP {$code}).");
        }

        /** @var array{errors?: bool} $decoded */
        $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR) ?: [];
        if (($decoded['errors'] ?? false) === true) {
            throw new \RuntimeException("Bulk index into '{$index}' reported per-document errors.");
        }
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array{0: int, 1: string}
     */
    private function request(string $method, string $path, ?array $body = null, ?int $timeout = null): array
    {
        return $this->rawRequest(
            $method,
            $path,
            $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR),
            'application/json',
            $timeout,
        );
    }

    /** @return array{0: int, 1: string} */
    private function rawRequest(string $method, string $path, ?string $payload, string $contentType, ?int $timeout = null): array
    {
        $base = $this->baseUrl();
        if ($base === '') {
            throw new \RuntimeException('ELASTICSEARCH_URL is not configured.');
        }

        $ch = curl_init($base . $path);
        if ($ch === false) {
            throw new \RuntimeException('Could not initialise a request to Elasticsearch.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => $timeout ?? self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Content-Type: ' . $contentType],
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Elasticsearch request failed: ' . $error);
        }

        return [$code, (string) $response];
    }
}
