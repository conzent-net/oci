<?php

declare(strict_types=1);

namespace OCI\Site\DTO;

/**
 * Outcome of a plugin claim: either the issued site data, or a stable error
 * code with the HTTP status the handler should answer with.
 */
final readonly class ClaimResult
{
    /**
     * @param array<string, mixed>  $data   the issued site fields on success
     * @param array<string, string> $errors field errors for invalid_request
     * @param array<string, mixed>  $extra  extra keys merged into the error body (reason, retry_after, ...)
     */
    public function __construct(
        public bool $ok,
        public string $code,
        public int $status,
        public string $message,
        public array $data = [],
        public array $errors = [],
        public array $extra = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function issued(array $data): self
    {
        return new self(true, 'issued', 200, 'Website key issued.', $data);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed>  $extra
     */
    public static function failed(string $code, int $status, string $message, array $errors = [], array $extra = []): self
    {
        return new self(false, $code, $status, $message, [], $errors, $extra);
    }
}
