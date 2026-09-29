<?php

declare(strict_types=1);

namespace OCI\Cookie\Service;

use Psr\Log\LoggerInterface;

/**
 * Google results for "<cookie> cookie", through DataForSEO.
 *
 * The web-enabled model alone gave up on ordinary cookies that a plain Google
 * search explains in its first result: the search "shop_per_row cookie"
 * carries an AI overview naming the vendor and purpose, plus four cookie
 * policy pages quoting the same sentence, while the model reported "no web
 * search results mention" it. This reads that page of results and hands the
 * useful passages to the classifier.
 *
 * Only passages that actually mention the cookie name are kept: a search for
 * a cookie called "shop_per_row" also returns bakery videos, and those must not
 * reach the model.
 */
final class SerpCookieEvidence implements CookieEvidenceProviderInterface
{
    /**
     * "regular" answers in about seven seconds with titles and snippets, which
     * is all a cookie policy page needs to say. "advanced" adds Google's AI
     * overview but takes about eighteen seconds and fails with a server error
     * half the time, so it is only the fallback.
     */
    private const ENDPOINT_REGULAR = 'https://api.dataforseo.com/v3/serp/google/organic/live/regular';

    private const ENDPOINT_ADVANCED = 'https://api.dataforseo.com/v3/serp/google/organic/live/advanced';

    /** United States, English: the largest index of cookie policy pages. */
    private const LOCATION_CODE = 2840;

    private const LANGUAGE_CODE = 'en';

    private const MAX_SNIPPETS = 8;

    private const TIMEOUT_SECONDS = 25;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function isConfigured(): bool
    {
        return trim((string) ($_ENV['DATAFORSEO_LOGIN'] ?? '')) !== ''
            && trim((string) ($_ENV['DATAFORSEO_PASSWORD'] ?? '')) !== '';
    }

    public function gather(string $cookieName, ?string $domain): array
    {
        $empty = ['snippets' => [], 'sources' => []];
        if (!$this->isConfigured()) {
            return $empty;
        }

        $needle = self::needle($cookieName);
        if ($needle === '') {
            return $empty;
        }

        $evidence = self::fromSerp($this->search($needle . ' cookie'), $needle);

        // Nothing under the bare name: try it together with the site it was
        // seen on, which finds vendor documentation for site-specific names.
        if ($evidence['snippets'] === [] && $domain !== null && trim($domain) !== '') {
            $evidence = self::fromSerp($this->search($needle . ' cookie ' . trim($domain)), $needle);
        }

        return $evidence;
    }

    /**
     * The part of the name worth searching for: a pattern's wildcard and the
     * separator before it go, so `AMP_*` searches for `AMP`.
     */
    public static function needle(string $cookieName): string
    {
        // Only the tail is trimmed: a leading underscore is part of the name
        // (Hotjar's cookies all start with `_hj`).
        return trim(rtrim(str_replace('*', '', $cookieName), "_-."));
    }

    /**
     * Pull the passages that mention the cookie out of one page of results.
     *
     * @param list<array<string, mixed>> $items The `items` of a DataForSEO advanced SERP result
     *
     * @return array{snippets: list<string>, sources: list<string>}
     */
    public static function fromSerp(array $items, string $needle): array
    {
        $snippets = [];
        $sources = [];
        $mentions = static fn (string $text): bool => $needle !== '' && stripos($text, $needle) !== false;

        foreach ($items as $item) {
            $type = (string) ($item['type'] ?? '');

            // Google's own summary, when it wrote one, is the best single passage.
            if ($type === 'ai_overview') {
                foreach ((array) ($item['items'] ?? []) as $element) {
                    $text = trim((string) ($element['text'] ?? ''));
                    if ($text !== '' && $mentions($text)) {
                        $snippets[] = self::clip($text);
                    }
                }
                foreach ((array) ($item['references'] ?? []) as $reference) {
                    $text = trim((string) ($reference['text'] ?? ''));
                    if ($text !== '' && $mentions($text)) {
                        $snippets[] = self::clip($text);
                    }
                    $sources[] = (string) ($reference['domain'] ?? '');
                }
                continue;
            }

            if ($type === 'organic' || $type === 'featured_snippet') {
                $text = trim(((string) ($item['title'] ?? '')) . '. ' . ((string) ($item['description'] ?? '')));
                if ($mentions($text)) {
                    $snippets[] = self::clip($text);
                    $sources[] = (string) ($item['domain'] ?? '');
                }
                continue;
            }

            if ($type === 'people_also_ask') {
                foreach ((array) ($item['items'] ?? []) as $question) {
                    $expanded = (array) ($question['expanded_element'] ?? []);
                    foreach ($expanded as $answer) {
                        $text = trim(((string) ($question['title'] ?? '')) . ' ' . ((string) ($answer['description'] ?? '')));
                        if ($mentions($text)) {
                            $snippets[] = self::clip($text);
                            $sources[] = (string) ($answer['domain'] ?? '');
                        }
                    }
                }
            }
        }

        $snippets = array_values(array_unique(array_filter($snippets)));
        $sources = array_values(array_unique(array_filter($sources)));

        return [
            'snippets' => array_slice($snippets, 0, self::MAX_SNIPPETS),
            'sources' => array_slice($sources, 0, self::MAX_SNIPPETS),
        ];
    }

    private static function clip(string $text): string
    {
        $text = (string) preg_replace('/\s+/', ' ', $text);

        return mb_strlen($text) > 320 ? mb_substr($text, 0, 317) . '...' : $text;
    }

    /**
     * One live Google search. Returns the result items, or nothing on any failure.
     *
     * @return list<array<string, mixed>>
     */
    private function search(string $keyword): array
    {
        // Fast and usually enough. When it fails (task 40101 "Internal SE
        // Server Error" comes and goes) or names nothing useful, the slower
        // search with Google's AI overview gets one try.
        $items = $this->searchOnce($keyword, self::ENDPOINT_REGULAR, false);
        if ($items === null || $items === []) {
            $items = $this->searchOnce($keyword, self::ENDPOINT_ADVANCED, true);
        }

        return $items ?? [];
    }

    /**
     * @return list<array<string, mixed>>|null Items, or null when the search itself failed.
     */
    private function searchOnce(string $keyword, string $endpoint, bool $withAiOverview): ?array
    {
        $request = [
            'keyword' => $keyword,
            'location_code' => self::LOCATION_CODE,
            'language_code' => self::LANGUAGE_CODE,
            'device' => 'desktop',
            'depth' => 10,
        ];
        if ($withAiOverview) {
            $request['load_async_ai_overview'] = true;
        }
        $payload = json_encode([$request], JSON_THROW_ON_ERROR);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_USERPWD => trim((string) $_ENV['DATAFORSEO_LOGIN']) . ':' . trim((string) $_ENV['DATAFORSEO_PASSWORD']),
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!\is_string($body) || $status !== 200) {
            $this->logger->warning('Cookie evidence search failed', ['keyword' => $keyword, 'status' => $status, 'error' => $error]);

            return null;
        }

        $decoded = json_decode($body, true);
        $task = \is_array($decoded) ? ($decoded['tasks'][0] ?? null) : null;
        if (!\is_array($task) || (int) ($task['status_code'] ?? 0) !== 20000) {
            $this->logger->warning('Cookie evidence search rejected', ['keyword' => $keyword, 'message' => $task['status_message'] ?? 'no task']);

            return null;
        }

        $items = $task['result'][0]['items'] ?? [];

        return \is_array($items) ? array_values($items) : [];
    }
}
