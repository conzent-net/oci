<?php

declare(strict_types=1);

namespace OCI\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PSR-15-compatible middleware interface — owned by OCI.
 */
interface MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        callable $next,
    ): ResponseInterface;
}
