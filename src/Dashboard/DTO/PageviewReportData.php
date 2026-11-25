<?php

declare(strict_types=1);

namespace OCI\Dashboard\DTO;

/**
 * Pageview report data returned from the report API endpoint.
 *
 * @param array<string, array{date: string, views: int}> $dataPoints
 */
final readonly class PageviewReportData
{
    /**
     * @param array<string, array{date: string, views: int}> $dataPoints
     */
    public function __construct(
        public array $dataPoints,
    ) {}
}
