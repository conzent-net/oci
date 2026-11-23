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

        // By key, not by reference: `foreach ($config['plans'] ?? [] as &$plan)`
        // iterates a copy, so nothing was ever removed and the price ids
        // went out to the browser.
        foreach (array_keys($config['plans'] ?? []) as $key) {
            unset($config['plans'][$key]['stripe_price_ids']);
            if (isset($config['plans'][$key]['pageview_tiers'])) {
                $config['plans'][$key]['pageview_tiers'] = array_map(
                    static function (array $tier): array {
                        unset($tier['stripe_price_ids']);

                        return $tier;
                    },
                    self::buyableTiers($config['plans'][$key]['pageview_tiers'], $this->stripeMode),
                );
            }
        }

        return $config;
    }

    /**
     * The pageview allowances a site on this plan can choose between.
     *
     * Each entry: pageviews, addon_monthly, addon_yearly (the extra per site
     * on top of the plan price) and, for paid tiers, stripe_price_ids.
     * Empty for plans without tiers (unlimited plans, self-hosted).
     *
     * @return list<array<string, mixed>>
     */
    public function getPageviewTiers(string $planKey): array
    {
        if (!$this->edition->isCloud()) {
            return [];
        }

        return self::buyableTiers($this->getPlan($planKey)['pageview_tiers'] ?? [], $this->stripeMode);
    }

    /**
     * The tiers that can be sold in a Stripe mode: the free base tier, and
     * every paid tier whose monthly and yearly price both exist there. A tier
     * without a price is not offered anywhere, so it can be put in the config
     * before its Stripe price is created.
     *
     * @param array<int, array<string, mixed>> $tiers
     *
     * @return list<array<string, mixed>>
     */
    private static function buyableTiers(array $tiers, string $mode): array
    {
        return array_values(array_filter($tiers, static function (array $tier) use ($mode): bool {
            if ((float) ($tier['addon_monthly'] ?? 0) <= 0) {
                return true;
            }

            return ($tier['stripe_price_ids'][$mode]['monthly'] ?? '') !== ''
                && ($tier['stripe_price_ids'][$mode]['yearly'] ?? '') !== '';
        }));
    }

    /**
     * One tier of a plan by its allowance, or null when the plan has no such tier.
     *
     * @return array<string, mixed>|null
     */
    public function getPageviewTier(string $planKey, int $pageviews): ?array
    {
        foreach ($this->getPageviewTiers($planKey) as $tier) {
            if ((int) $tier['pageviews'] === $pageviews) {
                return $tier;
            }
        }

        return null;
    }

    /**
     * Monthly pageview allowance of one site: the plan's base, or the larger
     * tier the site carries. 0 means unlimited.
     *
     * A stored tier the plan does not offer is ignored, so a value left
     * behind by a removed tier can never raise the allowance.
     */
    public function resolvePageviewLimit(string $planKey, int $siteTier): int
    {
        $base = $this->getLimit($planKey, 'pageviews_per_month');
        if ($base <= 0) {
            return 0;
        }

        if ($siteTier > $base && $this->getPageviewTier($planKey, $siteTier) !== null) {
            return $siteTier;
        }

        return $base;
    }

    /**
     * What a site on this tier costs extra, per month, on the given cycle.
     * The yearly figure is the monthly equivalent, like the plan tiers.
     */
    public function getPageviewAddonPrice(string $planKey, int $pageviews, string $cycle): float
    {
        $tier = $this->getPageviewTier($planKey, $pageviews);
        if ($tier === null) {
            return 0.0;
        }

        return (float) ($tier['addon_' . $cycle] ?? $tier['addon_monthly'] ?? 0);
    }

    /**
     * Stripe price of a paid pageview tier in the current mode, or null for
     * the base tier and for tiers whose price has not been created yet.
     */
    public function getPageviewAddonPriceId(string $planKey, int $pageviews, string $cycle): ?string
    {
        $tier = $this->getPageviewTier($planKey, $pageviews);
        $priceId = $tier['stripe_price_ids'][$this->stripeMode][$cycle] ?? null;

        return ($priceId !== null && $priceId !== '') ? (string) $priceId : null;
    }

    /**
     * Reverse lookup for a pageview add-on price.
     *
     * @return array{plan_key: string, pageviews: int, cycle: string}|null
     */
    public function findPageviewTierByStripePriceId(string $priceId): ?array
    {
        if ($priceId === '') {
            return null;
        }

        foreach ($this->getConfig()['plans'] ?? [] as $planKey => $plan) {
            foreach ($plan['pageview_tiers'] ?? [] as $tier) {
                foreach (['test', 'live'] as $mode) {
                    foreach (['monthly', 'yearly'] as $cycle) {
                        if (($tier['stripe_price_ids'][$mode][$cycle] ?? '') === $priceId) {
                            return [
                                'plan_key' => (string) $planKey,
                                'pageviews' => (int) $tier['pageviews'],
                                'cycle' => $cycle,
                            ];
                        }
                    }
                }
            }
        }

        return null;
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
     * Calculate total price for a plan + cycle + quantity using volume tiering.
     *
     * All units are priced at the rate of the tier the total quantity falls
     * into — matching the Stripe prices, which use tiers_mode "volume".
     * Example: 8 sites on personal monthly = 8 * €6 = €48.
     */
    public function calculatePrice(string $planKey, string $cycle, int $quantity): float
    {
        if ($quantity <= 0) {
            return 0.0;
        }

        return round($quantity * $this->getUnitPrice($planKey, $cycle, $quantity), 2);
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

        $tiers = $plan['tiers'] ?? [];
        foreach ($tiers as $tier) {
            if ($quantity >= $tier['from'] && $quantity <= $tier['to']) {
                return (float) ($tier[$cycle] ?? $tier['monthly'] ?? 0);
            }
        }

        // Beyond the last configured tier: charge the last tier's rate
        // (Stripe's final tier is open-ended).
        if ($quantity > 0 && $tiers !== []) {
            $last = end($tiers);
            if ($quantity > $last['to']) {
                return (float) ($last[$cycle] ?? $last['monthly'] ?? 0);
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
     * True for any price this config sells today: a plan price or a pageview
     * add-on price. Revenue reports use it to tell current income from
     * retired prices.
     */
    public function isCurrentStripePrice(string $priceId): bool
    {
        return $priceId !== ''
            && ($this->findPlanByStripePriceId($priceId) !== null || $this->findPageviewTierByStripePriceId($priceId) !== null);
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
