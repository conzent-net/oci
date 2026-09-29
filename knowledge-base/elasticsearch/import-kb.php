<?php

declare(strict_types=1);

/**
 * Conzent Knowledge Base importer.
 *
 * Parses every frontmatter Markdown file under knowledge-base/articles/ and bulk-indexes
 * it into Elasticsearch, optionally attaching embeddings for hybrid search.
 *
 *   php knowledge-base/elasticsearch/import-kb.php               upsert changed articles
 *   php knowledge-base/elasticsearch/import-kb.php --recreate    drop + recreate the index first
 *   php knowledge-base/elasticsearch/import-kb.php --dry-run     parse and validate, index nothing
 *   php knowledge-base/elasticsearch/import-kb.php --force       re-embed everything
 *
 * Environment:
 *   ELASTICSEARCH_URL   default http://localhost:9200
 *   KB_INDEX            default conzent-kb-v2  (alias conzent-kb should point here)
 *   KB_EMBED_URL        OpenAI-compatible embeddings endpoint; unset = index without vectors
 *   KB_EMBED_MODEL      default text-embedding-3-small
 *   KB_EMBED_KEY        bearer token for the embeddings endpoint
 *
 * No Composer dependencies — plain cURL, so it runs inside the app container as-is.
 */

const KB_ROOT      = __DIR__ . '/../articles';
const MAPPING_FILE = __DIR__ . '/mapping.conzent-kb.json';

$esUrl      = rtrim((string) (getenv('ELASTICSEARCH_URL') ?: 'http://localhost:9200'), '/');
$index      = (string) (getenv('KB_INDEX') ?: 'conzent-kb-v2');
$embedUrl   = (string) (getenv('KB_EMBED_URL') ?: '');
$embedModel = (string) (getenv('KB_EMBED_MODEL') ?: 'text-embedding-3-small');
$embedKey   = (string) (getenv('KB_EMBED_KEY') ?: '');

$argvFlags = $argv ?? [];
$recreate  = in_array('--recreate', $argvFlags, true);
$dryRun    = in_array('--dry-run', $argvFlags, true);
$force     = in_array('--force', $argvFlags, true);

// ── Required frontmatter keys. A missing one is a hard error: the retrieval
//    contract in README.md depends on every document carrying them.
const REQUIRED_KEYS = ['id', 'title', 'area', 'knowledgebase', 'url', 'menu_path', 'edition', 'audience', 'plan', 'tags', 'questions'];

// ── Folder → knowledgebase name. Each folder ships as its own knowledge base,
//    so `knowledgebase:` must match the folder the article lives in, and every
//    cross-document reference names the target KB rather than a relative path.
const KNOWLEDGEBASES = [
    '00-platform'     => 'Platform',
    '10-account'      => 'Account',
    '20-sites'        => 'Sites',
    '30-banner'       => 'Banner',
    '40-cookies'      => 'Cookies',
    '50-consent'      => 'Consent',
    '60-compliance'   => 'Compliance',
    '70-integrations' => 'Integrations',
    '80-growth'       => 'Growth',
    '90-plugins'      => 'Plugins',
    '95-self-hosting' => 'Self-Hosting',
];

// ─────────────────────────────────────────────────────────────────────────────

function out(string $msg): void
{
    echo $msg . PHP_EOL;
}

function fail(string $msg): never
{
    fwrite(STDERR, "ERROR: {$msg}" . PHP_EOL);
    exit(1);
}

/**
 * @param array<string, string> $headers
 * @return array{status: int, body: string}
 */
function http(string $method, string $url, ?string $body = null, array $headers = []): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        fail('cURL init failed');
    }

    $hdrs = ['Content-Type: application/json'];
    foreach ($headers as $k => $v) {
        $hdrs[] = "{$k}: {$v}";
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => $hdrs,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $resp   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        fail("HTTP {$method} {$url} failed: {$err}");
    }

    return ['status' => $status, 'body' => (string) $resp];
}

/**
 * Minimal YAML frontmatter reader.
 *
 * Deliberately supports only what the article contract uses: scalars,
 * inline arrays [a, b], and block lists of scalars. Anything else is a
 * schema violation we would rather surface than silently accept.
 *
 * @return array{0: array<string, mixed>, 1: string}
 */
function parseFrontmatter(string $raw): array
{
    if (!str_starts_with($raw, '---')) {
        return [[], $raw];
    }

    $parts = preg_split('/^---\s*$/m', $raw, 3);
    if ($parts === false || count($parts) < 3) {
        return [[], $raw];
    }

    $meta    = [];
    $lastKey = null;

    foreach (explode("\n", $parts[1]) as $line) {
        if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
            continue;
        }

        // block-list item: "  - value"
        if (preg_match('/^\s+-\s+(.*)$/', $line, $m) === 1 && $lastKey !== null) {
            if (!is_array($meta[$lastKey] ?? null)) {
                $meta[$lastKey] = [];
            }
            $meta[$lastKey][] = unquote(trim($m[1]));
            continue;
        }

        if (preg_match('/^([A-Za-z0-9_]+):\s*(.*)$/', $line, $m) !== 1) {
            continue;
        }

        $key   = $m[1];
        $value = trim($m[2]);
        $lastKey = $key;

        if ($value === '') {
            $meta[$key] = [];          // block list follows
        } elseif (str_starts_with($value, '[')) {
            $inner = trim($value, "[] \t");
            $meta[$key] = $inner === ''
                ? []
                : array_map(static fn(string $v): string => unquote(trim($v)), explode(',', $inner));
        } else {
            $meta[$key] = unquote($value);
        }
    }

    return [$meta, ltrim($parts[2], "\n")];
}

function unquote(string $v): string
{
    if (strlen($v) >= 2) {
        $first = $v[0];
        $last  = $v[strlen($v) - 1];
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            return substr($v, 1, -1);
        }
    }

    return $v;
}

/** First paragraph under "## What it does" — the agent-quotable summary. */
function extractSummary(string $body): string
{
    if (preg_match('/^##\s+What it does\s*$(.*?)(?=^##\s)/ms', $body, $m) === 1) {
        foreach (explode("\n\n", trim($m[1])) as $para) {
            $para = trim($para);
            if ($para !== '' && !str_starts_with($para, '>') && !str_starts_with($para, '|')) {
                return preg_replace('/\s+/', ' ', $para) ?? $para;
            }
        }
    }

    $plain = preg_replace('/^#.*$/m', '', $body) ?? $body;

    return trim(mb_substr(preg_replace('/\s+/', ' ', $plain) ?? $plain, 0, 400));
}

/**
 * Every control named in the leftmost column of a "## Fields" table.
 * Powers the "where do I find X" lookup in queries.md.
 *
 * @return list<string>
 */
function extractFieldNames(string $body): array
{
    $names = [];
    $inFieldsTable = false;

    foreach (explode("\n", $body) as $line) {
        $trimmed = trim($line);

        if (preg_match('/^##+\s/', $trimmed) === 1) {
            $inFieldsTable = stripos($trimmed, 'Fields') !== false
                || stripos($trimmed, 'Controls') !== false
                || stripos($trimmed, 'Settings') !== false
                || stripos($trimmed, 'Columns') !== false;
            continue;
        }

        if (!$inFieldsTable || !str_starts_with($trimmed, '|')) {
            continue;
        }
        if (preg_match('/^\|[\s\-:|]+\|$/', $trimmed) === 1) {
            continue; // separator row
        }

        $cells = array_map('trim', explode('|', trim($trimmed, '|')));
        $first = $cells[0] ?? '';
        // strip markdown emphasis / code ticks
        $first = trim(preg_replace('/[`*_]/', '', $first) ?? $first);

        if ($first !== '' && strcasecmp($first, 'Field') !== 0 && strcasecmp($first, 'Column') !== 0) {
            $names[] = $first;
        }
    }

    return array_values(array_unique($names));
}

/** @return list<float>|null */
function embed(string $text, string $url, string $model, string $key): ?array
{
    if ($url === '') {
        return null;
    }

    $payload = json_encode([
        'model' => $model,
        'input' => mb_substr($text, 0, 24000),
    ], JSON_THROW_ON_ERROR);

    $headers = $key !== '' ? ['Authorization' => "Bearer {$key}"] : [];
    $res     = http('POST', rtrim($url, '/'), $payload, $headers);

    if ($res['status'] >= 400) {
        fwrite(STDERR, "  ! embedding failed (HTTP {$res['status']}) — indexing without vectors\n");

        return null;
    }

    $decoded = json_decode($res['body'], true);
    $vec     = $decoded['data'][0]['embedding'] ?? null;

    return is_array($vec) ? array_map('floatval', $vec) : null;
}

/**
 * Every cross-document reference in the body, as [knowledgebase, document] pairs.
 *
 * Articles reference each other as "Knowledgebase: Sites - Document: install-script.md"
 * rather than by relative path, because each folder ships as an independent
 * knowledge base and relative paths do not survive that split.
 *
 * @return list<array{0: string, 1: string, 2: string}> [kb, doc, full match]
 */
function extractReferences(string $body): array
{
    // Case-insensitive on purpose: a reference typed with a lowercase "k" is a
    // typo we want flagged, not silently skipped. Frontmatter is already stripped
    // from $body, so the `knowledgebase:` key itself can never match here.
    if (preg_match_all(
        '/Knowledgebase:\s*([A-Za-z][A-Za-z\- ]*?)\s*-\s*Document:\s*([A-Za-z0-9\-]+\.md)/i',
        $body,
        $m,
        PREG_SET_ORDER,
    ) === false) {
        return [];
    }

    return array_map(
        static fn(array $set): array => [trim($set[1]), $set[2], $set[0]],
        $m,
    );
}

/** Strip the `_comment` documentation keys — ES 8 rejects unknown top-level keys. */
function stripComments(mixed $node): mixed
{
    if (!is_array($node)) {
        return $node;
    }

    $clean = [];
    foreach ($node as $k => $v) {
        if ($k === '_comment') {
            continue;
        }
        $clean[$k] = stripComments($v);
    }

    return $clean;
}

// ── 1. Collect articles ──────────────────────────────────────────────────────

if (!is_dir(KB_ROOT)) {
    fail('articles directory not found: ' . KB_ROOT);
}

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(KB_ROOT, FilesystemIterator::SKIP_DOTS));
$docs  = [];
$seen  = [];
$errors = [];

foreach ($files as $file) {
    if (!$file instanceof SplFileInfo || $file->getExtension() !== 'md') {
        continue;
    }

    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen(dirname(KB_ROOT)) + 1));
    [$meta, $body] = parseFrontmatter((string) file_get_contents($file->getPathname()));

    if ($meta === []) {
        $errors[] = "{$rel}: no YAML frontmatter";
        continue;
    }

    foreach (REQUIRED_KEYS as $key) {
        if (!array_key_exists($key, $meta) || $meta[$key] === '' || $meta[$key] === []) {
            $errors[] = "{$rel}: missing required frontmatter key '{$key}'";
        }
    }

    $id = (string) ($meta['id'] ?? '');
    if ($id === '') {
        continue;
    }
    if (isset($seen[$id])) {
        $errors[] = "{$rel}: duplicate id '{$id}' (also in {$seen[$id]})";
        continue;
    }
    $seen[$id] = $rel;

    // The folder is the knowledge base. A mismatch means the article would ship
    // in one KB while announcing itself as another.
    $folder      = basename(dirname($file->getPathname()));
    $expectedKb  = KNOWLEDGEBASES[$folder] ?? null;
    $declaredKb  = (string) ($meta['knowledgebase'] ?? '');

    if ($expectedKb === null) {
        $errors[] = "{$rel}: folder '{$folder}' is not a known knowledge base";
    } elseif ($declaredKb !== '' && $declaredKb !== $expectedKb) {
        $errors[] = "{$rel}: knowledgebase '{$declaredKb}' does not match folder ('{$expectedKb}')";
    }

    // Cross-document references must name a real document in a real KB.
    foreach (extractReferences($body) as [$refKb, $refDoc, $raw]) {
        $refFolder = array_search($refKb, KNOWLEDGEBASES, true);
        if ($refFolder === false) {
            $errors[] = "{$rel}: reference to unknown knowledgebase '{$refKb}' ({$raw})";
            continue;
        }
        if (!is_file(KB_ROOT . '/' . $refFolder . '/' . $refDoc)) {
            $errors[] = "{$rel}: reference '{$refKb} / {$refDoc}' does not exist";
        }
    }

    // Relative markdown links do not survive the split into separate KBs.
    if (preg_match('/\[[^\]]*\]\([^)]*\.md[^)]*\)/', $body, $m) === 1) {
        $errors[] = "{$rel}: relative markdown link found ({$m[0]}) — use "
            . '"Knowledgebase: <Name> - Document: <file.md>" instead';
    }

    $asList = static fn(mixed $v): array => is_array($v) ? array_values($v) : ($v === '' || $v === null ? [] : [$v]);

    $docs[] = [
        'id'             => $id,
        'title'          => (string) ($meta['title'] ?? $id),
        'body'           => $body,
        'summary'        => extractSummary($body),
        'questions'      => $asList($meta['questions'] ?? []),
        'fields_covered' => extractFieldNames($body),
        'area'           => (string) ($meta['area'] ?? 'General'),
        'knowledgebase'  => $expectedKb ?? $declaredKb,
        'url'            => (string) ($meta['url'] ?? ''),
        'menu_path'      => (string) ($meta['menu_path'] ?? ''),
        'edition'        => $asList($meta['edition'] ?? ['cloud', 'self-hosted']),
        'audience'       => $asList($meta['audience'] ?? ['customer']),
        'plan'           => (string) ($meta['plan'] ?? 'any'),
        'tags'           => $asList($meta['tags'] ?? []),
        'related'        => $asList($meta['related'] ?? []),
        'source_files'   => $asList($meta['source_files'] ?? []),
        'source'         => 'document',
        'reviewed'       => true,
        'content_hash'   => hash('sha256', $body),
        '_path'          => $rel,
    ];
}

usort($docs, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));

out(sprintf('Parsed %d article(s) from %s', count($docs), KB_ROOT));

if ($errors !== []) {
    foreach ($errors as $e) {
        fwrite(STDERR, "  ! {$e}\n");
    }
    if ($dryRun) {
        out('Dry run: ' . count($errors) . ' validation error(s).');
        exit(1);
    }
    fail(count($errors) . ' validation error(s) — fix the frontmatter and re-run.');
}

if ($dryRun) {
    $byKb = [];
    foreach ($docs as $d) {
        $byKb[$d['knowledgebase']][] = $d;
    }
    ksort($byKb);

    foreach ($byKb as $kbName => $kbDocs) {
        out(sprintf("\nKnowledgebase: %s  (%d document(s))", $kbName, count($kbDocs)));
        foreach ($kbDocs as $d) {
            printf(
                "  %-34s %-28s %-18s %s\n",
                $d['id'],
                $d['url'] !== '' ? $d['url'] : '(no url)',
                implode('/', $d['edition']),
                basename($d['_path'])
            );
        }
    }

    out(sprintf("\nDry run OK — %d document(s) across %d knowledge base(s). Nothing indexed.", count($docs), count($byKb)));
    exit(0);
}

// ── 2. Recreate the index if asked ───────────────────────────────────────────

if ($recreate) {
    $mapping = json_decode((string) file_get_contents(MAPPING_FILE), true, 512, JSON_THROW_ON_ERROR);
    $mapping = stripComments($mapping);

    http('DELETE', "{$esUrl}/{$index}");
    $res = http('PUT', "{$esUrl}/{$index}", json_encode($mapping, JSON_THROW_ON_ERROR));
    if ($res['status'] >= 400) {
        fail("index create failed (HTTP {$res['status']}): {$res['body']}");
    }
    out("Recreated index {$index}");
}

// ── 3. Skip unchanged articles unless --force ────────────────────────────────

$existing = [];
if (!$recreate && !$force) {
    $res = http('POST', "{$esUrl}/{$index}/_search", json_encode([
        'size'    => 1000,
        '_source' => ['id', 'content_hash'],
        'query'   => ['match_all' => (object) []],
    ], JSON_THROW_ON_ERROR));

    if ($res['status'] < 400) {
        foreach (json_decode($res['body'], true)['hits']['hits'] ?? [] as $hit) {
            $existing[$hit['_id']] = $hit['_source']['content_hash'] ?? '';
        }
    }
}

$pending = array_values(array_filter(
    $docs,
    static fn(array $d): bool => ($existing[$d['id']] ?? null) !== $d['content_hash']
));

$skipped = count($docs) - count($pending);
if ($skipped > 0) {
    out("Skipping {$skipped} unchanged article(s)");
}
if ($pending === []) {
    out('Nothing to do.');
    exit(0);
}

// ── 4. Embed + bulk index ────────────────────────────────────────────────────

if ($embedUrl === '') {
    out('KB_EMBED_URL not set — indexing without vectors (BM25-only retrieval).');
}

$bulk = '';
foreach ($pending as $doc) {
    $id   = $doc['id'];
    $path = $doc['_path'];
    unset($doc['_path']);

    if ($embedUrl !== '') {
        $bodyVec = embed($doc['title'] . "\n\n" . $doc['body'], $embedUrl, $embedModel, $embedKey);
        $qVec    = embed($doc['title'] . "\n" . implode("\n", $doc['questions']), $embedUrl, $embedModel, $embedKey);
        if ($bodyVec !== null) {
            $doc['body_vector'] = $bodyVec;
        }
        if ($qVec !== null) {
            $doc['questions_vector'] = $qVec;
        }
    }

    $doc['updatedAt'] = date('c');
    $doc['createdAt'] = date('c');

    $bulk .= json_encode(['index' => ['_index' => $index, '_id' => $id]], JSON_THROW_ON_ERROR) . "\n";
    $bulk .= json_encode($doc, JSON_THROW_ON_ERROR) . "\n";

    out("  + {$id}  ({$path})");
}

$res = http('POST', "{$esUrl}/_bulk", $bulk);
if ($res['status'] >= 400) {
    fail("bulk index failed (HTTP {$res['status']}): " . substr($res['body'], 0, 800));
}

$decoded = json_decode($res['body'], true);
if (($decoded['errors'] ?? false) === true) {
    foreach ($decoded['items'] ?? [] as $item) {
        $err = $item['index']['error'] ?? null;
        if ($err !== null) {
            fwrite(STDERR, "  ! {$item['index']['_id']}: {$err['type']} — {$err['reason']}\n");
        }
    }
    fail('bulk index completed with errors.');
}

http('POST', "{$esUrl}/{$index}/_refresh");

out(sprintf('Indexed %d article(s) into %s.', count($pending), $index));
out("Point the alias if you have not yet:  POST {$esUrl}/_aliases "
    . '{"actions":[{"add":{"index":"' . $index . '","alias":"conzent-kb"}}]}');
