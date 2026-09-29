<?php

declare(strict_types=1);

namespace OCI\Scanning\Service;

/**
 * Whether a cookie was set by Conzent's own service on someone else's page.
 *
 * Cookie classification used to call every cookie whose domain contained
 * "conzent.com" or "conzent.net" necessary. The intent was the CMP's own
 * cookies on a customer's page, but getconzent.com contains "conzent.com", so
 * Google Analytics and Meta cookies on Conzent's own websites were filed as
 * strictly necessary. Once a scan after consent sees those cookies, that
 * would put them under Necessary in Conzent's own public declaration.
 *
 * A cookie on a Conzent domain is the service's own only when the page is on a
 * different site. On a Conzent website it is classified like anyone else's.
 */
final class ConzentCookieDomain
{
    private const SERVICE_DOMAINS = ['getconzent.com', 'conzent.net'];

    public static function isServiceCookieOnAnotherSite(string $cookieDomain, string $pageUrl): bool
    {
        $cookieSite = self::serviceDomainOf($cookieDomain);
        if ($cookieSite === null) {
            return false;
        }

        $pageHost = strtolower((string) (parse_url($pageUrl, PHP_URL_HOST) ?: ''));

        // No page to compare with: keep the old answer rather than guess.
        if ($pageHost === '') {
            return true;
        }

        return self::serviceDomainOf($pageHost) !== $cookieSite;
    }

    private static function serviceDomainOf(string $host): ?string
    {
        $host = strtolower(ltrim(trim($host), '.'));
        foreach (self::SERVICE_DOMAINS as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return $domain;
            }
        }

        return null;
    }
}
