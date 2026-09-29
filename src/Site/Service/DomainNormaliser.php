<?php

declare(strict_types=1);

namespace OCI\Site\Service;

/**
 * Hostname helpers with no dependencies, shared by site creation and the
 * plugin claim.
 *
 * `normalise()` is the historical site-domain normaliser (ports the legacy
 * getDomainOnly + normalize_domain): it strips scheme, path, query, fragment
 * and trailing dots, converts IDN labels to punycode and lowercases. It keeps
 * `www.` and a `:port` on purpose, because `oci_sites.domain` stores exactly
 * what the customer typed there; the other helpers take those off where a
 * caller needs a bare host.
 */
final class DomainNormaliser
{
    public function normalise(string $domain): string
    {
        $domain = trim($domain);

        // Strip protocol first (before IDN conversion)
        $domain = (string) preg_replace('#^https?://#i', '', $domain);

        // Strip path, query, fragment: keep only the host
        $domain = explode('/', $domain)[0];
        $domain = explode('?', $domain)[0];
        $domain = explode('#', $domain)[0];

        // Strip trailing dots
        $domain = rtrim($domain, '.');

        // Handle IDN to punycode conversion (mirrors legacy normalize_domain)
        if (!$this->isPunycode($domain)) {
            $converted = idn_to_ascii($domain, \IDNA_NONTRANSITIONAL_TO_ASCII, \INTL_IDNA_VARIANT_UTS46);
            if ($converted !== false) {
                $domain = $converted;
            }
        }

        return strtolower($domain);
    }

    public function stripPort(string $host): string
    {
        return (string) preg_replace('/:\d+$/', '', $host);
    }

    public function stripWww(string $host): string
    {
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /** `example.com` for `www.example.com`, and the other way round. */
    public function wwwTwin(string $host): string
    {
        return str_starts_with($host, 'www.') ? substr($host, 4) : 'www.' . $host;
    }

    /**
     * A registrable-looking public hostname: labels of letters, digits and
     * hyphens, at least one dot, and an alphabetic or punycode top-level label.
     * IP literals, `localhost` and single labels do not pass.
     */
    public function isHostname(string $host): bool
    {
        return preg_match(
            '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+([a-z]{2,63}|xn--[a-z0-9-]{2,59})$/',
            $host,
        ) === 1;
    }

    private function isPunycode(string $domain): bool
    {
        foreach (explode('.', $domain) as $label) {
            if (str_starts_with($label, 'xn--')) {
                return true;
            }
        }

        return false;
    }
}
