<?php

declare(strict_types=1);

namespace OCI\Site\DTO;

/**
 * Validated input for creating a new site.
 */
final readonly class CreateSiteInput
{
    /**
     * @param list<int> $languageIds
     * @param list<string> $frameworkIds
     */
    public function __construct(
        public string $domain,
        public string $siteName,
        public string $privacyPolicyUrl = '',
        public string $bannerType = 'gdpr',
        public array $languageIds = [],
        public array $frameworkIds = [],
    ) {}
}
