<?php

declare(strict_types=1);

namespace OCI\Monetization\Service;

use OCI\Shared\Service\EditionService;

/**
 * Loads and caches config/pricing.json — the single source of truth for plans,
 * prices, tiers, features, and limits.
 *
 * For self-hosted (non-Cloud) editions, all features are enabled and all limits
 * are unlimited (0).
 */
final class PricingService
{
    private ?array $config = null;

    public function __construct(
        private readonly string $pricingJsonPath,
        private readonly string $stripeMode,
        private readonly EditionService $edition,
    ) {}

    /**
     * Full pricing config (cached per request).
     */
    public function getConfig(): array
    {
        if ($this->config === null) {
            $json = file_get_contents($this->pricingJsonPath);
            if ($json === false) {
                throw new \RuntimeException("Cannot read pricing config: {$this->pricingJsonPath}");
            }
            $this->config = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        }

        return $this->config;
    }

    /**
     * Get the full config safe for frontend (strips stripe_price_ids).
     */
    public function getPublicConfig(): array
    {
        $config = $this->getConfig();

        foreach ($config['plans'] ?? [] as $key => &$plan) {
            unset($plan['stripe_price_ids']);
        }

        return $config;
    }

    /**
     * Get a single plan definition by key (e.g. 'personal', 'business').
     */
    public function getPlan(string $planKey): ?array
    {
        return $this->getConfig()['plans'][$planKey] ?? null;
    }

    /**
     * Get all plans (keyed by plan key).
     */
    public function getPlans(): array
    {
        return $this->getConfig()['plans'] ?? [];
    }

    /**
     * Machine-readable feature keys for a plan.
     */
    public function getFeatureKeys(string $planKey): array
    {
        $plan = $this->getPlan($planKey);
        return $plan['feature_keys'] ?? [];
    }

    /**
     * Check if a plan includes a feature.
     *
     * Self-hosted edition: always returns true (all features unlocked).
     */
    public function hasFeature(string $planKey, string $featureKey): bool
    {
        if (!$this->edition->isCloud()) {
            return true;
        }

        return \in_array($featureKey, $this->getFeatureKeys($planKey), true);
    }

    /**
     * Get a numeric limit for a plan (e.g. pages_per_scan, max_languages).
     *
     * Returns 0 for unlimited. Self-hosted edition: always 0 (unlimited).
     */
    public function getLimit(string $planKey, string $limitKey): int
    {
        if (!$this->edition->isCloud()) {
            return 0;
        }

        $plan = $this->getPlan($planKey);
        return (int) ($plan['limits'][$limitKey] ?? 0);
    }

    /**
     * Calculate total price for a plan + cycle + quantity using tiered pricing.
     *
     * Each unit is priced at the tier rate for its position in the quantity.
     * Example: 8 sites on personal monthly = (5 * €9) + (3 * €7.50) = €67.50
     */
    public function calculatePrice(string $planKey, string $cycle, int $quantity): float
    {
        $plan = $this->getPlan($planKey);
        if ($plan === null) {
            return 0.0;
        }

        $tiers = $plan['tiers'] ?? [];
        $total = 0.0;
        $remaining = $quantity;

        foreach ($tiers as $tier) {
            if ($remaining <= 0) {
                break;
            }

            $tierSize = $tier['to'] - $tier['from'] + 1;
            $unitsInTier = min($remaining, $tierSize);
            $unitPrice = (float) ($tier[$cycle] ?? $tier['monthly'] ?? 0);
            $total += $unitsInTier * $unitPrice;
            $remaining -= $unitsInTier;
        }

        return round($total, 2);
    }

    /**
     * Get the unit price for a given quantity (the tier rate that applies to that unit).
     */
    public function getUnitPrice(string $planKey, string $cycle, int $quantity): float
    {
        $plan = $this->getPlan($planKey);
        if ($plan === null) {
            return 0.0;
        }

        foreach ($plan['tiers'] ?? [] as $tier) {
            if ($quantity >= $tier['from'] && $quantity <= $tier['to']) {
                return (float) ($tier[$cycle] ?? $tier['monthly'] ?? 0);
            }
        }

        return 0.0;
    }

    /**
     * Get the Stripe price ID for a plan + cycle, based on current STRIPE_MODE.
     */
    public function getStripePriceId(string $planKey, string $cycle): ?string
    {
        $plan = $this->getPlan($planKey);
        if ($plan === null) {
            return null;
        }

        $priceId = $plan['stripe_price_ids'][$this->stripeMode][$cycle] ?? null;

        return ($priceId !== null && $priceId !== '') ? $priceId : null;
    }

    /**
     * Reverse lookup: find which plan + cycle a Stripe price ID belongs to.
     *
     * Returns ['plan_key' => string, 'cycle' => string] or null.
     */
    public function findPlanByStripePriceId(string $priceId): ?array
    {
        foreach ($this->getConfig()['plans'] ?? [] as $planKey => $plan) {
            foreach (['test', 'live'] as $mode) {
                foreach (['monthly', 'yearly'] as $cycle) {
                    $id = $plan['stripe_price_ids'][$mode][$cycle] ?? '';
                    if ($id !== '' && $id === $priceId) {
                        return ['plan_key' => $planKey, 'cycle' => $cycle];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Equivalent months for lifetime savings calculation (e.g. 36 = 3 years).
     */
    public function getLifetimeMonths(): int
    {
        return (int) ($this->getConfig()['lifetime_months'] ?? 36);
    }

    /**
     * Current Stripe mode ('test' or 'live').
     */
    public function getStripeMode(): string
    {
        return $this->stripeMode;
    }

    /**
     * Trial period in days from config.
     */
    public function getTrialPeriodDays(): int
    {
        return (int) ($this->getConfig()['trial_period_days'] ?? 14);
    }

    /**
     * Currency code (e.g. 'EUR').
     */
    public function getCurrency(): string
    {
        return $this->getConfig()['currency'] ?? 'EUR';
    }

    /**
     * Currency symbol (e.g. '€').
     */
    public function getCurrencySymbol(): string
    {
        return $this->getConfig()['currency_symbol'] ?? '€';
    }
}
