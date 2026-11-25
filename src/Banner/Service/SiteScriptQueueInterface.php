<?php

declare(strict_types=1);

namespace OCI\Banner\Service;

/**
 * Queue a site's consent bundle for regeneration.
 *
 * This exists so a service can ask for a rebuild without depending on the
 * generator itself. `ScriptGenerationService` needs the subscription state to
 * decide what a site is entitled to, so anything in the billing path that
 * reached for the generator directly would close a dependency cycle; billing
 * resolves this interface from the container when it needs it instead.
 */
interface SiteScriptQueueInterface
{
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_NORMAL = 'normal';

    /**
     * @param array<int, int> $siteIds
     *
     * @return int jobs queued, after dedupe
     */
    public function enqueue(array $siteIds, string $priority = self::PRIORITY_NORMAL, string $reason = ''): int;
}
