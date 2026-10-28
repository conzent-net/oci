<?php

declare(strict_types=1);

namespace OCI\Cookie\Service;

/**
 * Finds what the web says about a cookie, so the classifier can read it
 * instead of guessing.
 */
interface CookieEvidenceProviderInterface
{
    public function isConfigured(): bool;

    /**
     * @return array{snippets: list<string>, sources: list<string>}
     *         Short passages that mention the cookie, and the domains they came from.
     */
    public function gather(string $cookieName, ?string $domain): array;
}
