<?php

declare(strict_types=1);

namespace OCI\Dashboard\DTO;

/**
 * Consent report data returned from the report API endpoint.
 */
final readonly class ConsentReportData
{
    public function __construct(
        public int $accepted,
        public int $rejected,
        public int $partiallyAccepted,
    ) {}

    /**
     * @return array{accepted: int, rejected: int, partially_accepted: int}
     */
    public function toArray(): array
    {
        return [
            'accepted' => $this->accepted,
            'rejected' => $this->rejected,
            'partially_accepted' => $this->partiallyAccepted,
        ];
    }
}
