<?php

declare(strict_types=1);

namespace OCI\Dashboard\DTO;

/**
 * All data needed to render the agency/reseller dashboard view.
 */
final readonly class AgencyDashboardData
{
    /**
     * @param array<string, array<string, mixed>> $commissionData
     * @param array<string, array<string, mixed>> $customerData
     */
    public function __construct(
        public int $userId,
        public string $priceModel,
        public string $payoutCurrency,
        public float $payoutAmount,
        public array $commissionData,
        public array $customerData,
    ) {}
}
