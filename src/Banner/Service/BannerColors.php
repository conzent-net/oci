<?php

declare(strict_types=1);

namespace OCI\Banner\Service;

/**
 * Where each banner color lives, and what it is when nobody set it.
 *
 * The banner page edits colors flat (element → theme → field); the generator
 * reads them nested under the section of the banner that uses them
 * (section → theme → element → field). Both directions and the default
 * palette live here, so the page and the banner cannot disagree again. They
 * did in two ways:
 *
 *  - The page carried its own hard-coded defaults. An unsaved Reject all or
 *    Customize button showed grey in the color tab while the banner, filled
 *    from the generator's palette, rendered white with green.
 *  - The opt-out center's Cancel, Save and checkbox colors were filed under
 *    the preference center, and the "Do not sell" link color under the
 *    opt-out center, while the generator reads each from the other section.
 *    Those colors never reached a banner.
 */
final class BannerColors
{
    /**
     * Flat element on the banner page → [section, element in that section].
     * Sections match the v1 color form, which the generator was written for.
     */
    public const ELEMENTS = [
        'banner' => ['cookie_notice', 'banner'],
        'accept_all_button' => ['cookie_notice', 'accept_all_button'],
        'reject_all_button' => ['cookie_notice', 'reject_all_button'],
        'customize_button' => ['cookie_notice', 'customize_button'],
        'do_not_sell_link' => ['cookie_notice', 'do_not_sell_link'],
        'save_preference_button' => ['preference_center', 'save_preference_button'],
        'toggle_switch' => ['preference_center', 'toggle_switch'],
        'opt_out_save_button' => ['opt_out_center', 'save_preference_button'],
        'cancel_button' => ['opt_out_center', 'cancel_button'],
        'checkbox' => ['opt_out_center', 'checkbox'],
        'floating_button' => ['revisit_consent_button', 'floating_button'],
        'alttext_button' => ['alttext_blocked_content', 'button'],
    ];

    /** The fields the banner page edits for each element. */
    public const FIELDS = [
        'banner' => ['background', 'border', 'title', 'message'],
        'accept_all_button' => ['background', 'border', 'text'],
        'reject_all_button' => ['background', 'border', 'text'],
        'customize_button' => ['background', 'border', 'text'],
        'do_not_sell_link' => ['text'],
        'save_preference_button' => ['background', 'border', 'text'],
        'toggle_switch' => ['enabled_state', 'disabled_state'],
        'opt_out_save_button' => ['background', 'border', 'text'],
        'cancel_button' => ['background', 'border', 'text'],
        'checkbox' => ['enabled_state', 'disabled_state'],
        'floating_button' => ['background'],
        'alttext_button' => ['background', 'border', 'text'],
    ];

    /**
     * What the generator fills an unset color with, nested. A field missing
     * here, or set to '', gets no inline color and the banner stylesheet
     * decides.
     *
     * @return array<string, array<string, array<string, array<string, string>>>>
     */
    public static function palette(): array
    {
        $btn = static fn (string $bg, string $text, string $border) => [
            'background' => $bg, 'text' => $text, 'border' => $border,
        ];

        // WCAG AA: #0C7A5F is 5.3:1 against white — the old #10a37f was
        // 2.9:1, failing 1.4.3 (text) and 1.4.11 (the toggle) out of the box.
        // One constant fixes accept, reject, customize, save and the switch.
        $green = '#0C7A5F';

        $theme = [
            'banner' => ['background' => '#ffffff', 'border' => '#f4f4f4', 'title' => '#212121', 'message' => '#4b4b4b'],
            'accept_all_button' => $btn($green, '#ffffff', $green),
            'reject_all_button' => $btn('#ffffff', $green, $green),
            'customize_button' => $btn('#ffffff', $green, $green),
        ];

        $preference = [
            'save_preference_button' => $btn($green, '#ffffff', $green),
            'toggle_switch' => ['enabled_state' => $green, 'disabled_state' => '#dddddd'],
        ];

        // The opt-out center's own defaults, which used to be written inline
        // in the generator where the page could not see them.
        $optOut = [
            'save_preference_button' => $btn('#115cfa', '#ffffff', '#115cfa'),
            'cancel_button' => $btn('', '#4b4b4b', '#cccccc'),
        ];

        return [
            'cookie_notice' => ['light' => $theme, 'dark' => $theme],
            'preference_center' => ['light' => $preference, 'dark' => $preference],
            'opt_out_center' => ['light' => $optOut, 'dark' => $optOut],
            // No default background: the stock revisit icon is a full-color glyph,
            // and a palette-colored disc behind it camouflages it. Only a color the
            // user explicitly saved should paint the wrapper.
            'revisit_consent_button' => [
                'light' => ['floating_button' => $btn('', '#ffffff', '')],
                'dark' => ['floating_button' => $btn('', '#ffffff', '')],
            ],
        ];
    }

    /**
     * Flat page colors → the nested shape the generator reads. An element the
     * map does not know goes under the cookie notice, as it always has.
     *
     * @param array<array-key, mixed> $flat
     * @return array<string, array<string, array<string, array<string, mixed>>>>
     */
    public static function nest(array $flat): array
    {
        $nested = [];
        foreach ($flat as $element => $themes) {
            if (!\is_array($themes)) {
                continue;
            }
            [$section, $target] = self::ELEMENTS[$element] ?? ['cookie_notice', (string) $element];

            foreach ($themes as $theme => $fields) {
                if (!\is_array($fields)) {
                    continue;
                }
                $nested[$section][$theme][$target] = array_merge(
                    $nested[$section][$theme][$target] ?? [],
                    $fields,
                );
            }
        }

        return $nested;
    }

    /**
     * Stored colors → the flat shape the page edits. Flat input comes back
     * unchanged; metadata such as `_activeTheme` is kept.
     *
     * @param array<array-key, mixed> $colors
     * @return array<array-key, mixed>
     */
    public static function flatten(array $colors): array
    {
        $sections = array_unique(array_column(self::ELEMENTS, 0));
        if (array_intersect(array_keys($colors), $sections) === []) {
            return $colors;
        }

        $reverse = [];
        foreach (self::ELEMENTS as $flatElement => [$section, $target]) {
            $reverse[$section][$target] = $flatElement;
        }

        $flat = [];
        foreach ($colors as $key => $value) {
            if (!\is_array($value) || !\in_array($key, $sections, true)) {
                $flat[$key] = $value;
                continue;
            }
            foreach ($value as $theme => $elements) {
                if (!\is_array($elements)) {
                    continue;
                }
                foreach ($elements as $element => $fields) {
                    if (!\is_array($fields)) {
                        continue;
                    }
                    $flatElement = $reverse[$key][$element] ?? $element;
                    $flat[$flatElement][$theme] = array_merge($flat[$flatElement][$theme] ?? [], $fields);
                }
            }
        }

        return $flat;
    }

    /**
     * The defaults the banner page shows, flat and per theme: exactly what
     * the generator will use for a color nobody saved. '' means the banner
     * stylesheet decides.
     *
     * @return array{light: array<string, array<string, string>>, dark: array<string, array<string, string>>}
     */
    public static function pageDefaults(): array
    {
        $flat = self::flatten(self::palette());
        $out = ['light' => [], 'dark' => []];
        foreach (self::FIELDS as $element => $fields) {
            foreach (['light', 'dark'] as $theme) {
                foreach ($fields as $field) {
                    $out[$theme][$element][$field] = (string) ($flat[$element][$theme][$field] ?? '');
                }
            }
        }

        return $out;
    }
}
