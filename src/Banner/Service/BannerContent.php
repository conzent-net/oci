<?php

declare(strict_types=1);

namespace OCI\Banner\Service;

/**
 * Banner content settings, flat as the banner page edits them.
 *
 * The page reads and writes flat keys (`accept_all_button`, `floating_button`,
 * …) while the generator reads them nested under the part of the banner that
 * uses them (`cookie_notice.accept_all_button`), optionally wrapped in the law
 * (`gdpr` / `ccpa`). Banners imported from v1 are stored nested.
 *
 * Nothing converted the other way, so an imported banner opened the page with
 * every toggle it knows initialised from a flat key that was not there:
 * Accept all, Reject all and Customize showed as off although the banner had
 * them on, and saving anything on that page wrote them off for real. This is
 * the missing direction, and {@see \OCI\Banner\Service\ScriptGenerationService::normaliseContentSetting()}
 * is the one it mirrors.
 */
final class BannerContent
{
    /** Flat key the page uses → [section, key inside that section]. */
    public const KEYS = [
        'accept_all_button' => ['cookie_notice', 'accept_all_button'],
        'reject_all_button' => ['cookie_notice', 'reject_all_button'],
        'customize_button' => ['cookie_notice', 'customize_button'],
        'close_button' => ['cookie_notice', 'close_button'],
        // The page's toggle is named after the link; the builder after the label.
        'cookie_policy_link' => ['cookie_notice', 'cookie_policy_label'],
        'disable_branding' => ['cookie_notice', 'disable_branding'],
        'custom_logo' => ['cookie_notice', 'custom_logo'],
        'button_order' => ['cookie_notice', 'button_order'],
        'show_google_privacy_policy' => ['preference_center', 'show_google_privacy_policy'],
        'respect_global_privacy_control' => ['preference_center', 'respect_global_privacy_control'],
        'google_privacy_url' => ['preference_center', 'google_privacy_url'],
        'show_cookie_on_banner' => ['cookie_list', 'show_cookie_on_banner'],
        'embed_code' => ['cookie_list', 'embed_code'],
        'floating_button' => ['revisit_consent_button', 'floating_button'],
        'button_position' => ['revisit_consent_button', 'button_position'],
        'revisit_custom_icon' => ['revisit_consent_button', 'custom_icon'],
    ];

    /** Sections the generator reads content from. */
    public const SECTIONS = [
        'cookie_notice',
        'preference_center',
        'cookie_list',
        'revisit_consent_button',
        'opt_out_center',
    ];

    public static function isNested(array $content): bool
    {
        return isset($content['gdpr']) || isset($content['ccpa'])
            || array_intersect(array_keys($content), self::SECTIONS) !== [];
    }

    /**
     * Stored content in the flat shape the page edits.
     *
     * Flat input is returned unchanged. A stored value always wins over a flat
     * one of the same meaning, which is how the generator resolves the mixed
     * shape too. Keys the page does not manage (a policy URL, a snippet) are
     * carried along untouched so a save cannot drop them.
     *
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    public static function flatten(array $content, string $law = 'gdpr'): array
    {
        if (!self::isNested($content)) {
            return $content;
        }

        $sections = [];
        foreach (self::SECTIONS as $section) {
            if (\is_array($content[$section] ?? null)) {
                $sections[$section] = $content[$section];
            }
        }

        // A law wrapper describes this banner more precisely than the bare
        // sections beside it, so it is applied on top.
        foreach ([$law === 'ccpa' ? 'ccpa' : 'gdpr', $law === 'ccpa' ? 'gdpr' : 'ccpa'] as $wrapper) {
            if (!\is_array($content[$wrapper] ?? null)) {
                continue;
            }
            foreach (self::SECTIONS as $section) {
                if (\is_array($content[$wrapper][$section] ?? null)) {
                    $sections[$section] = array_merge($sections[$section] ?? [], $content[$wrapper][$section]);
                }
            }
            break;
        }

        $flat = [];
        foreach ($content as $key => $value) {
            if (\in_array($key, self::SECTIONS, true) || $key === 'gdpr' || $key === 'ccpa') {
                continue;
            }
            $flat[$key] = $value;
        }

        foreach (self::KEYS as $flatKey => [$section, $nestedKey]) {
            if (\array_key_exists($nestedKey, $sections[$section] ?? [])) {
                $flat[$flatKey] = $sections[$section][$nestedKey];
            }
        }

        // Anything else a section carried, kept so it survives the next save.
        foreach ($sections as $section => $fields) {
            foreach ($fields as $key => $value) {
                $known = false;
                foreach (self::KEYS as [$knownSection, $knownKey]) {
                    if ($knownSection === $section && $knownKey === $key) {
                        $known = true;
                        break;
                    }
                }
                if (!$known && !\array_key_exists($key, $flat)) {
                    $flat[$key] = $value;
                }
            }
        }

        return $flat;
    }

    /**
     * What to store when the page saves: its own keys, over everything else
     * that was stored. The page posts only the settings it shows, so without
     * this a save would drop the rest of an imported banner.
     *
     * @param array<string, mixed> $posted
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    public static function merge(array $posted, array $stored, string $law = 'gdpr'): array
    {
        return array_merge(self::flatten($stored, $law), $posted);
    }

    /**
     * The same texts addressed by another banner template's field ids.
     *
     * The GDPR and CCPA templates are separate field sets that share only a
     * handful of keys, so saving a text to both banners matches by key. A key
     * the target template does not have is dropped, never invented.
     *
     * @param array<int|string, mixed> $fields    field id → value, as posted
     * @param array<int, string>       $fromKeys  field id → key, posted banner's template
     * @param array<int, string>       $toKeys    field id → key, target banner's template
     * @return array<int, mixed> target field id → value
     */
    public static function mapFieldsByKey(array $fields, array $fromKeys, array $toKeys): array
    {
        $idOf = array_flip($toKeys);
        $mapped = [];
        foreach ($fields as $fieldId => $value) {
            $key = $fromKeys[(int) $fieldId] ?? null;
            if ($key !== null && isset($idOf[$key])) {
                $mapped[(int) $idOf[$key]] = $value;
            }
        }

        return $mapped;
    }
}
