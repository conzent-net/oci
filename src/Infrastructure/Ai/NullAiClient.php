<?php

declare(strict_types=1);

namespace OCI\Infrastructure\Ai;

/**
 * No-op {@see AiClientInterface} used by the open-source core, where no AI
 * provider ships. Every call returns null, so AI-assisted features degrade
 * gracefully (exactly as the real client behaves when no API key is set).
 *
 * The Cloud Edition overrides this binding with the OpenRouter-backed client.
 */
final class NullAiClient implements AiClientInterface
{
    public function chatCompletion(
        string $model,
        array $messages,
        float $temperature = 0.7,
        bool $jsonMode = false,
        int $maxTokens = 4096,
    ): ?string {
        return null;
    }

    public function analyseImage(string $model, string $imageUrl, string $prompt): ?string
    {
        return null;
    }

    public function chatImageGeneration(string $model, string $prompt): ?string
    {
        return null;
    }
}
