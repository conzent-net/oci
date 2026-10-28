<?php

declare(strict_types=1);

namespace OCI\Http\Handler;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * All OCI request handlers implement this interface.
 * This is our own interface — not tied to any framework.
 * Compatible with PSR-15 RequestHandlerInterface by design.
 */
interface RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface;
}
