<?php

declare(strict_types=1);

namespace OCI\Module;

/**
 * Discovers modules by scanning src/Modules/{Name}/module.php.
 *
 * If the Modules directory doesn't exist (Community Edition with no modules),
 * returns an empty array — the app boots normally.
 */
final class ModuleLoader
{
    /**
     * Scan the modules directory and return descriptors for all discovered modules.
     *
     * @return ModuleDescriptor[]
     */
    public static function discover(string $modulesPath): array
    {
        if (!is_dir($modulesPath)) {
            return [];
        }

        $modules = [];
        $dirs = glob($modulesPath . '/*/module.php');

        if ($dirs === false) {
            return [];
        }

        foreach ($dirs as $moduleFile) {
            $modulePath = \dirname($moduleFile);
            $config = require $moduleFile;

            if (!\is_array($config) || !isset($config['name'], $config['namespace'])) {
                continue;
            }

            $modules[] = ModuleDescriptor::fromArray($config, $modulePath);
        }

        return $modules;
    }
}
