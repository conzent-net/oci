<?php

declare(strict_types=1);

namespace OCI\Cookie\Service;

use Psr\Log\LoggerInterface;

/**
 * The marketing site's head, header and footer, borrowed for the public
 * cookie pages so they look like every other page on getconzent.com.
 *
 * The site is a static build we do not render, so the shell is read from a
 * live page: everything before `<main id="main">` and everything after
 * `</main>`. Page-specific head tags (title, description, canonical, social
 * tags, hreflang alternates, structured data) are rewritten per cookie page.
 * A copy is cached for a day so a generation run never depends on the site
 * answering, and a built-in minimal shell covers the very first run.
 */
final class CookiePageShell
{
    public const SOURCE_URL = 'https://getconzent.com/status/';

    private const CACHE_FILE = '/var/cache/cookie-shell.html';

    private const CACHE_SECONDS = 86400;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $basePath,
    ) {}

    /**
     * @return array{prefix: string, suffix: string}
     */
    public function load(): array
    {
        $cache = $this->basePath . self::CACHE_FILE;
        $fresh = is_file($cache) && (time() - (int) filemtime($cache)) < self::CACHE_SECONDS;

        if (!$fresh) {
            $html = $this->fetch();
            $split = $html !== null ? self::split($html) : null;
            if ($split !== null) {
                @mkdir(\dirname($cache), 0o775, true);
                @file_put_contents($cache, $html);

                return $split;
            }
        }

        if (is_file($cache)) {
            $split = self::split((string) file_get_contents($cache));
            if ($split !== null) {
                return $split;
            }
        }

        $this->logger->warning('Cookie pages: no site shell available, using the built-in one');

        return self::builtIn();
    }

    /**
     * @return array{prefix: string, suffix: string}|null
     */
    public static function split(string $html): ?array
    {
        $open = strpos($html, '<main id="main">');
        $close = strrpos($html, '</main>');
        if ($open === false || $close === false || $close <= $open) {
            return null;
        }
        $open += \strlen('<main id="main">');

        return [
            'prefix' => substr($html, 0, $open),
            'suffix' => substr($html, $close),
        ];
    }

    /**
     * @param array{prefix: string, suffix: string} $shell
     * @param array{title: string, description: string, url: string, robots?: string} $meta
     */
    public static function wrap(array $shell, string $body, array $meta): string
    {
        $prefix = self::rewriteHead($shell['prefix'], $meta);

        return $prefix . "\n" . $body . "\n" . $shell['suffix'];
    }

    /**
     * @param array{title: string, description: string, url: string, robots?: string} $meta
     */
    public static function rewriteHead(string $prefix, array $meta): string
    {
        $title = htmlspecialchars($meta['title'], ENT_QUOTES, 'UTF-8');
        $description = htmlspecialchars($meta['description'], ENT_QUOTES, 'UTF-8');
        $url = htmlspecialchars($meta['url'], ENT_QUOTES, 'UTF-8');
        $robots = htmlspecialchars($meta['robots'] ?? 'index,follow', ENT_QUOTES, 'UTF-8');

        $head = $prefix;
        $head = (string) preg_replace('#<title>.*?</title>#s', "<title>{$title}</title>", $head, 1);
        $head = (string) preg_replace('#<meta name="description"[^>]*>#', "<meta name=\"description\" content=\"{$description}\">", $head, 1);
        $head = (string) preg_replace('#<link rel="canonical"[^>]*>#', "<link rel=\"canonical\" href=\"{$url}\">", $head, 1);
        $head = (string) preg_replace('#<meta property="og:title"[^>]*>#', "<meta property=\"og:title\" content=\"{$title}\">", $head, 1);
        $head = (string) preg_replace('#<meta property="og:description"[^>]*>#', "<meta property=\"og:description\" content=\"{$description}\">", $head, 1);
        $head = (string) preg_replace('#<meta property="og:url"[^>]*>#', "<meta property=\"og:url\" content=\"{$url}\">", $head, 1);
        $head = (string) preg_replace('#<meta property="og:type"[^>]*>#', '<meta property="og:type" content="article">', $head, 1);
        $head = (string) preg_replace('#<meta name="twitter:title"[^>]*>#', "<meta name=\"twitter:title\" content=\"{$title}\">", $head, 1);
        $head = (string) preg_replace('#<meta name="twitter:description"[^>]*>#', "<meta name=\"twitter:description\" content=\"{$description}\">", $head, 1);
        // The source page's translations do not exist for cookie pages.
        $head = (string) preg_replace('#\s*<link rel="alternate" hreflang="[^"]*"[^>]*>#', '', $head);
        // The source page's structured data describes the source page.
        $head = (string) preg_replace('#<script type="application/ld\+json">.*?</script>#s', '', $head);
        $head = (string) preg_replace('#<meta name="robots"[^>]*>#', '', $head);
        $head = (string) preg_replace('#</head>#', "<meta name=\"robots\" content=\"{$robots}\">\n</head>", $head, 1);

        return $head;
    }

    /**
     * @return array{prefix: string, suffix: string}
     */
    public static function builtIn(): array
    {
        return [
            'prefix' => '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
                . '<title></title><meta name="description" content=""><link rel="canonical" href="">'
                . '<style>body{font-family:system-ui,sans-serif;margin:0;color:#0f172a}.container{max-width:960px;margin:0 auto;padding:0 1rem}.hero{padding:3rem 0 1rem}.section{padding:2rem 0}</style>'
                . '</head><body><main id="main">',
            'suffix' => '</main></body></html>',
        ];
    }

    private function fetch(): ?string
    {
        $ch = curl_init(self::SOURCE_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => 'Conzent cookie pages',
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (!\is_string($body) || $status !== 200 || $body === '') {
            $this->logger->warning('Cookie pages: site shell fetch failed', ['status' => $status]);

            return null;
        }

        return $body;
    }
}
