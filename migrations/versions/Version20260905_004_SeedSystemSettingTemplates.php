<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Seed the four built-in templates as `agency_id IS NULL` rows.
 *
 * Read from `config/banner-templates.php` rather than retyped here, so the file
 * stays the single definition of what "advanced_tcf" means and this table is a
 * mirror rather than a second source that drifts. A migration that hardcoded
 * the same values would be correct on the day it ran and wrong the first time
 * anyone edited the config.
 *
 * The `content` section is empty for all four. That is not an oversight: the
 * button set these templates configure lives under a nested `gdpr` key that the
 * single-site apply path builds from the site's own privacy policy URL, so
 * copying a fixed version of it into a shared template would stamp one site's
 * URL onto every other. Agencies building their own templates capture content
 * from a real site instead, where the per-site keys are stripped on the way in.
 *
 * Idempotent via `system_key`'s unique index, so re-running cannot duplicate.
 */
final class Version20260905_004_SeedSystemSettingTemplates extends Migration
{
    public function getDescription(): string
    {
        return 'Seed basic, advanced, basic_tcf and advanced_tcf as system setting templates';
    }

    public function up(): void
    {
        $path = \dirname(__DIR__, 2) . '/config/banner-templates.php';

        if (!is_file($path)) {
            throw new \RuntimeException(
                'config/banner-templates.php is missing, so the system templates cannot be seeded from it. '
                . 'That file is the single definition of the built-in templates; seeding a hardcoded copy here '
                . 'would create a second source that silently drifts from it.',
            );
        }

        /** @var array<string, array<string, mixed>> $templates */
        $templates = require $path;
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        foreach ($templates as $key => $definition) {
            $payload = json_encode([
                'general' => $definition['general'] ?? [],
                'content' => [],
                'site' => $definition['site'] ?? [],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            $this->db->executeStatement(
                'INSERT INTO `oci_setting_templates`
                    (`agency_id`, `system_key`, `name`, `description`, `payload`, `created_at`, `updated_at`)
                 VALUES (NULL, :key, :name, :description, :payload, :now, :now)
                 ON DUPLICATE KEY UPDATE
                    `name` = VALUES(`name`),
                    `description` = VALUES(`description`),
                    `payload` = VALUES(`payload`)',
                [
                    'key' => (string) $key,
                    'name' => (string) ($definition['label'] ?? $key),
                    'description' => isset($definition['description']) ? (string) $definition['description'] : null,
                    'payload' => $payload,
                    'now' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        $this->sql('DELETE FROM `oci_setting_templates` WHERE `system_key` IS NOT NULL');
    }
}
