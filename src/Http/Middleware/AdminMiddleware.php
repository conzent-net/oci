<?php

declare(strict_types=1);

namespace OCI\Http\Middleware;

use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Requires the user to have the 'admin' role.
 *
 * Must run after SessionMiddleware + AuthMiddleware.
 */
final class AdminMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        $user = $request->getAttribute('user');

        if ($user === null || $user['role'] !== 'admin') {
            return ApiResponse::error('Forbidden', 403);
        }

        return $next($request);
    }
}
