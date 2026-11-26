<?php

declare(strict_types=1);

namespace OCI\Banner\Service;

/**
 * The banner logo and the revisit button icon: links to images the site
 * owner hosts.
 *
 * The runtime writes each one into `<img src='…'>` by string concatenation,
 * so a value holding a quote would become markup on every page the banner
 * runs on. A link passes when it has no whitespace, quotes, angle brackets,
 * backslash, backtick or parentheses, and its scheme, if it has one, is http,
 * https or a base64 image. Links without a scheme (//host/…, /path, a relative
 * path) pass: v1 stored whatever its text field was given, and dropping an
 * imported logo would change a live banner.
 */
final class BannerImageUrl
{
    /** Content keys, flat as the banner page saves them. */
    public const LOGO = 'custom_logo';

    public const REVISIT_ICON = 'revisit_custom_icon';

    public static function isSafe(string $url): bool
    {
        if ($url === '') {
            return true;
        }
        if (\strlen($url) > 2048 || preg_match('/[\s"\'<>\\\\`()]/', $url) === 1) {
            return false;
        }
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) === 1) {
            return preg_match('#^https?://[^/]#i', $url) === 1
                || preg_match('#^data:image/(?:png|jpe?g|gif|webp);base64,[a-z0-9+/=]+$#i', $url) === 1;
        }

        return true;
    }

    /** A safe link comes back trimmed; anything else as ''. v1's '#' placeholder means none. */
    public static function sanitize(mixed $url): string
    {
        if (!\is_string($url)) {
            return '';
        }
        $url = trim($url);
        if ($url === '#') {
            return '';
        }

        return self::isSafe($url) ? $url : '';
    }

    /**
     * The stored link for the logo or the revisit icon, wherever this banner's
     * content keeps it: flat as the banner page saves it, or nested as
     * imported v1 banners store it, with or without the law level.
     *
     * @param array<string, mixed> $content
     */
    public static function fromContent(array $content, string $key): string
    {
        [$section, $nestedKey] = $key === self::REVISIT_ICON
            ? ['revisit_consent_button', 'custom_icon']
            : ['cookie_notice', 'custom_logo'];

        $candidates = [$content[$key] ?? null, $content[$section][$nestedKey] ?? null];
        foreach (['gdpr', 'ccpa'] as $law) {
            $candidates[] = $content[$law][$section][$nestedKey] ?? null;
        }
        foreach ($candidates as $candidate) {
            $url = self::sanitize($candidate);
            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }
}
