<?php

declare(strict_types=1);

namespace OCI\Banner\Service;

/**
 * The colour values a banner may carry.
 *
 * Banner colours are written straight into the bundle: into style="…"
 * attributes on the buttons and the revisit button, and into the stylesheet
 * for the switches and checkboxes. Nothing escapes them there, and the save
 * endpoint takes whatever JSON it is sent, so a value like
 * `red" onmouseover="…` would have become markup on every page the banner
 * runs on. Only real colour values pass:
 *
 *  - hex with 3, 4, 6 or 8 digits (#fff, #ffff, #ffffff, #ffffff80)
 *  - rgb()/rgba() and hsl()/hsla(), in comma or space syntax
 *  - a single word: a named colour, `transparent`, `currentColor`
 *
 * None of those can hold a quote, a semicolon, a brace, a nested bracket or
 * a comment, which is what an injection needs. Anything else becomes an
 * empty string, which the generator treats as "not set".
 */
final class ColorValue
{
    private const HEX = '/^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i';

    private const WORD = '/^[a-z]{3,30}$/i';

    private const FUNCTIONAL = '/^(?:rgba?|hsla?)\([0-9a-z.%, \t\/+-]{1,80}\)$/i';

    public static function isSafe(string $value): bool
    {
        return preg_match(self::HEX, $value) === 1
            || preg_match(self::WORD, $value) === 1
            || preg_match(self::FUNCTIONAL, $value) === 1;
    }

    /**
     * A safe colour comes back trimmed; anything else as ''. Values that are
     * not strings cannot carry markup and are returned untouched.
     */
    public static function sanitize(mixed $value): mixed
    {
        if (!\is_string($value)) {
            return $value;
        }

        $value = trim($value);

        return $value === '' || self::isSafe($value) ? $value : '';
    }

    /**
     * Every string in a colour setting, at any depth, run through sanitize().
     * The structure is kept, so theme markers such as `_activeTheme` survive
     * (a single word) and a stripped value falls back to the default palette.
     *
     * @param array<array-key, mixed> $colors
     * @return array<array-key, mixed>
     */
    public static function sanitizeTree(array $colors): array
    {
        foreach ($colors as $key => $value) {
            $colors[$key] = \is_array($value) ? self::sanitizeTree($value) : self::sanitize($value);
        }

        return $colors;
    }
}
