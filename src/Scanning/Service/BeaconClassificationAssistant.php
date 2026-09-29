<?php

declare(strict_types=1);

namespace OCI\Scanning\Service;

use OCI\Infrastructure\Ai\AiClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Uses a web-enabled LLM to suggest how an unknown third-party beacon/script
 * domain should be classified — to help staff work through the backlog.
 */
final class BeaconClassificationAssistant
{
    /** OpenRouter ":online" suffix enables web search for the model. */
    private const MODEL = 'google/gemini-2.5-flash:online';

    private const ALLOWED = ['necessary', 'functional', 'analytics', 'marketing', 'unclassified'];

    public function __construct(
        private readonly AiClientInterface $ai,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Research a beacon/script domain and suggest a consent category.
     *
     * @return array{
     *     success: bool,
     *     category_slug?: string,
     *     platform?: ?string,
     *     description?: ?string,
     *     confidence?: int,
     *     reasoning?: ?string,
     *     error?: string
     * }
     */
    public function suggest(string $domain): array
    {
        $domain = trim($domain);
        if ($domain === '') {
            return ['success' => false, 'error' => 'Domain is required'];
        }

        $prompt = <<<PROMPT
You are a GDPR consent-classification expert. A website loads a third-party script/beacon from the domain "{$domain}". Research this domain and the service it belongs to using the web, and decide which consent category the tracker belongs to.

Categories (pick exactly one):
- "necessary": strictly required for the site to function (CDNs delivering essential assets, security/anti-bot, load balancing, consent management). Cannot be switched off.
- "functional": enables non-essential features and preferences (embedded maps, chat widgets, video players, fonts).
- "analytics": measures traffic and usage / aggregate statistics (e.g. Google Analytics, Matomo, Hotjar, Segment).
- "marketing": advertising, retargeting, cross-site tracking, social/ad pixels (e.g. Google Ads/DoubleClick, Facebook, TikTok).
- "unclassified": you genuinely cannot determine the purpose.

Return ONLY a JSON object, no prose, no markdown fences, with this exact shape:
{
  "category": "necessary|functional|analytics|marketing|unclassified",
  "platform": "the vendor/service behind the domain, or null if unknown",
  "description": "one concise sentence (max 30 words) describing what it does",
  "confidence": 0-100,
  "reasoning": "one short sentence (max 25 words) — do NOT include any URLs, links, or citations"
}

Keep every string field short so the JSON is complete. Only choose "unclassified" when the evidence is truly insufficient — prefer a concrete category when the service is known.
PROMPT;

        $response = $this->ai->chatCompletion(
            model: self::MODEL,
            messages: [['role' => 'user', 'content' => $prompt]],
            temperature: 0.2,
            jsonMode: true,
            maxTokens: 2048,
        );

        if ($response === null || trim($response) === '') {
            $this->logger->warning('Beacon classification AI returned no response', ['domain' => $domain]);
            return ['success' => false, 'error' => 'The AI lookup is unavailable right now. Check that OPENROUTER_API_KEY is configured.'];
        }

        $data = $this->decodeJsonObject($response);
        if ($data === null) {
            $this->logger->warning('Beacon classification AI returned invalid JSON', ['domain' => $domain, 'response' => $response]);
            return ['success' => false, 'error' => 'The AI returned an unexpected response. Try again.'];
        }

        $category = strtolower(trim((string) ($data['category'] ?? '')));
        if (!\in_array($category, self::ALLOWED, true)) {
            $category = 'unclassified';
        }

        $confidence = max(0, min(100, (int) ($data['confidence'] ?? 0)));

        return [
            'success' => true,
            'category_slug' => $category,
            'platform' => $this->cleanString($data['platform'] ?? null),
            'description' => $this->cleanString($data['description'] ?? null),
            'confidence' => $confidence,
            'reasoning' => $this->cleanString($data['reasoning'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonObject(string $raw): ?array
    {
        $raw = trim($raw);

        if (str_starts_with($raw, '```')) {
            $raw = (string) preg_replace('/^```(?:json)?\s*/i', '', $raw);
            $raw = (string) preg_replace('/\s*```$/', '', $raw);
            $raw = trim($raw);
        }

        $data = json_decode($raw, true);
        if (\is_array($data)) {
            return $data;
        }

        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $data = json_decode(substr($raw, $start, $end - $start + 1), true);
            if (\is_array($data)) {
                return $data;
            }
        }

        return null;
    }

    private function cleanString(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || strtolower($value) === 'null') {
            return null;
        }

        return $value;
    }
}
