<?php

/**
 * OCI Middleware Group Definitions
 *
 * Each key is a middleware group name (referenced in routes).
 * Each value is an ordered list of middleware class names.
 * Middleware runs in the order listed (first → last), then the handler.
 */

declare(strict_types=1);

return [

    // Public Consent API — cross-origin, rate limited, site key auth
    'public_api' => [
        // OCI\Http\Middleware\CorsMiddleware::class,
        // OCI\Http\Middleware\RateLimitMiddleware::class,
        // OCI\Http\Middleware\SiteKeyAuthMiddleware::class,
    ],

    // Dashboard / authenticated web pages
    'web' => [
        OCI\Http\Middleware\SessionMiddleware::class,
        OCI\Http\Middleware\AuthMiddleware::class,
        // After Auth so the user attribute exists, before AuditTrail so a
        // request held at the 2FA prompt is not logged as a completed action.
        OCI\Http\Middleware\TwoFactorMiddleware::class,
        OCI\Http\Middleware\AuditTrailMiddleware::class,
    ],

    // Guest-only pages (login, register, forgot password)
    'guest' => [
        OCI\Http\Middleware\GeoBlockMiddleware::class,
        OCI\Http\Middleware\SessionMiddleware::class,
        OCI\Http\Middleware\GuestOnlyMiddleware::class,
    ],

    // Webhook endpoints — raw body, signature verification
    'webhook' => [
        // OCI\Http\Middleware\RawBodyMiddleware::class,
    ],

    // Agency surface — authenticated + APPROVED, ACTIVE agency.
    //
    // AgencyMiddleware attaches the verified agency scope to the request, which
    // is the only way a handler can obtain one — so a handler physically cannot
    // query outside its own client book. It also distinguishes "pending" from
    // "never applied" and routes each to the right page rather than a 403.
    //
    // Note for the community edition: this group and 'agency_write' are defined
    // here but unreachable, because every route that uses them lives in the
    // cloud-only Agency module. That is deliberate — this file ships publicly
    // and must never name a class from the cloud-only module namespace, so the
    // middleware itself lives in src/Http/Middleware/ and this file stays
    // byte-identical across editions.
    'agency' => [
        OCI\Http\Middleware\SessionMiddleware::class,
        OCI\Http\Middleware\AuthMiddleware::class,
        OCI\Http\Middleware\TwoFactorMiddleware::class,
        OCI\Http\Middleware\AgencyMiddleware::class,
        OCI\Http\Middleware\AuditTrailMiddleware::class,
    ],

    // Agency mutations — same chain plus CSRF.
    //
    // CSRF runs AFTER the agency gate on purpose: the role check is the cheap
    // one, and a 403 that says "not an approved agency" is far more diagnostic
    // than one that says "invalid CSRF token" when the real problem is that the
    // account was suspended an hour ago.
    'agency_write' => [
        OCI\Http\Middleware\SessionMiddleware::class,
        OCI\Http\Middleware\AuthMiddleware::class,
        OCI\Http\Middleware\TwoFactorMiddleware::class,
        OCI\Http\Middleware\AgencyMiddleware::class,
        OCI\Http\Middleware\CsrfMiddleware::class,
        OCI\Http\Middleware\AuditTrailMiddleware::class,
    ],

    // Admin panel — authenticated + admin role
    'admin' => [
        OCI\Http\Middleware\SessionMiddleware::class,
        OCI\Http\Middleware\AuthMiddleware::class,
        OCI\Http\Middleware\TwoFactorMiddleware::class,
        OCI\Http\Middleware\AdminMiddleware::class,
        OCI\Http\Middleware\AuditTrailMiddleware::class,
    ],

];
