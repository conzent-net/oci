<?php

declare(strict_types=1);

namespace OCI\Shared\Service;

/**
 * Determines which edition features are available based on CMP_ID configuration.
 *
 * Cloud Edition: CMP_ID is set and valid → billing, plan limits, agency features enabled.
 * Community/Self-hosted: CMP_ID is empty or invalid → unlimited domains, no billing, no agency.
 */
final class EditionService
{
    private ?bool $isCloud = null;

    /**
     * Is this a Cloud Edition instance?
     *
     * Cloud = CMP_ID environment variable is set and non-empty.
     * The actual IAB validation is done by CmpValidationService;
     * this service only checks if CMP_ID is configured at all.
     */
    public function isCloud(): bool
    {
        if ($this->isCloud === null) {
            $cmpId = trim($_ENV['CMP_ID'] ?? '');
            $this->isCloud = $cmpId !== '' && $cmpId !== '0';
        }

        return $this->isCloud;
    }

    /**
     * Is billing enabled?
     * Billing only works on Cloud Edition with a valid CMP_ID.
     */
    public function isBillingEnabled(): bool
    {
        return $this->isCloud();
    }

    /**
     * Are plan-based domain limits enforced?
     * Self-hosted = unlimited. Cloud = limited by plan.
     */
    public function arePlanLimitsEnforced(): bool
    {
        return $this->isCloud();
    }

    /**
     * Is the agency user type available?
     * Agency management is Cloud-only.
     */
    public function isAgencyEnabled(): bool
    {
        return $this->isCloud();
    }

    /**
     * Get the maximum domains allowed for a user.
     * Returns 0 for unlimited (self-hosted).
     */
    public function getMaxDomains(int $planMaxDomains): int
    {
        if (!$this->isCloud()) {
            return 0; // 0 = unlimited
        }

        return $planMaxDomains;
    }
}
