<?php

declare(strict_types=1);

namespace OCI\Identity\Service;

/**
 * Simple CSRF token manager using PHP sessions.
 */
final class CsrfService
{
    private const SESSION_KEY = '_csrf_tokens';

    /**
     * Generate a CSRF token for a given form/intent.
     */
    public function generate(string $intent = 'default'): string
    {
        $this->ensureSession();

        $token = bin2hex(random_bytes(32));

        if (!isset($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = [];
        }

        $_SESSION[self::SESSION_KEY][$intent] = [
            'token' => $token,
            'created_at' => time(),
        ];

        return $token;
    }

    /**
     * Validate a CSRF token. Each token is single-use.
     */
    public function validate(string $token, string $intent = 'default'): bool
    {
        $this->ensureSession();

        $stored = $_SESSION[self::SESSION_KEY][$intent] ?? null;

        if ($stored === null) {
            return false;
        }

        // Remove token (single-use)
        unset($_SESSION[self::SESSION_KEY][$intent]);

        // Check expiry (1 hour)
        if (time() - $stored['created_at'] > 3600) {
            return false;
        }

        return hash_equals($stored['token'], $token);
    }

    private function ensureSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }
}
