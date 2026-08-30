<?php

declare(strict_types=1);

namespace OCI\Compliance\Service;

/**
 * Minimal HTTP GET seam for the GVL updater.
 *
 * Exists so GvlUpdateService can be unit-tested with zero network access,
 * and so air-gapped installs can bind a no-op implementation.
 */
interface GvlHttpClientInterface
{
    /**
     * Fetch a URL and return the raw body, or null on any failure
     * (network error, non-2xx status, timeout).
     */
    public function get(string $url, int $timeoutSeconds = 20): ?string;
}
