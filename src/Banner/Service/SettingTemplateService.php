<?php

declare(strict_types=1);

namespace OCI\Banner\Service;

/**
 * Applies a settings template over a site's existing banner settings.
 *
 * Pure: no database, no filesystem, no clock. Everything here is a function of
 * its arguments, which is the only reason the merge rule below can be pinned by
 * a test at all — the behaviour it protects used to live inline in a controller
 * that needed four collaborators to instantiate.
 *
 * ## The rule: merge, never replace
 *
 * A template is a **partial** settings map. It states the handful of things a
 * compliance preset cares about and is silent about everything else, and
 * silence means "leave it alone".
 *
 * That was not true before. `content_setting` was rebuilt from a fresh literal
 * on every apply, which discarded every key the template did not mention —
 * `disable_branding` and `custom_logo` among them. The practical effect: an
 * agency that had turned Conzent branding off got it back the moment anybody
 * applied a template, with nothing anywhere to explain why. Since branding
 * removal is the agency channel's headline perk, "applying our own standard
 * silently undoes our white-label" would have been a very expensive bug to
 * find in the wild.
 */
final class SettingTemplateService
{
    /**
     * Keys a template must never touch, whatever it contains.
     *
     * These are per-site identity rather than configuration. A template that
     * carried them would overwrite one client's policy links and logo with
     * another's, which is the difference between a preset and a mistake.
     */
    public const PROTECTED_CONTENT_KEYS = [
        'custom_logo',
        'revisit_custom_icon',
        'google_privacy_url',
        'cookie_policy_url',
        'privacy_policy_url',
    ];

    /** @param array<string, array<string, mixed>> $templates */
    public function __construct(private readonly array $templates)
    {
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_keys($this->templates);
    }

    public function exists(string $key): bool
    {
        return isset($this->templates[$key]);
    }

    /** @return array<string, mixed>|null */
    public function find(string $key): ?array
    {
        return $this->templates[$key] ?? null;
    }

    /**
     * The general_setting map after applying a template.
     *
     * @param array<string, mixed> $existing
     *
     * @return array<string, mixed>
     */
    public function mergeGeneral(string $key, array $existing): array
    {
        $template = $this->templates[$key]['general'] ?? [];

        return array_replace_recursive($existing, $template);
    }

    /**
     * The site-column map a template sets.
     *
     * @return array<string, mixed>
     */
    public function siteSettings(string $key): array
    {
        return $this->templates[$key]['site'] ?? [];
    }

    /**
     * The content_setting map after applying a template.
     *
     * `array_replace_recursive` rather than a fresh build: the template's keys
     * win at every depth and anything it is silent about survives. It is the
     * right tool because content_setting is a nested map of scalars — it would
     * be the wrong tool for a list, which merges element-wise, and a list
     * appearing in this structure later would need handling here.
     *
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $templateContent
     *
     * @return array<string, mixed>
     */
    public function mergeContent(array $existing, array $templateContent): array
    {
        foreach (self::PROTECTED_CONTENT_KEYS as $protected) {
            unset($templateContent[$protected]);
        }

        return array_replace_recursive($existing, $templateContent);
    }

    /**
     * Any other settings blob a template carries — `layout` or `color` — merged
     * the same way. Both are nested maps of scalars (colours are a light theme
     * and a dark theme, each a flat map), so the same rule and the same caveat
     * about lists apply.
     *
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $template
     *
     * @return array<string, mixed>
     */
    public function mergeSection(array $existing, array $template): array
    {
        return array_replace_recursive($existing, $template);
    }

    /**
     * The part of a site's current settings that a template governs.
     *
     * Drift means "a setting the template SET has since changed". It does not
     * mean "anything at all changed": a client moving their banner from the
     * bottom-left to the bottom-right has not left a compliance template that
     * never mentioned position. Hashing the whole blob reported exactly that,
     * so every client edit read as drift and the signal was worthless.
     *
     * This walks the template's shape and picks the matching values out of the
     * current settings — the same key paths, whatever they hold now, with a
     * key the template names and the site lacks recorded as null so the
     * absence itself is part of the fingerprint. The result is what gets
     * hashed on apply and on every later comparison.
     *
     * @param array<string, mixed> $current
     * @param array<string, mixed> $template
     *
     * @return array<string, mixed>
     */
    public function governed(array $current, array $template): array
    {
        $subset = [];

        foreach ($template as $key => $value) {
            $now = $current[$key] ?? null;

            if (\is_array($value)) {
                $subset[$key] = \is_array($now) ? $this->governed($now, $value) : null;
                continue;
            }

            $subset[$key] = $now;
        }

        return $subset;
    }

    /**
     * Every difference between two full settings maps, in both directions.
     *
     * {@see self::diff()} walks the PROPOSED map, which is right for a
     * template: a template is partial and only its own keys can change. A
     * revert is different — the snapshot it restores is a full copy, so a key
     * that exists now and did not exist then is REMOVED by the revert, and a
     * preview that only walked the snapshot never mentioned it. That is how
     * the first version of the revert preview under-reported what it undid.
     *
     * @param array<string, mixed> $current
     * @param array<string, mixed> $proposed
     *
     * @return array<string, array{from: mixed, to: mixed}> dot-path => change
     */
    public function diffFull(array $current, array $proposed, string $prefix = ''): array
    {
        $changes = $this->diff($current, $proposed, $prefix);

        foreach ($current as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (!\array_key_exists($key, $proposed)) {
                $changes[$path] = ['from' => $value, 'to' => null];
                continue;
            }

            if (\is_array($value) && \is_array($proposed[$key])) {
                $changes += $this->diffFull($value, $proposed[$key], $path);
            }
        }

        return $changes;
    }

    /**
     * What would change, per key, without writing anything.
     *
     * The input to a preview-then-confirm apply: an agency about to rewrite
     * forty client banners needs to see that it is turning IAB on for nine of
     * them before it does, not afterwards. Only genuine differences are
     * reported, so a site already matching the template shows as no change
     * rather than as forty no-op writes.
     *
     * @param array<string, mixed> $current
     * @param array<string, mixed> $proposed
     *
     * @return array<string, array{from: mixed, to: mixed}> dot-path => change
     */
    public function diff(array $current, array $proposed, string $prefix = ''): array
    {
        $changes = [];

        foreach ($proposed as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            $before = $current[$key] ?? null;

            if (\is_array($value) && \is_array($before)) {
                $changes += $this->diff($before, $value, $path);
                continue;
            }

            // Loose comparison on purpose: these settings travel through JSON
            // and a legacy schema that has stored 1, "1", true and "true" for
            // the same flag at different times. Reporting `1 -> true` as a
            // change would bury the real ones in noise.
            if (!\array_key_exists($key, $current) || $before != $value) {
                $changes[$path] = ['from' => $before, 'to' => $value];
            }
        }

        return $changes;
    }
}
