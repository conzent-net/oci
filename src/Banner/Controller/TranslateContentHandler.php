<?php

declare(strict_types=1);

namespace OCI\Banner\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /app/banners/translate — Translate banner content fields via OpenRouter.
 *
 * Accepts JSON: { target_language: string, fields: { fieldId: text, ... } }
 * Returns JSON: { success: true, data: { translations: { fieldId: text, ... } } }
 */
final class TranslateContentHandler implements RequestHandlerInterface
{
    private const API_URL = 'https://openrouter.ai/api/v1/chat/completions';
    private const HTTP_TIMEOUT = 30;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $apiKey = trim($_ENV['OPENROUTER_API_KEY'] ?? '');
        if ($apiKey === '') {
            return ApiResponse::error('Translation service not configured. Set OPENROUTER_API_KEY in .env', 503);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $targetLanguage = trim((string) ($body['target_language'] ?? ''));
        $fields = (array) ($body['fields'] ?? []);

        if ($targetLanguage === '') {
            return ApiResponse::error('Target language is required', 422);
        }

        if ($fields === []) {
            return ApiResponse::error('No fields to translate', 422);
        }

        // Build a single prompt with all fields for efficiency
        $fieldEntries = [];
        $fieldIds = [];
        foreach ($fields as $id => $text) {
            $text = trim((string) $text);
            if ($text !== '') {
                $fieldIds[] = (string) $id;
                $fieldEntries[] = (string) $id . ': ' . $text;
            }
        }

        if ($fieldEntries === []) {
            return ApiResponse::error('No non-empty fields to translate', 422);
        }

        $prompt = "Translate the following UI texts to {$targetLanguage}. These are cookie consent banner labels and messages for a website.\n\n"
            . "Return ONLY a JSON object mapping each field ID to its translation. Keep the same field IDs as keys. "
            . "Preserve any HTML tags. Do not add explanations.\n\n"
            . "Fields:\n" . implode("\n", $fieldEntries);

        $translations = $this->callOpenRouter($apiKey, $prompt, $fieldIds);

        if ($translations === null) {
            return ApiResponse::error('Translation failed. Please try again.', 502);
        }

        return ApiResponse::success(['translations' => $translations]);
    }

    /**
     * @param list<string> $expectedKeys
     * @return array<string, string>|null
     */
    private function callOpenRouter(string $apiKey, string $prompt, array $expectedKeys): ?array
    {
        $payload = json_encode([
            'model' => 'google/gemini-2.0-flash-001',
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.3,
            'response_format' => ['type' => 'json_object'],
        ], \JSON_THROW_ON_ERROR);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => self::HTTP_TIMEOUT,
                'ignore_errors' => true,
                'header' => "Content-Type: application/json\r\n"
                    . "Authorization: Bearer {$apiKey}\r\n"
                    . "HTTP-Referer: https://conzent.net\r\n"
                    . "X-Title: Conzent CMP\r\n",
                'content' => $payload,
            ],
        ]);

        $response = @file_get_contents(self::API_URL, false, $context);

        if ($response === false) {
            $this->logger->warning('OpenRouter API call failed: no response');
            return null;
        }

        $data = json_decode($response, true);
        if (!\is_array($data)) {
            $this->logger->warning('OpenRouter API returned invalid JSON');
            return null;
        }

        // Check for API errors
        if (isset($data['error'])) {
            $this->logger->warning('OpenRouter API error: ' . ($data['error']['message'] ?? 'unknown'));
            return null;
        }

        $content = (string) ($data['choices'][0]['message']['content'] ?? '');
        if ($content === '') {
            $this->logger->warning('OpenRouter API returned empty content');
            return null;
        }

        // Parse the JSON response
        $translations = json_decode($content, true);
        if (!\is_array($translations)) {
            $this->logger->warning('OpenRouter translation response is not valid JSON: ' . $content);
            return null;
        }

        // Ensure all expected keys are present, cast values to strings
        $result = [];
        foreach ($expectedKeys as $key) {
            $result[$key] = isset($translations[$key]) ? (string) $translations[$key] : '';
        }

        return $result;
    }
}
