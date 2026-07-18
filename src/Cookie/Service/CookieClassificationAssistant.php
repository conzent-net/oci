<?php

declare(strict_types=1);

namespace OCI\Cookie\Service;

use OCI\Infrastructure\Ai\AiClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Uses a web-enabled LLM to suggest how an unknown cookie should be classified.
 *
 * Given a cookie name (and optional domain), it researches the cookie online and
 * returns one of the standard consent categories plus the vendor/platform and a
 * short description — to help staff classify the unclassified backlog.
 */
final class CookieClassificationAssistant
{
    /** OpenRouter ":online" suffix enables web search for the model. */
    private const MODEL = 'google/gemini-2.5-flash:online';

    /** The categories the assistant is allowed to return. */
    private const ALLOWED = ['necessary', 'functional', 'analytics', 'marketing', 'unclassified'];

    public function __construct(
        private readonly AiClientInterface $ai,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Research a cookie and suggest a classification.
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
    public function suggest(string $cookieName, ?string $domain): array
    {
        $cookieName = trim($cookieName);
        if ($cookieName === '') {
            return ['success' => false, 'error' => 'Cookie name is required'];
        }

        $domainLine = ($domain !== null && trim($domain) !== '')
            ? "It has been observed on the domain: {$domain}."
            : 'The domain it is set on is unknown.';

        $prompt = <<<PROMPT
You are a GDPR cookie-classification expert. Research the browser cookie named "{$cookieName}" using the web and decide which consent category it belongs to. {$domainLine}

Categories (pick exactly one):
- "necessary": strictly required for the site to function (session, security, load balancing, consent storage). Cannot be switched off.
- "functional": remembers user preferences and choices (language, region, UI settings) — enhances functionality but not essential.
- "analytics": measures traffic and usage, aggregate statistics (e.g. Google Analytics, Matomo, Hotjar).
- "marketing": advertising, retargeting, cross-site tracking, social pixels (e.g. Facebook Pixel, DoubleClick).
- "unclassified": you genuinely cannot determine the purpose from available information.

Return ONLY a JSON object, no prose, no markdown fences, with this exact shape:
{
  "category": "necessary|functional|analytics|marketing|unclassified",
  "platform": "the vendor/service that sets it, or null if unknown",
  "description": "one concise sentence (max 30 words) describing its purpose",
  "confidence": 0-100,
  "reasoning": "one short sentence (max 25 words) — do NOT include any URLs, links, or citations"
}

Keep every string field short so the JSON is complete. Only choose "unclassified" when the evidence is truly insufficient — prefer a concrete category when the cookie is a known one.
PROMPT;

        $response = $this->ai->chatCompletion(
            model: self::MODEL,
            messages: [['role' => 'user', 'content' => $prompt]],
            temperature: 0.2,
            jsonMode: true,
            maxTokens: 2048,
        );

        if ($response === null || trim($response) === '') {
            $this->logger->warning('Cookie classification AI returned no response', ['cookie' => $cookieName]);
            return ['success' => false, 'error' => 'The AI lookup is unavailable right now. Check that OPENROUTER_API_KEY is configured.'];
        }

        $data = $this->decodeJsonObject($response);
        if ($data === null) {
            $this->logger->warning('Cookie classification AI returned invalid JSON', ['cookie' => $cookieName, 'response' => $response]);
            return ['success' => false, 'error' => 'The AI returned an unexpected response. Try again.'];
        }

        $category = strtolower(trim((string) ($data['category'] ?? '')));
        if (!\in_array($category, self::ALLOWED, true)) {
            $category = 'unclassified';
        }

        $confidence = (int) ($data['confidence'] ?? 0);
        $confidence = max(0, min(100, $confidence));

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
     * Decode the model's reply into an object, tolerating markdown fences and
     * surrounding prose (some grounded models wrap or annotate the JSON).
     *
     * @return array<string, mixed>|null
     */
    private function decodeJsonObject(string $raw): ?array
    {
        $raw = trim($raw);

        // Strip ```json ... ``` (or plain ```) code fences.
        if (str_starts_with($raw, '```')) {
            $raw = (string) preg_replace('/^```(?:json)?\s*/i', '', $raw);
            $raw = (string) preg_replace('/\s*```$/', '', $raw);
            $raw = trim($raw);
        }

        $data = json_decode($raw, true);
        if (\is_array($data)) {
            return $data;
        }

        // Fall back to the outermost {...} span.
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
