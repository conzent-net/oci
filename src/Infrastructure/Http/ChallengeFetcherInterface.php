<?php

declare(strict_types=1);

namespace OCI\Infrastructure\Http;

/**
 * One small GET against a URL the UrlGuard has already validated.
 *
 * No redirects are followed on purpose: the caller compares a token against
 * exactly the URL it was told to fetch, and a redirect is reported as the
 * status it is so the customer sees "your site answered 301", not a mismatch.
 */
interface ChallengeFetcherInterface
{
    /**
     * @param array{host: string, port: int, pin_ip: string} $pin From UrlGuard::check(): the
     *                                                             connection is pinned to pin_ip
     *                                                             when it is set.
     * @return array{ok: bool, error: string, status_code: int, headers: array<string, string>, body: string, truncated: bool}
     */
    public function fetch(string $url, array $pin): array;
}
