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
        public ?string $commandsFile = null,
        public ?string $scheduledFile = null,
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
            // CLI commands and scheduled tasks a module contributes to bin/oci,
            // so the open-source core carries no reference to module classes.
            commandsFile: isset($config['commands']) && file_exists($config['commands']) ? $config['commands'] : null,
            scheduledFile: isset($config['scheduled']) && file_exists($config['scheduled']) ? $config['scheduled'] : null,
        );
    }
}
