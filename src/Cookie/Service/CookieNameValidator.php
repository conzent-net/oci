<?php

declare(strict_types=1);

namespace OCI\Cookie\Service;

/**
 * The one test for "is this string a cookie name?".
 *
 * Cookie names reach the database from unauthenticated sources (the runtime's
 * scan beacons, scanner callbacks) and from there the public cookie list that
 * every visitor's banner renders. A real cookie name is an RFC 6265 token:
 * printable US-ASCII with no whitespace and none of the separators
 * ( ) < > @ , ; : \ " / [ ] ? = { }. Anything else is not a cookie a browser
 * set, so it is dropped at ingest and again when the list is served.
 */
final class CookieNameValidator
{
    /** RFC 7230 tchar, the set RFC 6265 uses for cookie-name. */
    private const TOKEN = '/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]{1,255}$/D';

    public static function isValidCookieName(string $name): bool
    {
        return preg_match(self::TOKEN, $name) === 1;
    }
}
