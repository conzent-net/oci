<?php

declare(strict_types=1);

namespace OCI\Http\Middleware;

use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Requires an authenticated user. Redirects to /login if not.
 *
 * Must run after SessionMiddleware.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        $user = $request->getAttribute('user');

        if ($user === null) {
            // AJAX / API requests get JSON 401, not a redirect
            $accept = $request->getHeaderLine('Accept');
            $xhr = $request->getHeaderLine('X-Requested-With');
            $contentType = $request->getHeaderLine('Content-Type');
            $method = strtoupper($request->getMethod());

            if ($method !== 'GET' || $xhr === 'XMLHttpRequest'
                || str_contains($accept, 'application/json')
                || str_contains($contentType, 'application/json')) {
                return ApiResponse::error('Session expired. Please reload the page and log in.', 401);
            }

            // Store intended URL for redirect after login
            $intendedUrl = (string) $request->getUri();
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['intended_url'] = $intendedUrl;
            }

            return ApiResponse::redirect('/login');
        }

        return $next($request);
    }
}
