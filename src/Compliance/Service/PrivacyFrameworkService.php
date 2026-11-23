<?php

declare(strict_types=1);

namespace OCI\Compliance\Service;

/**
 * Loads and queries privacy framework rules from config/privacy-frameworks.json.
 *
 * This is the single source of truth for all framework-related logic. The JSON
 * is loaded once and cached in memory. US state laws are resolved from the
 * _us_state_template + overrides pattern.
 */
final class PrivacyFrameworkService
{
    /** @var array<string, mixed>|null */
    private ?array $config = null;

    /** @var array<string, array<string, mixed>>|null Keyed by framework id */
    private ?array $frameworkIndex = null;

    /** Consent model strictness (higher = stricter) */
    private const CONSENT_MODEL_RANK = [
        'opt_in' => 4,
        'hybrid' => 3,
        'opt_out' => 2,
        'implied' => 1,
        'notice_only' => 0,
    ];

    /** Blocking strategy strictness (higher = stricter) */
    private const BLOCKING_STRATEGY_RANK = [
        'block_all_non_essential' => 2,
        'block_category_specific' => 1,
        'block_none' => 0,
    ];

    /** Region display order and labels */
    private const REGION_GROUPS = [
        'europe' => [
            'label' => 'Europe',
            'regions' => ['eu', 'eea', 'uk'],
        ],
        'north_america' => [
            'label' => 'North America',
            'regions' => ['us_state', 'us_federal', 'canada'],
        ],
        'south_america' => [
            'label' => 'South America',
            'regions' => ['latam', 'brazil'],
        ],
        'asia_pacific' => [
            'label' => 'Asia-Pacific',
            'regions' => ['asia_east', 'asia_south', 'asia_southeast', 'oceania'],
        ],
        'middle_east_africa' => [
            'label' => 'Middle East & Africa',
            'regions' => ['middle_east', 'africa'],
        ],
    ];

    public function __construct(private readonly string $configPath = '')
    {
        // Allow empty configPath — will resolve from project root at load time
    }

    private function resolveConfigPath(): string
    {
        if ($this->configPath !== '') {
            return $this->configPath;
        }

        // Fall back to project root discovery (3 levels up from src/Compliance/Service/)
        return dirname(__DIR__, 3) . '/config/privacy-frameworks.json';
    }

    /**
     * Load and cache the full JSON config.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        if ($this->config === null) {
            $json = file_get_contents($this->resolveConfigPath());
            $this->config = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        }

        return $this->config;
    }

    /**
     * Get all frameworks indexed by id (including resolved US state laws).
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAllFrameworks(): array
    {
        if ($this->frameworkIndex !== null) {
            return $this->frameworkIndex;
        }

        $config = $this->getConfig();
        $index = [];

        // Main frameworks
        foreach ($config['frameworks'] ?? [] as $fw) {
            $index[$fw['id']] = $fw;
        }

        // US state laws (inherit from _us_state_template with overrides)
        $template = $config['_us_state_template'] ?? null;
        if ($template !== null) {
            foreach ($config['us_state_laws'] ?? [] as $stateLaw) {
                $resolved = $this->resolveUsStateLaw($template, $stateLaw);
                $index[$resolved['id']] = $resolved;
            }
        }

        $this->frameworkIndex = $index;

        return $this->frameworkIndex;
    }

    /**
     * Get a single framework by id.
     *
     * @return array<string, mixed>|null
     */
    public function getFramework(string $id): ?array
    {
        return $this->getAllFrameworks()[$id] ?? null;
    }

    /**
     * Get all frameworks that are selectable in the UI (status = active).
     *
     * @return array<string, array<string, mixed>> Keyed by id
     */
    public function getSelectableFrameworks(): array
    {
        $frameworks = $this->getAllFrameworks();

        return array_filter($frameworks, static fn(array $fw): bool =>
            ($fw['status'] ?? '') === 'active'
        );
    }

    /**
     * Get selectable frameworks grouped by region for the UI.
     *
     * @return array<string, array{label: string, frameworks: list<array<string, mixed>>}>
     */
    public function getFrameworksGroupedByRegion(): array
    {
        $selectable = $this->getSelectableFrameworks();
        $grouped = [];

        foreach (self::REGION_GROUPS as $groupKey => $group) {
            $groupFrameworks = [];

            foreach ($selectable as $fw) {
                $fwRegion = $fw['region'] ?? '';
                if (in_array($fwRegion, $group['regions'], true)) {
                    $groupFrameworks[] = $fw;
                }
            }

            if ($groupFrameworks !== []) {
                // Sort: main frameworks first (no states key), then by name
                usort($groupFrameworks, static function (array $a, array $b): int {
                    $aIsState = isset($a['states']);
                    $bIsState = isset($b['states']);
                    if ($aIsState !== $bIsState) {
                        return $aIsState ? 1 : -1;
                    }

                    return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
                });

                $grouped[$groupKey] = [
                    'label' => $group['label'],
                    'frameworks' => $groupFrameworks,
                ];
            }
        }

        return $grouped;
    }

    /**
     * Find which frameworks cover a given country code.
     *
     * @return list<string> Framework IDs
     */
    public function getFrameworksForCountry(string $countryCode): array
    {
        $countryCode = strtoupper($countryCode);
        $matches = [];

        foreach ($this->getAllFrameworks() as $fw) {
            $countries = $fw['countries'] ?? [];
            if (in_array($countryCode, $countries, true)) {
                $matches[] = $fw['id'];
            }
        }

        return $matches;
    }

    // ── Rule extraction ──────────────────────────────────

    public function getConsentModel(string $frameworkId): string
    {
        $fw = $this->getFramework($frameworkId);

        return $fw['consent']['model'] ?? 'opt_in';
    }

    public function getBlockingStrategy(string $frameworkId): string
    {
        $fw = $this->getFramework($frameworkId);

        return $fw['blocking']['strategy'] ?? 'block_all_non_essential';
    }

    /**
     * @return list<string> e.g. ['accept_all', 'reject_all', 'manage_preferences']
     */
    public function getRequiredButtons(string $frameworkId): array
    {
        $fw = $this->getFramework($frameworkId);

        return $fw['banner']['required_buttons'] ?? [];
    }

    public function isDoNotSellRequired(string $frameworkId): bool
    {
        $fw = $this->getFramework($frameworkId);

        return (bool) ($fw['do_not_sell']['required'] ?? false);
    }

    public function mustHonorGpc(string $frameworkId): bool
    {
        $fw = $this->getFramework($frameworkId);

        return (bool) ($fw['signals']['must_honor_gpc'] ?? false);
    }

    public function isAcceptRejectEqualProminence(string $frameworkId): bool
    {
        $fw = $this->getFramework($frameworkId);

        return (bool) ($fw['banner']['accept_reject_equal_prominence'] ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function getSignalRequirements(string $frameworkId): array
    {
        $fw = $this->getFramework($frameworkId);

        return $fw['signals'] ?? [];
    }

    /**
     * @return list<string> Required log fields
     */
    public function getRequiredLogFields(string $frameworkId): array
    {
        $fw = $this->getFramework($frameworkId);

        return $fw['logging']['log_fields_required'] ?? [];
    }

    // ── Composite methods for multi-framework sites ──────

    /**
     * Merge rules from multiple frameworks using strictest-wins.
     *
     * @param list<string> $frameworkIds
     * @return array{
     *     consent_model: string,
     *     blocking_strategy: string,
     *     required_buttons: list<string>,
     *     do_not_sell_required: bool,
     *     must_honor_gpc: bool,
     *     must_honor_dnt: bool,
     *     iab_tcf_supported: bool,
     *     gcm_required: bool,
     *     accept_reject_equal_prominence: bool,
     *     log_fields_required: list<string>,
     *     banner_required: bool,
     *     preference_center_required: bool,
     * }
     */
    public function getMergedRules(array $frameworkIds): array
    {
        $merged = [
            'consent_model' => 'notice_only',
            'blocking_strategy' => 'block_none',
            'required_buttons' => [],
            'do_not_sell_required' => false,
            'must_honor_gpc' => false,
            'must_honor_dnt' => false,
            'iab_tcf_supported' => false,
            'gcm_required' => false,
            'accept_reject_equal_prominence' => false,
            'log_fields_required' => [],
            'banner_required' => false,
            'preference_center_required' => false,
        ];

        foreach ($frameworkIds as $fwId) {
            $fw = $this->getFramework($fwId);
            if ($fw === null) {
                continue;
            }

            // Consent model: strictest wins
            $model = $fw['consent']['model'] ?? 'notice_only';
            if ((self::CONSENT_MODEL_RANK[$model] ?? 0) > (self::CONSENT_MODEL_RANK[$merged['consent_model']] ?? 0)) {
                $merged['consent_model'] = $model;
            }

            // Blocking strategy: strictest wins
            $strategy = $fw['blocking']['strategy'] ?? 'block_none';
            if ((self::BLOCKING_STRATEGY_RANK[$strategy] ?? 0) > (self::BLOCKING_STRATEGY_RANK[$merged['blocking_strategy']] ?? 0)) {
                $merged['blocking_strategy'] = $strategy;
            }

            // Buttons: union
            $buttons = $fw['banner']['required_buttons'] ?? [];
            $merged['required_buttons'] = array_values(array_unique(array_merge($merged['required_buttons'], $buttons)));

            // Booleans: true wins
            $merged['do_not_sell_required'] = $merged['do_not_sell_required'] || ($fw['do_not_sell']['required'] ?? false);
            $merged['must_honor_gpc'] = $merged['must_honor_gpc'] || ($fw['signals']['must_honor_gpc'] ?? false);
            $merged['must_honor_dnt'] = $merged['must_honor_dnt'] || ($fw['signals']['must_honor_dnt'] ?? false);
            $merged['iab_tcf_supported'] = $merged['iab_tcf_supported'] || ($fw['signals']['iab_tcf_supported'] ?? false);
            $merged['gcm_required'] = $merged['gcm_required'] || ($fw['signals']['google_consent_mode_v2_required'] ?? false);
            $merged['accept_reject_equal_prominence'] = $merged['accept_reject_equal_prominence'] || ($fw['banner']['accept_reject_equal_prominence'] ?? false);
            $merged['banner_required'] = $merged['banner_required'] || ($fw['banner']['required'] ?? false);
            $merged['preference_center_required'] = $merged['preference_center_required'] || ($fw['banner']['preference_center_required'] ?? false);

            // Log fields: union
            $logFields = $fw['logging']['log_fields_required'] ?? [];
            $merged['log_fields_required'] = array_values(array_unique(array_merge($merged['log_fields_required'], $logFields)));
        }

        return $merged;
    }

    /**
     * Build a compact country→framework rules map for embedding in generated script.js.
     *
     * For each country covered by the selected frameworks, picks the best framework
     * (strictest-wins for overlaps) and returns a compact rule set.
     *
     * @param list<string> $frameworkIds Selected framework IDs for the site
     * @return array<string, array{fw: string, cm: string, bs: string, btn: list<string>, gpc: bool, dns: bool}>
     */
    public function getCountryToFrameworkMap(array $frameworkIds): array
    {
        $map = [];
        $strictestOverall = null;
        $strictestRank = -1;

        foreach ($frameworkIds as $fwId) {
            $fw = $this->getFramework($fwId);
            if ($fw === null) {
                continue;
            }

            $rules = $this->buildCompactRules($fw);

            // Track strictest framework overall (for _default)
            $rank = (self::CONSENT_MODEL_RANK[$rules['cm']] ?? 0) * 10
                  + (self::BLOCKING_STRATEGY_RANK[$rules['bs']] ?? 0);
            if ($rank > $strictestRank) {
                $strictestRank = $rank;
                $strictestOverall = $rules;
            }

            // Map each country to this framework's rules
            $countries = $fw['countries'] ?? [];
            foreach ($countries as $countryCode) {
                $countryCode = strtoupper($countryCode);

                if (!isset($map[$countryCode])) {
                    $map[$countryCode] = $rules;
                } else {
                    // Overlap: pick stricter
                    $existingRank = (self::CONSENT_MODEL_RANK[$map[$countryCode]['cm']] ?? 0) * 10
                                  + (self::BLOCKING_STRATEGY_RANK[$map[$countryCode]['bs']] ?? 0);
                    if ($rank > $existingRank) {
                        $map[$countryCode] = $rules;
                    }
                }
            }

            // US state laws: map with state qualifier (e.g., "US:CA")
            $states = $fw['states'] ?? [];
            foreach ($states as $stateCode) {
                $key = 'US:' . strtoupper($stateCode);
                if (!isset($map[$key])) {
                    $map[$key] = $rules;
                } else {
                    $existingRank = (self::CONSENT_MODEL_RANK[$map[$key]['cm']] ?? 0) * 10
                                  + (self::BLOCKING_STRATEGY_RANK[$map[$key]['bs']] ?? 0);
                    if ($rank > $existingRank) {
                        $map[$key] = $rules;
                    }
                }
            }
        }

        // Default fallback: strictest framework selected
        if ($strictestOverall !== null) {
            $map['_default'] = $strictestOverall;
        }

        return $map;
    }

    /**
     * Validate that a list of framework IDs are all valid.
     *
     * @param list<string> $frameworkIds
     * @return list<string> Invalid IDs (empty if all valid)
     */
    public function validateFrameworkIds(array $frameworkIds): array
    {
        $all = $this->getAllFrameworks();
        $invalid = [];

        foreach ($frameworkIds as $id) {
            if (!isset($all[$id])) {
                $invalid[] = $id;
            }
        }

        return $invalid;
    }

    // ── Private helpers ──────────────────────────────────

    /**
     * Resolve a US state law by merging template + overrides.
     *
     * @param array<string, mixed> $template
     * @param array<string, mixed> $stateLaw
     * @return array<string, mixed>
     */
    private function resolveUsStateLaw(array $template, array $stateLaw): array
    {
        $overrides = $stateLaw['overrides'] ?? [];
        unset($stateLaw['overrides']);

        // Start from template, overlay state-specific top-level fields
        $resolved = array_merge($template, $stateLaw);

        // Ensure countries is set for US state laws
        if (!isset($resolved['countries'])) {
            $resolved['countries'] = ['US'];
        }

        // Ensure region is set
        if (!isset($resolved['region'])) {
            $resolved['region'] = 'us_state';
        }

        // Deep merge overrides into the resolved framework
        foreach ($overrides as $section => $values) {
            if (is_array($values) && isset($resolved[$section]) && is_array($resolved[$section])) {
                $resolved[$section] = array_merge($resolved[$section], $values);
            } else {
                $resolved[$section] = $values;
            }
        }

        return $resolved;
    }

    /**
     * Build a compact rule set for embedding in generated script.
     *
     * @param array<string, mixed> $fw Full framework data
     * @return array{fw: string, cm: string, bs: string, btn: list<string>, gpc: bool, dns: bool}
     */
    private function buildCompactRules(array $fw): array
    {
        return [
            'fw' => $fw['id'],
            'cm' => $fw['consent']['model'] ?? 'opt_in',
            'bs' => $fw['blocking']['strategy'] ?? 'block_all_non_essential',
            'btn' => $fw['banner']['required_buttons'] ?? [],
            'gpc' => (bool) ($fw['signals']['must_honor_gpc'] ?? false),
            'dns' => (bool) ($fw['do_not_sell']['required'] ?? false),
        ];
    }
}
