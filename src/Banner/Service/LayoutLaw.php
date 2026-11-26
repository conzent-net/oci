<?php

declare(strict_types=1);

namespace OCI\Banner\Service;

/**
 * Which law a banner and a layout belong to.
 *
 * A layout is written for one law: the GDPR layouts carry Accept and Reject
 * buttons, the CCPA layout an opt-out link and no buttons. Drawn with the
 * other law's texts it shows raw placeholders and, for a GDPR banner, no way
 * to accept or reject at all. Every place that stores or renders a layout for
 * a banner checks it against the banner's law here.
 */
final class LayoutLaw
{
    /**
     * The law of a banner row, from its template's cookie_laws (a plain
     * string, or JSON such as {"gdpr":0,"ccpa":1} on imported templates).
     * Only a CCPA-only banner is CCPA; everything else draws GDPR layouts.
     */
    public static function ofBanner(string $cookieLaws): string
    {
        $raw = trim($cookieLaws);
        if ($raw === 'ccpa') {
            return 'ccpa';
        }

        $decoded = json_decode($raw, true);
        if (\is_array($decoded) && !empty($decoded['ccpa']) && empty($decoded['gdpr'])) {
            return 'ccpa';
        }

        return 'gdpr';
    }

    /**
     * The law a layout key (gdpr/classic, ccpa/classic) is written for, or
     * null when the key names none.
     */
    public static function ofLayoutKey(?string $layoutKey): ?string
    {
        if ($layoutKey === null || $layoutKey === '') {
            return null;
        }

        $law = strtok($layoutKey, '/');

        return \in_array($law, ['gdpr', 'ccpa'], true) ? $law : null;
    }

    /**
     * Whether a layout key may be used on a banner of this law. No key fits
     * every banner: the generator then picks the law's default layout.
     */
    public static function fits(?string $layoutKey, string $bannerLaw): bool
    {
        $law = self::ofLayoutKey($layoutKey);

        return $law === null || $law === $bannerLaw;
    }
}
