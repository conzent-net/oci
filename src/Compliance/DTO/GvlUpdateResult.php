<?php

declare(strict_types=1);

namespace OCI\Compliance\DTO;

/**
 * Outcome of a single Global Vendor List update run.
 *
 * A result is always returned for non-fatal outcomes (disabled, up-to-date,
 * dry run). Validation failures throw {@see \OCI\Compliance\Exception\GvlUpdateException}
 * instead, because those must surface a non-zero CLI exit code.
 */
final readonly class GvlUpdateResult
{
    /**
     * @param list<string> $translationsUpdated Language codes written this run
     * @param list<string> $translationsFailed  Language codes skipped (404 or invalid shape)
     * @param list<string> $warnings            Non-fatal issues worth surfacing
     */
    public function __construct(
        public bool $updated = false,
        public bool $skipped = false,
        public string $reason = '',
        public int $previousVersion = 0,
        public int $newVersion = 0,
        public string $lastUpdated = '',
        public int $vendorCount = 0,
        public int $googleVendorCount = 0,
        public bool $hasStandardTexts = false,
        public array $translationsUpdated = [],
        public array $translationsFailed = [],
        public array $warnings = [],
    ) {}

    public static function disabled(): self
    {
        return new self(
            skipped: true,
            reason: 'GVL_AUTO_UPDATE is false — the bundled vendor list is pinned.',
        );
    }

    /**
     * Nothing to fetch. Carries the *local* list's real figures so callers
     * report what is actually being served rather than zeros.
     */
    public static function upToDate(
        int $version,
        string $lastUpdated,
        int $vendorCount = 0,
        int $googleVendorCount = 0,
        bool $hasStandardTexts = false,
    ): self {
        return new self(
            skipped: true,
            reason: "Already at vendorListVersion {$version}.",
            previousVersion: $version,
            newVersion: $version,
            lastUpdated: $lastUpdated,
            vendorCount: $vendorCount,
            googleVendorCount: $googleVendorCount,
            hasStandardTexts: $hasStandardTexts,
        );
    }
}
