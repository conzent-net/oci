<?php

declare(strict_types=1);

namespace OCI\Infrastructure\Ai;

/**
 * A minimal text/vision/image AI client, defined in core so that core-domain
 * services (Banner/Cookie/Scanning translation & classification helpers) do not
 * depend on any cloud module.
 *
 * The Cloud Edition binds this to the OpenRouter-backed implementation; the
 * open-source core binds it to {@see NullAiClient}, so AI-assisted features are
 * inert (returning null) unless a real implementation is provided.
 */
interface AiClientInterface
{
    /**
     * Send a chat completion request and return the text content.
     *
     * @param array<array{role: string, content: string|array<mixed>}> $messages
     * @return string|null The assistant response text, or null on failure.
     */
    public function chatCompletion(
        string $model,
        array $messages,
        float $temperature = 0.7,
        bool $jsonMode = false,
        int $maxTokens = 4096,
    ): ?string;

    /**
     * Analyse an image with a vision model.
     *
     * @return string|null The model's description of the image, or null on failure.
     */
    public function analyseImage(string $model, string $imageUrl, string $prompt): ?string;

    /**
     * Generate an image via a chat-completion model that supports image output.
     *
     * @return string|null Raw image binary data, or null on failure.
     */
    public function chatImageGeneration(string $model, string $prompt): ?string;
}
