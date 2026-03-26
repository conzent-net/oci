<?php

declare(strict_types=1);

namespace OCI\Module;

/**
 * Immutable descriptor for a discovered module.
 *
 * Built from the array returned by each module's module.php file.
 */
final readonly class ModuleDescriptor
{
    /**
     * @param array<int, array<string, mixed>> $menu Menu item declarations
     */
    public function __construct(
        public string $name,
        public string $namespace,
        public string $path,
        public ?string $servicesFile = null,
        public ?string $routesFile = null,
        public ?string $templatesPath = null,
        public ?string $migrationsPath = null,
        public array $menu = [],
    ) {}

    /**
     * @param array<string, mixed> $config Array returned by module.php
     */
    public static function fromArray(array $config, string $modulePath): self
    {
        return new self(
            name: $config['name'],
            namespace: $config['namespace'],
            path: $modulePath,
            servicesFile: isset($config['services']) && file_exists($config['services']) ? $config['services'] : null,
            routesFile: isset($config['routes']) && file_exists($config['routes']) ? $config['routes'] : null,
            templatesPath: isset($config['templates']) && is_dir($config['templates']) ? $config['templates'] : null,
            migrationsPath: isset($config['migrations']) && is_dir($config['migrations']) ? $config['migrations'] : null,
            menu: $config['menu'] ?? [],
        );
    }
}
