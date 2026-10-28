<?php

declare(strict_types=1);

namespace OCI\Cookie\Service;

use Doctrine\DBAL\Connection;
use OCI\Infrastructure\Filesystem\OwnedFile;
use Psr\Log\LoggerInterface;
use Twig\Environment as TwigEnvironment;

/**
 * The public cookie database: one static page per classified cookie at
 * getconzent.com/cookie/<name>/, plus an A–Z index, a page per vendor, a
 * not-found page and a sitemap.
 *
 * Every page is written as a file and served by nginx, so a visitor never
 * touches PHP or the database. The scheduler regenerates everything nightly;
 * classifying a cookie in the admin refreshes that cookie's page at once.
 *
 * What makes the pages worth indexing: each answers what the cookie does,
 * who sets it, which consent category it belongs to and how long it lives,
 * in its own words, with links to the vendor's other cookies. Cookies with
 * no description or no category are left out rather than published thin.
 */
final class CookiePageGenerator
{
    public const BASE_URL = 'https://getconzent.com/cookie';

    public const OUTPUT_DIR = '/public/cookie';

    private const CATEGORY_LABEL = [
        'necessary' => 'Strictly necessary',
        'functional' => 'Functional',
        'preferences' => 'Functional',
        'analytics' => 'Analytics',
        'performance' => 'Analytics',
        'marketing' => 'Marketing',
    ];

    private const CATEGORY_TEXT = [
        'necessary' => 'Strictly necessary cookies keep a site working: sessions, security, load balancing and remembering the visitor\'s consent choice. They are exempt from consent under the ePrivacy rules, but a cookie policy still has to list them.',
        'functional' => 'Functional cookies remember a choice the visitor made, such as a language, a region or a layout preference. They are not essential, so visitors in the EU must be able to decline them, and a site should not set them before consent.',
        'preferences' => 'Functional cookies remember a choice the visitor made, such as a language, a region or a layout preference. They are not essential, so visitors in the EU must be able to decline them, and a site should not set them before consent.',
        'analytics' => 'Analytics cookies measure how a site is used, in aggregate: pages seen, sessions, where visitors came from. Under the GDPR they need consent before they are set, and Google Consent Mode can model what is lost when visitors decline.',
        'performance' => 'Analytics cookies measure how a site is used, in aggregate: pages seen, sessions, where visitors came from. Under the GDPR they need consent before they are set, and Google Consent Mode can model what is lost when visitors decline.',
        'marketing' => 'Marketing cookies follow visitors across sites to build advertising profiles and to measure campaigns. They always require prior consent, and a consent banner has to block them until the visitor agrees.',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly TwigEnvironment $twig,
        private readonly CookiePageShell $shell,
        private readonly LoggerInterface $logger,
        private readonly string $basePath,
    ) {}

    /**
     * Whether this install publishes the cookie database on its own.
     *
     * Off unless the global setting `cookie_pages_enabled` is 1. The pages
     * carry one specific site's header, footer and addresses, so an install
     * that did not ask for them must never find them in its public folder.
     * Running the command by hand always works.
     */
    public function isEnabled(): bool
    {
        try {
            $value = $this->db->fetchOne(
                "SELECT config_value FROM oci_configuration WHERE scope = 'global' AND scope_id IS NULL AND config_key = ?",
                ['cookie_pages_enabled'],
            );
        } catch (\Throwable) {
            return false;
        }

        return (string) $value === '1';
    }

    /**
     * Write every page.
     *
     * @return array{pages: int, vendors: int, skipped: int}
     */
    public function publishAll(): array
    {
        $entries = self::entries($this->rows());
        $vendors = self::vendors($entries);
        $shell = $this->shell->load();
        $generated = date('Y-m-d');

        $pages = 0;
        foreach ($entries as $entry) {
            $this->writePage($shell, $entry, $entries, $generated);
            $pages++;
        }

        foreach ($vendors as $vendor) {
            $this->write('/vendor/' . $vendor['slug'] . '/index.html', CookiePageShell::wrap($shell, $this->twig->render('public/cookie/vendor.html.twig', [
                'vendor' => $vendor,
                'generated' => $generated,
            ]), [
                'title' => $vendor['name'] . ' cookies: what each one does',
                'description' => 'The ' . \count($vendor['cookies']) . ' cookies set by ' . $vendor['name'] . ', each with its purpose, consent category and lifetime.',
                'url' => self::BASE_URL . '/vendor/' . $vendor['slug'] . '/',
            ]));
        }

        $this->write('/index.html', CookiePageShell::wrap($shell, $this->twig->render('public/cookie/index.html.twig', [
            'groups' => self::groupByLetter($entries),
            'vendors' => \array_slice($vendors, 0, 40),
            'total' => \count($entries),
            'generated' => $generated,
        ]), [
            'title' => 'Cookie database: what ' . \count($entries) . ' cookies do and who sets them',
            'description' => 'Look up any browser cookie by name: its purpose, the company that sets it, its consent category under the GDPR and how long it lasts.',
            'url' => self::BASE_URL . '/',
        ]));

        $this->write('/not-found/index.html', CookiePageShell::wrap($shell, $this->twig->render('public/cookie/not-found.html.twig', []), [
            'title' => 'Cookie not found',
            'description' => 'We have not classified this cookie yet.',
            'url' => self::BASE_URL . '/not-found/',
            'robots' => 'noindex,follow',
        ]));

        $this->write('/sitemap.xml', self::sitemap($entries, $vendors, $generated));

        $skipped = \count($this->rows()) - array_sum(array_map(static fn (array $e): int => $e['rows'], $entries));
        $this->logger->info('Cookie pages published', ['pages' => $pages, 'vendors' => \count($vendors), 'skipped' => $skipped]);

        return ['pages' => $pages, 'vendors' => \count($vendors), 'skipped' => max(0, $skipped)];
    }

    /**
     * Refresh one cookie's page after it was classified. The index and the
     * sitemap follow at the nightly run.
     */
    public function publishOne(string $cookieName): bool
    {
        $entries = self::entries($this->rows());
        $entry = $entries[self::dirName($cookieName)] ?? null;
        if ($entry === null) {
            return false;
        }

        $shell = $this->shell->load();
        $this->writePage($shell, $entry, $entries, date('Y-m-d'));

        foreach ($entry['vendors'] as $vendor) {
            $all = self::vendors($entries);
            if (isset($all[$vendor['slug']])) {
                $this->write('/vendor/' . $vendor['slug'] . '/index.html', CookiePageShell::wrap($shell, $this->twig->render('public/cookie/vendor.html.twig', [
                    'vendor' => $all[$vendor['slug']],
                    'generated' => date('Y-m-d'),
                ]), [
                    'title' => $vendor['name'] . ' cookies: what each one does',
                    'description' => 'The cookies set by ' . $vendor['name'] . ', each with its purpose, consent category and lifetime.',
                    'url' => self::BASE_URL . '/vendor/' . $vendor['slug'] . '/',
                ]));
            }
        }

        return true;
    }

    /**
     * The classified cookies as pages, keyed by cookie name, sorted by name.
     *
     * Several rows can share a name (the same cookie seen from different
     * vendors or domains); they become one page listing every vendor. So do
     * a pattern and its bare prefix (`AMP_*` and `AMP_`): both live at the
     * same address, so they are one page too.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, array<string, mixed>> Keyed by directory name (see dirName())
     */
    public static function entries(array $rows): array
    {
        $byName = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row['cookie_name'] ?? ''));
            $category = (string) ($row['category_slug'] ?? '');
            $description = trim((string) ($row['description'] ?? ''));
            if ($name === '' || $description === '' || !isset(self::CATEGORY_LABEL[$category])) {
                continue;
            }
            $segment = self::urlSegment($name);
            if ($segment === null) {
                continue;
            }

            $key = self::dirName($name);
            $entry = $byName[$key] ?? [
                'name' => $name,
                'segment' => $segment,
                'path' => '/cookie/' . $segment . '/',
                'url' => self::BASE_URL . '/' . $segment . '/',
                'category' => $category,
                'category_label' => self::CATEGORY_LABEL[$category],
                'category_text' => self::CATEGORY_TEXT[$category],
                'descriptions' => [],
                'vendors' => [],
                'expiry' => '',
                'wildcard' => str_contains($name, '*') || (int) ($row['wildcard_match'] ?? 0) === 1,
                'rows' => 0,
            ];

            if (!\in_array($description, $entry['descriptions'], true)) {
                $entry['descriptions'][] = $description;
            }
            $platform = trim((string) ($row['platform'] ?? ''));
            if ($platform !== '') {
                $slug = self::vendorSlug($platform);
                $entry['vendors'][$slug] = [
                    'name' => $platform,
                    'slug' => $slug,
                    'controller' => trim((string) ($row['data_controller'] ?? '')),
                    'privacy_url' => self::safeUrl((string) ($row['privacy_url'] ?? '')),
                ];
            }
            if ($entry['expiry'] === '' && trim((string) ($row['expiry_duration'] ?? '')) !== '') {
                $entry['expiry'] = trim((string) $row['expiry_duration']);
            }
            $entry['wildcard'] = $entry['wildcard'] || str_contains($name, '*') || (int) ($row['wildcard_match'] ?? 0) === 1;
            $entry['rows']++;
            $byName[$key] = $entry;
        }

        foreach ($byName as &$entry) {
            $entry['description'] = $entry['descriptions'][0];
            $entry['vendors'] = array_values($entry['vendors']);
        }
        unset($entry);

        // Alphabetical as a reader expects it: `_ga` sorts under G, not under
        // the underscore, and case does not matter.
        uksort($byName, static function (string $a, string $b): int {
            $ka = strtolower(ltrim($a, '_.-'));
            $kb = strtolower(ltrim($b, '_.-'));

            return strnatcmp($ka, $kb) ?: strcmp($a, $b);
        });

        // Related cookies: up to eight others from the same vendor.
        $byVendor = [];
        foreach ($byName as $key => $entry) {
            foreach ($entry['vendors'] as $vendor) {
                $byVendor[$vendor['slug']][] = $key;
            }
        }
        foreach ($byName as $key => &$entry) {
            $related = [];
            foreach ($entry['vendors'] as $vendor) {
                foreach ($byVendor[$vendor['slug']] as $other) {
                    if ($other !== $key && !isset($related[$other]) && \count($related) < 8) {
                        $related[$other] = ['name' => $byName[$other]['name'], 'path' => $byName[$other]['path']];
                    }
                }
            }
            $entry['related'] = array_values($related);
        }
        unset($entry);

        return $byName;
    }

    /**
     * @param array<string, array<string, mixed>> $entries
     *
     * @return array<string, array<string, mixed>> Keyed by vendor slug, biggest first
     */
    public static function vendors(array $entries): array
    {
        $vendors = [];
        foreach ($entries as $entry) {
            foreach ($entry['vendors'] as $vendor) {
                $vendors[$vendor['slug']] ??= $vendor + ['path' => '/cookie/vendor/' . $vendor['slug'] . '/', 'cookies' => []];
                $vendors[$vendor['slug']]['cookies'][] = [
                    'name' => $entry['name'],
                    'path' => $entry['path'],
                    'category_label' => $entry['category_label'],
                    'description' => $entry['description'],
                ];
                if ($vendors[$vendor['slug']]['controller'] === '' && $vendor['controller'] !== '') {
                    $vendors[$vendor['slug']]['controller'] = $vendor['controller'];
                }
                if ($vendors[$vendor['slug']]['privacy_url'] === '' && $vendor['privacy_url'] !== '') {
                    $vendors[$vendor['slug']]['privacy_url'] = $vendor['privacy_url'];
                }
            }
        }
        uasort($vendors, static fn (array $a, array $b): int => \count($b['cookies']) <=> \count($a['cookies']) ?: strcasecmp($a['name'], $b['name']));

        return $vendors;
    }

    /**
     * The path segment for a cookie name: the name itself, URL-encoded, with
     * a pattern's trailing wildcard dropped (`AMP_*` lives at /cookie/AMP_/).
     * Names that cannot be a path are refused.
     */
    public static function urlSegment(string $name): ?string
    {
        $name = rtrim($name, '*');
        if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, '*') || preg_match('/[\x00-\x20\x7f]/', $name)) {
            return null;
        }

        return rawurlencode($name);
    }

    /** The directory name on disk, which nginx matches after decoding the URL. */
    public static function dirName(string $name): string
    {
        return rawurldecode((string) self::urlSegment($name));
    }

    public static function vendorSlug(string $platform): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $platform), '-'));

        return $slug === '' ? 'other' : $slug;
    }

    /**
     * @param array<string, array<string, mixed>> $entries
     *
     * @return array<string, list<array<string, mixed>>> Letter (or '#') => entries
     */
    public static function groupByLetter(array $entries): array
    {
        $groups = [];
        foreach ($entries as $entry) {
            $first = strtoupper(substr(ltrim($entry['name'], '_.-'), 0, 1));
            $letter = preg_match('/[A-Z]/', $first) ? $first : '#';
            $groups[$letter][] = $entry;
        }
        ksort($groups);

        return $groups;
    }

    /**
     * @param array<string, array<string, mixed>> $entries
     * @param array<string, array<string, mixed>> $vendors
     */
    public static function sitemap(array $entries, array $vendors, string $generated): string
    {
        $lines = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];
        $add = static function (string $url, string $priority) use (&$lines, $generated): void {
            $lines[] = '  <url><loc>' . htmlspecialchars($url, ENT_XML1) . '</loc><lastmod>' . $generated . '</lastmod><priority>' . $priority . '</priority></url>';
        };
        $add(self::BASE_URL . '/', '0.8');
        foreach ($vendors as $vendor) {
            $add(self::BASE_URL . '/vendor/' . $vendor['slug'] . '/', '0.6');
        }
        foreach ($entries as $entry) {
            $add($entry['url'], '0.5');
        }
        $lines[] = '</urlset>';

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array{prefix: string, suffix: string} $shell
     * @param array<string, mixed> $entry
     * @param array<string, array<string, mixed>> $entries
     */
    private function writePage(array $shell, array $entry, array $entries, string $generated): void
    {
        $vendorNames = array_map(static fn (array $v): string => $v['name'], $entry['vendors']);
        $who = $vendorNames === [] ? 'an unnamed service' : implode(' and ', \array_slice($vendorNames, 0, 2));
        $kind = strtolower($entry['category_label']);
        $article = preg_match('/^[aeiou]/', $kind) === 1 ? 'an' : 'a';

        $html = CookiePageShell::wrap($shell, $this->twig->render('public/cookie/page.html.twig', [
            'cookie' => $entry,
            'generated' => $generated,
        ]), [
            'title' => $entry['name'] . ' cookie: what it does, who sets it, consent category',
            'description' => mb_substr($entry['name'] . ' is ' . $article . ' ' . $kind . ' cookie set by ' . $who . '. ' . $entry['description'], 0, 155),
            'url' => $entry['url'],
        ]);

        $this->write('/' . self::dirName($entry['name']) . '/index.html', $html);
    }

    private function write(string $relativePath, string $content): void
    {
        $path = $this->basePath . self::OUTPUT_DIR . $relativePath;
        $dir = \dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            $this->logger->warning('Cookie pages: cannot create ' . $dir);

            return;
        }
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $content) === false || !@rename($tmp, $path)) {
            $this->logger->warning('Cookie pages: cannot write ' . $path);

            return;
        }

        // The nightly run is root's, the refresh after a classification is
        // the dashboard's. Pages written by root go to the dashboard's user,
        // or the dashboard could never rewrite them. public/ itself can be
        // root's (a volume mount point is), so the owner is read from the
        // folders the dashboard is known to write.
        $public = $this->basePath . '/public';
        $owner = OwnedFile::firstOwner([$public . '/cookie', $public . '/sites_data', $public . '/uploads', $public]);
        if ($owner !== null) {
            OwnedFile::adoptAs($path, $public, $owner['uid'], $owner['gid']);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT g.cookie_name, g.platform, g.description, g.expiry_duration, g.data_controller, g.privacy_url, g.wildcard_match,
                    c.slug AS category_slug
               FROM oci_cookies_global g
               LEFT JOIN oci_cookie_categories c ON c.id = g.category_id
              ORDER BY g.cookie_name, g.id',
        );
    }

    private static function safeUrl(string $url): string
    {
        $url = trim($url);

        return preg_match('#^https?://[^\s"\'<>]+$#i', $url) === 1 ? $url : '';
    }
}
