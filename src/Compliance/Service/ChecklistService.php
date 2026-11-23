<?php

declare(strict_types=1);

namespace OCI\Compliance\Service;

use OCI\Compliance\Repository\ChecklistRepositoryInterface;

final class ChecklistService
{
    /** @var array<string, mixed>|null */
    private ?array $config = null;

    public function __construct(
        private readonly ChecklistRepositoryInterface $checklistRepo,
        private readonly string $configPath,
    ) {}

    /**
     * Load and cache the compliance checklist JSON config.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        if ($this->config === null) {
            $json = file_get_contents($this->configPath);
            $this->config = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        }

        return $this->config;
    }

    /** Region-to-tab mapping for geographic grouping. */
    private const REGION_GROUPS = [
        'europe' => [
            'name' => 'Europe',
            'regions' => ['EU', 'UK', 'Switzerland'],
        ],
        'usa' => [
            'name' => 'United States',
            'regions' => ['_usa_category'],
        ],
        'asia-pacific' => [
            'name' => 'Asia-Pacific',
            'regions' => ['China', 'India', 'Japan', 'South Korea', 'Thailand', 'Indonesia', 'Singapore', 'Malaysia', 'Australia', 'Vietnam'],
        ],
        'americas-africa' => [
            'name' => 'Americas & Africa',
            'regions' => ['Brazil', 'South Africa', 'Canada', 'UAE'],
        ],
        // Ad platform regulations (tcf-2-3, google-consent-mode, etc.) are excluded
        // from the user-facing checklist — they are handled automatically by the system.
    ];

    /**
     * Get all categories with regulations and their scores for a user.
     * Re-groups the flat JSON categories into geographic tabs.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getOverview(int $userId): array
    {
        $config = $this->getConfig();
        $counts = $this->checklistRepo->getCheckedCountsByRegulation($userId);

        // Flatten all regulations from all JSON categories
        $allRegulations = [];
        foreach ($config['categories'] as $category) {
            $isUsaCategory = ($category['id'] === 'usa');
            foreach ($category['regulations'] as $reg) {
                $reg['_from_usa'] = $isUsaCategory;
                $allRegulations[] = $reg;
            }
        }

        // Build grouped tabs
        $groups = [];
        foreach (self::REGION_GROUPS as $groupId => $groupDef) {
            $regulations = [];
            foreach ($allRegulations as $reg) {
                if ($this->regulationBelongsToGroup($reg, $groupId, $groupDef)) {
                    $total = count($reg['items']);
                    $checked = $counts[$reg['id']] ?? 0;
                    $regulations[] = [
                        'id' => $reg['id'],
                        'name' => $reg['name'],
                        'region' => $reg['region'] ?? $reg['state'] ?? '',
                        'type' => $reg['type'] ?? '',
                        'effective_date' => $reg['effective_date'] ?? '',
                        'total_count' => $total,
                        'checked_count' => $checked,
                        'score' => $this->calculateScore($total, $checked),
                    ];
                }
            }

            if (!empty($regulations)) {
                $groups[] = [
                    'id' => $groupId,
                    'name' => $groupDef['name'],
                    'regulations' => $regulations,
                ];
            }
        }

        return $groups;
    }

    /**
     * @param array<string, mixed> $reg
     * @param array<string, mixed> $groupDef
     */
    /** Ad-platform regulation IDs excluded from the user-facing checklist. */
    private const EXCLUDED_IDS = [
        'tcf-2-3', 'google-consent-mode', 'amazon-consent-signal',
        'microsoft-uet-consent-mode', 'microsoft-clarity-consent-mode',
    ];

    private function regulationBelongsToGroup(array $reg, string $groupId, array $groupDef): bool
    {
        // Always exclude ad-platform regulations
        if (in_array($reg['id'], self::EXCLUDED_IDS, true)) {
            return false;
        }

        // USA category matched by source category flag
        if ($groupId === 'usa') {
            return !empty($reg['_from_usa']);
        }

        // Skip USA-sourced regulations from region-based matching
        if (!empty($reg['_from_usa'])) {
            return false;
        }

        $region = $reg['region'] ?? $reg['state'] ?? '';

        return in_array($region, $groupDef['regions'], true);
    }

    /**
     * Get a single regulation's checklist with items merged with user's checked state.
     *
     * @return array<string, mixed>|null
     */
    public function getRegulationChecklist(int $userId, string $regulationId): ?array
    {
        $regulation = $this->findRegulation($regulationId);
        if ($regulation === null) {
            return null;
        }

        $checkedItems = array_flip($this->checklistRepo->getCheckedItems($userId, $regulationId));

        $items = [];
        foreach ($regulation['items'] as $item) {
            $items[] = [
                'id' => $item['id'],
                'text' => $item['text'],
                'checked' => isset($checkedItems[$item['id']]),
            ];
        }

        $total = count($items);
        $checked = count($checkedItems);

        return [
            'regulation' => [
                'id' => $regulation['id'],
                'name' => $regulation['name'],
                'region' => $regulation['region'] ?? $regulation['state'] ?? '',
                'type' => $regulation['type'] ?? '',
                'effective_date' => $regulation['effective_date'] ?? '',
            ],
            'items' => $items,
            'total_count' => $total,
            'checked_count' => $checked,
            'score' => $this->calculateScore($total, $checked),
        ];
    }

    /**
     * Toggle a checklist item. Returns the new state and updated score.
     *
     * @return array<string, mixed>
     */
    public function toggleItem(int $userId, string $regulationId, string $itemId): array
    {
        $regulation = $this->findRegulation($regulationId);
        if ($regulation === null) {
            return ['error' => 'Regulation not found'];
        }

        // Validate item exists in this regulation
        $itemExists = false;
        foreach ($regulation['items'] as $item) {
            if ($item['id'] === $itemId) {
                $itemExists = true;
                break;
            }
        }

        if (!$itemExists) {
            return ['error' => 'Item not found in regulation'];
        }

        // Toggle: if currently checked, uncheck; otherwise check
        $isCurrentlyChecked = $this->checklistRepo->isChecked($userId, $regulationId, $itemId);

        if ($isCurrentlyChecked) {
            $this->checklistRepo->uncheckItem($userId, $regulationId, $itemId);
            $nowChecked = false;
        } else {
            $this->checklistRepo->checkItem($userId, $regulationId, $itemId);
            $nowChecked = true;
        }

        // Recalculate score
        $checkedItems = $this->checklistRepo->getCheckedItems($userId, $regulationId);
        $total = count($regulation['items']);
        $checkedCount = count($checkedItems);

        return [
            'checked' => $nowChecked,
            'score' => $this->calculateScore($total, $checkedCount),
            'checked_count' => $checkedCount,
            'total_count' => $total,
        ];
    }

    /**
     * Find a regulation by ID across all categories.
     *
     * @return array<string, mixed>|null
     */
    private function findRegulation(string $regulationId): ?array
    {
        $config = $this->getConfig();

        foreach ($config['categories'] as $category) {
            foreach ($category['regulations'] as $reg) {
                if ($reg['id'] === $regulationId) {
                    return $reg;
                }
            }
        }

        return null;
    }

    private function calculateScore(int $total, int $checked): int
    {
        if ($total === 0) {
            return 0;
        }

        return (int) round($checked / $total * 100);
    }
}
