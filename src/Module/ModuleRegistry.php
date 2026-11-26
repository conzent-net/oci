<?php

declare(strict_types=1);

namespace OCI\Module;

/**
 * In-memory registry of all discovered modules.
 *
 * Injectable via DI — any service or Twig extension can check
 * whether a module is active with $registry->has('Agency').
 */
final class ModuleRegistry
{
    /** @var array<string, ModuleDescriptor> Keyed by module name */
    private array $modules = [];

    /**
     * @param ModuleDescriptor[] $modules
     */
    public function __construct(array $modules = [])
    {
        foreach ($modules as $module) {
            $this->modules[$module->name] = $module;
        }
    }

    /**
     * @return ModuleDescriptor[]
     */
    public function all(): array
    {
        return array_values($this->modules);
    }

    public function get(string $name): ?ModuleDescriptor
    {
        return $this->modules[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->modules[$name]);
    }

    /**
     * Get all menu items from all modules, grouped by section.
     *
     * Each item has: section, label, icon, route, active_page, and
     * optional visibility conditions (role, edition).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function getMenuItemsBySection(): array
    {
        $sections = [];
        foreach ($this->modules as $module) {
            foreach ($module->menu as $item) {
                $section = $item['section'] ?? $module->name;
                $sections[$section][] = $item;
            }
        }

        // Sort items within each section by weight
        foreach ($sections as &$items) {
            usort($items, static fn(array $a, array $b): int =>
                ($a['weight'] ?? 50) <=> ($b['weight'] ?? 50)
            );
        }

        return $sections;
    }
}
