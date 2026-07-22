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

    // Admin panel — authenticated + admin role
    'admin' => [
        OCI\Http\Middleware\SessionMiddleware::class,
        OCI\Http\Middleware\AuthMiddleware::class,
        OCI\Http\Middleware\AdminMiddleware::class,
    ],

];
