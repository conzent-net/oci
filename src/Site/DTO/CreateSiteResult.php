<?php

declare(strict_types=1);

namespace OCI\Site\DTO;

/**
 * Result data after successfully creating a site.
 */
final readonly class CreateSiteResult
{
    /**
     * @param array<string, mixed> $site Full site row from database
     */
    public function __construct(
        public int $siteId,
        public string $domain,
        public string $websiteKey,
        public array $site,
    ) {}
}
