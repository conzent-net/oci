<?php

declare(strict_types=1);

namespace OCI\Compliance\Repository;

interface PrivacyFrameworkRepositoryInterface
{
    /**
     * Get all enabled framework IDs for a site.
     *
     * @return list<string> e.g. ['gdpr', 'eprivacy_directive', 'ccpa_cpra']
     */
    public function getFrameworksForSite(int $siteId): array;

    /**
     * Replace all frameworks for a site (delete + insert).
     *
     * @param list<string> $frameworkIds
     */
    public function setFrameworksForSite(int $siteId, array $frameworkIds): void;

    /**
     * Count the number of enabled frameworks for a site.
     */
    public function countFrameworks(int $siteId): int;
}
