<?php

declare(strict_types=1);

namespace OCI\Scanning\Repository;

use Doctrine\DBAL\Connection;

final class BeaconClassificationRepository implements BeaconClassificationRepositoryInterface
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    public function normalizeDomain(string $url): string
    {
        $url = strtolower(trim($url));
        if ($url === '') {
            return '';
        }
        if (!str_contains($url, '://')) {
            $url = 'http://' . $url;
        }
        $host = parse_url($url, PHP_URL_HOST);

        return \is_string($host) ? ltrim($host, '.') : '';
    }

    public function resolveBeacons(array $beacons): array
    {
        if ($beacons === []) {
            return $beacons;
        }

        // Load every classified entry once; match host by label suffix and
        // path against the entry's pattern — a path-scoped entry (marketing
        // on www.google.com/pagead/*) wins over a whole-domain one, so one
        // domain can carry scripts of different categories.
        $index = $this->classifiedEntryIndex();
        $nameBySlug = $this->slugNameMap();

        foreach ($beacons as $i => $b) {
            $identity = $this->splitIdentity((string) ($b['beacon_url'] ?? ''));
            $beacons[$i]['domain'] = $identity['domain'];

            $g = $identity['domain'] !== ''
                ? $this->matchEntry($identity['domain'], $identity['pattern'], $index)
                : null;

            if ($g !== null) {
                $beacons[$i]['resolved_slug'] = $g['category_slug'];
                $beacons[$i]['resolved_name'] = $g['category_name'] ?? ucfirst((string) $g['category_slug']);
                $beacons[$i]['is_global'] = true;
                $beacons[$i]['global_platform'] = $g['platform'];
                $beacons[$i]['global_description'] = $g['description'];
                continue;
            }

            $type = strtolower(trim((string) ($b['beacon_type'] ?? '')));
            if ($type !== '' && $type !== 'unclassified') {
                $beacons[$i]['resolved_slug'] = $type;
                $beacons[$i]['resolved_name'] = $nameBySlug[$type] ?? ucfirst($type);
            } else {
                $beacons[$i]['resolved_slug'] = 'unclassified';
                $beacons[$i]['resolved_name'] = 'Unclassified';
            }
            $beacons[$i]['is_global'] = false;
        }

        return $beacons;
    }

    public function matchGlobal(string $domain): ?array
    {
        $identity = $this->splitIdentity($domain);
        if ($identity['domain'] === '') {
            return null;
        }

        return $this->matchEntry($identity['domain'], $identity['pattern'], $this->classifiedEntryIndex());
    }

    /**
     * All classified entries, indexed for matching: path-pattern entries
     * grouped by domain (longest pattern first), whole-domain entries keyed
     * by domain.
     *
     * @return array{patterns: array<string, list<array<string, mixed>>>, domains: array<string, array<string, mixed>>}
     */
    private function classifiedEntryIndex(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT bg.*, cc.slug AS category_slug, cct.name AS category_name
             FROM oci_beacons_global bg
             INNER JOIN oci_cookie_categories cc ON bg.category_id = cc.id
             LEFT JOIN oci_cookie_category_translations cct ON cc.id = cct.category_id AND cct.language_id = 1',
        );

        $patterns = [];
        $domains = [];
        foreach ($rows as $row) {
            $domain = strtolower((string) $row['domain']);
            $pattern = (string) ($row['url_pattern'] ?? '');
            if ($pattern === '') {
                $domains[$domain] = $row;
            } else {
                $patterns[$domain][] = $row;
            }
        }

        foreach ($patterns as &$list) {
            usort($list, static fn(array $a, array $b): int => \strlen((string) $b['url_pattern']) <=> \strlen((string) $a['url_pattern']));
        }
        unset($list);

        return ['patterns' => $patterns, 'domains' => $domains];
    }

    /**
     * Most specific classified entry for an observed beacon, or null.
     * Path-scoped entries always beat whole-domain ones; within each, hosts
     * match by label suffix (an apex entry covers every subdomain) and
     * patterns use fnmatch (* wildcards), longest pattern first. An
     * observation with no stored path can only match whole-domain entries.
     *
     * @param array{patterns: array<string, list<array<string, mixed>>>, domains: array<string, array<string, mixed>>} $index
     * @return array<string, mixed>|null
     */
    private function matchEntry(string $host, string $path, array $index): ?array
    {
        $labels = explode('.', $host);
        $count = \count($labels);

        if ($path !== '') {
            for ($i = 0; $i <= $count - 2; $i++) {
                $candidate = implode('.', \array_slice($labels, $i));
                foreach ($index['patterns'][$candidate] ?? [] as $row) {
                    if (fnmatch((string) $row['url_pattern'], $path)) {
                        return $row;
                    }
                }
            }
        }

        for ($i = 0; $i <= $count - 2; $i++) {
            $candidate = implode('.', \array_slice($labels, $i));
            if (isset($index['domains'][$candidate])) {
                return $index['domains'][$candidate];
            }
        }

        return null;
    }

    public function findBeacon(int $beaconId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM oci_beacons WHERE id = :id',
            ['id' => $beaconId],
        );

        return $row ?: null;
    }

    public function classifyBeacon(int $beaconId, string $slug): void
    {
        $this->db->update('oci_beacons', ['beacon_type' => $slug], ['id' => $beaconId]);
    }

    public function applyGlobalClassification(
        string $domain,
        int $categoryId,
        ?string $platform = null,
        ?string $description = null,
        ?float $aiConfidence = null,
        string $classifiedBy = 'human',
    ): void {
        // The identity may be a bare domain (whole-domain classification) or
        // host/path[*] — one domain legitimately serves scripts of different
        // categories, so path patterns scope an entry to specific scripts.
        ['domain' => $domain, 'pattern' => $pattern] = $this->splitIdentity($domain);
        if ($domain === '') {
            return;
        }

        // The AI assistant reports percent; the review bar compares fractions.
        if ($aiConfidence !== null && $aiConfidence > 1) {
            $aiConfidence /= 100;
        }

        // AI classifications under the 80% bar go to the weekly human review.
        $needsReview = ($classifiedBy === 'ai' && $aiConfidence !== null && $aiConfidence < 0.8) ? 1 : 0;

        $existingId = $this->db->fetchOne(
            'SELECT id FROM oci_beacons_global WHERE domain = :domain AND url_pattern = :pattern LIMIT 1',
            ['domain' => $domain, 'pattern' => $pattern],
        );

        if ($existingId !== false && $existingId !== null) {
            $fields = [
                'category_id' => $categoryId,
                'classified_by' => $classifiedBy,
                'ai_confidence' => $aiConfidence,
                'needs_review' => $needsReview,
            ];
            if ($platform !== null && $platform !== '') {
                $fields['platform'] = $platform;
            }
            if ($description !== null && $description !== '') {
                $fields['description'] = $description;
            }
            $this->db->update('oci_beacons_global', $fields, ['id' => (int) $existingId]);

            return;
        }

        $this->db->insert('oci_beacons_global', [
            'domain' => $domain,
            'url_pattern' => $pattern,
            'category_id' => $categoryId,
            'platform' => $platform ?: null,
            'description' => $description ?: null,
            'classified_by' => $classifiedBy,
            'ai_confidence' => $aiConfidence,
            'needs_review' => $needsReview,
        ]);
    }

    /**
     * Split a classification identity into domain + optional path pattern.
     * Accepts 'doubleclick.net', 'www.google.com/recaptcha/*' or a pasted
     * full URL; the query string is dropped, a bare or trailing '/' means
     * whole-domain.
     *
     * @return array{domain: string, pattern: string}
     */
    public function splitIdentity(string $input): array
    {
        $input = trim($input);
        if (str_contains($input, '://')) {
            $input = substr($input, strpos($input, '://') + 3);
        }

        $slash = strpos($input, '/');
        if ($slash === false) {
            return ['domain' => $this->normalizeDomain($input), 'pattern' => ''];
        }

        $pattern = substr($input, $slash);
        if (($q = strpos($pattern, '?')) !== false) {
            $pattern = substr($pattern, 0, $q);
        }
        $pattern = rtrim($pattern, '/');

        return [
            'domain' => $this->normalizeDomain(substr($input, 0, $slash)),
            'pattern' => $pattern,
        ];
    }

    /**
     * Global beacon entries flagged for the weekly human review.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getNeedsReview(int $limit = 100): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT bg.id, bg.domain, bg.url_pattern, bg.platform, bg.description, bg.ai_confidence, bg.category_id,
                    COALESCE(ct.name, cc.slug) AS category_name
             FROM oci_beacons_global bg
             LEFT JOIN oci_cookie_categories cc ON cc.id = bg.category_id
             LEFT JOIN oci_cookie_category_translations ct ON ct.category_id = cc.id AND ct.language_id = 1
             WHERE bg.needs_review = 1
             ORDER BY bg.ai_confidence ASC, bg.id ASC
             LIMIT :limit',
            ['limit' => $limit],
            [
                'limit' => \Doctrine\DBAL\ParameterType::INTEGER,
            ],
        );
    }

    public function countNeedsReview(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM oci_beacons_global WHERE needs_review = 1');
    }

    /**
     * A human confirmed (or corrected) the entry — clear the review flag.
     */
    public function markReviewed(int $id, ?int $categoryId = null): void
    {
        $fields = ['needs_review' => 0, 'classified_by' => 'human'];
        if ($categoryId !== null) {
            $fields['category_id'] = $categoryId;
        }
        $this->db->update('oci_beacons_global', $fields, ['id' => $id]);
    }

    /**
     * Paginated list of classified global beacons for the admin database page.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function listGlobalClassified(int $page = 1, int $perPage = 50, ?string $search = null): array
    {
        $where = 'bg.category_id IS NOT NULL';
        $params = [];
        $types = [];
        if ($search !== null && $search !== '') {
            $where .= ' AND (bg.domain LIKE :search OR bg.platform LIKE :search2)';
            $like = '%' . $search . '%';
            $params += ['search' => $like, 'search2' => $like];
        }

        $total = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM oci_beacons_global bg WHERE {$where}",
            $params,
        );

        $params += ['limit' => $perPage, 'offset' => ($page - 1) * $perPage];
        $types += ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER];

        $items = $this->db->fetchAllAssociative(
            "SELECT bg.id, bg.domain, bg.url_pattern, bg.platform, bg.description,
                    bg.classified_by, bg.ai_confidence, bg.needs_review,
                    bg.category_id, COALESCE(ct.name, cc.slug) AS category_name, cc.slug AS category_slug
             FROM oci_beacons_global bg
             LEFT JOIN oci_cookie_categories cc ON cc.id = bg.category_id
             LEFT JOIN oci_cookie_category_translations ct ON ct.category_id = cc.id AND ct.language_id = 1
             WHERE {$where}
             ORDER BY bg.domain ASC
             LIMIT :limit OFFSET :offset",
            $params,
            $types,
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Admin edit of a global beacon entry by id. AI re-lookups pass their
     * confidence and 'ai'; manual edits count as human-verified.
     */
    public function updateGlobalEntry(
        int $id,
        string $domain,
        int $categoryId,
        ?string $platform,
        ?string $description,
        ?float $aiConfidence = null,
        string $classifiedBy = 'human',
    ): void {
        if ($aiConfidence !== null && $aiConfidence > 1) {
            $aiConfidence /= 100;
        }
        $needsReview = ($classifiedBy === 'ai' && $aiConfidence !== null && $aiConfidence < 0.8) ? 1 : 0;

        // The edited identity may carry a path pattern (host/pagead/*).
        $identity = $this->splitIdentity($domain);

        $this->db->update('oci_beacons_global', [
            'domain' => $identity['domain'],
            'url_pattern' => $identity['pattern'],
            'category_id' => $categoryId,
            'platform' => $platform !== '' ? $platform : null,
            'description' => $description !== '' ? $description : null,
            'classified_by' => $classifiedBy,
            'ai_confidence' => $aiConfidence,
            'needs_review' => $needsReview,
        ], ['id' => $id]);
    }

    /**
     * Delete a global entry. The domain returns to the unclassified backlog
     * if sites still observe it.
     */
    public function deleteGlobalEntry(int $id): void
    {
        $this->db->delete('oci_beacons_global', ['id' => $id]);
    }

    public function getUnclassifiedBacklog(int $page = 1, int $perPage = 25, ?string $search = null): array
    {
        // Anything already classified globally is excluded — hosts by label
        // suffix (a classified google-analytics.com swallows region1./www./
        // etc.) and paths against pattern entries, so classifying one script
        // on a domain leaves that domain's OTHER scripts in the backlog.
        $index = $this->classifiedEntryIndex();

        $rows = $this->db->fetchAllAssociative(
            "SELECT beacon_url, beacon_type, site_id, last_seen_at FROM oci_beacons WHERE beacon_url <> ''",
        );

        // Aggregate by registrable domain so subdomain variants collapse into
        // one row (classifying it covers them all via the suffix match).
        $agg = [];
        foreach ($rows as $r) {
            $identity = $this->splitIdentity((string) $r['beacon_url']);
            $host = $identity['domain'];
            if ($host === '' || $this->matchEntry($host, $identity['pattern'], $index) !== null) {
                continue;
            }
            $domain = $this->registrableDomain($host);
            if (!isset($agg[$domain])) {
                $agg[$domain] = [
                    'domain' => $domain,
                    'occurrences' => 0,
                    'siteSet' => [],
                    'hostSet' => [],
                    'typeSet' => [],
                    'sample_urls' => [],
                    'last_seen' => null,
                ];
            }
            $agg[$domain]['occurrences']++;
            $agg[$domain]['siteSet'][(int) $r['site_id']] = true;
            $agg[$domain]['hostSet'][$host] = true;
            $type = trim((string) ($r['beacon_type'] ?? ''));
            if ($type !== '') {
                $agg[$domain]['typeSet'][$type] = true;
            }
            // A few full URLs so a human can tell a tracking pixel from a CDN
            // library at a glance.
            $url = (string) $r['beacon_url'];
            if (\count($agg[$domain]['sample_urls']) < 3 && !\in_array($url, $agg[$domain]['sample_urls'], true)) {
                $agg[$domain]['sample_urls'][] = mb_substr($url, 0, 160);
            }
            $seen = $r['last_seen_at'];
            if ($seen !== null && ($agg[$domain]['last_seen'] === null || $seen > $agg[$domain]['last_seen'])) {
                $agg[$domain]['last_seen'] = $seen;
            }
        }

        $items = [];
        $term = $search !== null ? strtolower(trim($search)) : '';
        foreach ($agg as $domain => $data) {
            // Search matches the parent domain, any host variant, or a
            // sample URL — rows aggregate under the registrable domain, so
            // a subdomain search must still find them.
            if ($term !== '') {
                $haystack = $domain . ' ' . implode(' ', array_keys($data['hostSet'])) . ' ' . strtolower(implode(' ', $data['sample_urls']));
                if (!str_contains($haystack, $term)) {
                    continue;
                }
            }
            $items[] = [
                'domain' => $data['domain'],
                'occurrences' => $data['occurrences'],
                'sites' => count($data['siteSet']),
                'hosts' => array_keys($data['hostSet']),
                'types' => array_keys($data['typeSet']),
                'sample_urls' => $data['sample_urls'],
                'last_seen' => $data['last_seen'],
            ];
        }

        usort($items, static fn(array $a, array $b): int => $b['occurrences'] <=> $a['occurrences'] ?: strcmp($a['domain'], $b['domain']));

        $total = count($items);
        $offset = ($page - 1) * $perPage;

        return [
            'items' => array_slice($items, $offset, $perPage),
            'total' => $total,
        ];
    }

    /**
     * Registrable-domain approximation: the last two labels, or three when the
     * TLD is a known second-level registry (co.uk, com.au, ...). Not a full
     * public-suffix list, but right for the domains beacons actually use —
     * and the admin can always edit the domain before classifying.
     */
    private function registrableDomain(string $host): string
    {
        static $secondLevel = [
            'co.uk' => true, 'org.uk' => true, 'gov.uk' => true, 'ac.uk' => true, 'me.uk' => true,
            'com.au' => true, 'net.au' => true, 'org.au' => true,
            'co.jp' => true, 'ne.jp' => true, 'or.jp' => true,
            'co.nz' => true, 'co.za' => true, 'co.in' => true, 'co.kr' => true,
            'com.br' => true, 'com.mx' => true, 'com.tr' => true, 'com.cn' => true,
            'com.sg' => true, 'com.hk' => true, 'com.tw' => true, 'com.ar' => true,
        ];

        $labels = explode('.', $host);
        $count = \count($labels);
        if ($count <= 2) {
            return $host;
        }

        $lastTwo = $labels[$count - 2] . '.' . $labels[$count - 1];
        $keep = isset($secondLevel[$lastTwo]) ? 3 : 2;

        return implode('.', \array_slice($labels, $count - min($keep, $count)));
    }

    /**
     * @return array<string, string> slug => display name
     */
    private function slugNameMap(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT cc.slug, cct.name
             FROM oci_cookie_categories cc
             LEFT JOIN oci_cookie_category_translations cct ON cc.id = cct.category_id AND cct.language_id = 1',
        );
        $map = [];
        foreach ($rows as $row) {
            if (!empty($row['name'])) {
                $map[$row['slug']] = $row['name'];
            }
        }

        return $map;
    }
}
