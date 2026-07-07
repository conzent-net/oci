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

        // Normalise domains and collect them for a batch global lookup.
        $domains = [];
        foreach ($beacons as $i => $b) {
            $domain = $this->normalizeDomain((string) ($b['beacon_url'] ?? ''));
            $beacons[$i]['domain'] = $domain;
            if ($domain !== '') {
                $domains[$domain] = true;
            }
        }

        $globalByDomain = [];
        if ($domains !== []) {
            $names = array_keys($domains);
            $placeholders = implode(',', array_fill(0, count($names), '?'));
            $rows = $this->db->fetchAllAssociative(
                "SELECT bg.domain, bg.platform, bg.description, cc.slug AS category_slug, cct.name AS category_name
                 FROM oci_beacons_global bg
                 INNER JOIN oci_cookie_categories cc ON bg.category_id = cc.id
                 LEFT JOIN oci_cookie_category_translations cct ON cc.id = cct.category_id AND cct.language_id = 1
                 WHERE bg.domain IN ($placeholders)",
                array_values($names),
            );
            foreach ($rows as $row) {
                $globalByDomain[$row['domain']] = $row;
            }
        }

        $nameBySlug = $this->slugNameMap();

        foreach ($beacons as $i => $b) {
            $domain = $b['domain'];
            if ($domain !== '' && isset($globalByDomain[$domain])) {
                $g = $globalByDomain[$domain];
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
        $domain = $this->normalizeDomain($domain);
        if ($domain === '') {
            return null;
        }

        $row = $this->db->fetchAssociative(
            'SELECT bg.*, cc.slug AS category_slug
             FROM oci_beacons_global bg
             LEFT JOIN oci_cookie_categories cc ON bg.category_id = cc.id
             WHERE bg.domain = :domain
             LIMIT 1',
            ['domain' => $domain],
        );

        return $row ?: null;
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
    ): void {
        $domain = $this->normalizeDomain($domain);
        if ($domain === '') {
            return;
        }

        $existingId = $this->db->fetchOne(
            'SELECT id FROM oci_beacons_global WHERE domain = :domain LIMIT 1',
            ['domain' => $domain],
        );

        if ($existingId !== false && $existingId !== null) {
            $fields = ['category_id' => $categoryId];
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
            'category_id' => $categoryId,
            'platform' => $platform ?: null,
            'description' => $description ?: null,
        ]);
    }

    public function getUnclassifiedBacklog(int $page = 1, int $perPage = 25, ?string $search = null): array
    {
        // Domains already classified globally are excluded from the backlog.
        $classified = [];
        foreach ($this->db->fetchFirstColumn(
            'SELECT domain FROM oci_beacons_global WHERE category_id IS NOT NULL',
        ) as $d) {
            $classified[strtolower((string) $d)] = true;
        }

        $rows = $this->db->fetchAllAssociative(
            "SELECT beacon_url, site_id, last_seen_at FROM oci_beacons WHERE beacon_url <> ''",
        );

        $agg = [];
        foreach ($rows as $r) {
            $domain = $this->normalizeDomain((string) $r['beacon_url']);
            if ($domain === '' || isset($classified[$domain])) {
                continue;
            }
            if (!isset($agg[$domain])) {
                $agg[$domain] = ['domain' => $domain, 'occurrences' => 0, 'siteSet' => [], 'last_seen' => null];
            }
            $agg[$domain]['occurrences']++;
            $agg[$domain]['siteSet'][(int) $r['site_id']] = true;
            $seen = $r['last_seen_at'];
            if ($seen !== null && ($agg[$domain]['last_seen'] === null || $seen > $agg[$domain]['last_seen'])) {
                $agg[$domain]['last_seen'] = $seen;
            }
        }

        $items = [];
        $term = $search !== null ? strtolower(trim($search)) : '';
        foreach ($agg as $domain => $data) {
            if ($term !== '' && !str_contains($domain, $term)) {
                continue;
            }
            $items[] = [
                'domain' => $data['domain'],
                'occurrences' => $data['occurrences'],
                'sites' => count($data['siteSet']),
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
