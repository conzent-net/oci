<?php

declare(strict_types=1);

namespace OCI\Http\Middleware;

use OCI\Http\Handler\RequestHandlerInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Executes a chain of middleware classes, then the final handler.
 */
final class MiddlewarePipeline
{
    /** @var list<class-string> */
    private array $middlewareClasses = [];

    public function __construct(
        private readonly ContainerInterface $container,
    ) {}

    /**
     * @param class-string $middlewareClass
     */
    public function pipe(string $middlewareClass): self
    {
        $this->middlewareClasses[] = $middlewareClass;

        return $this;
    }

    public function handle(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $runner = $this->buildRunner($handler, 0);

        return $runner($request);
    }

    /**
     * Recursively build the middleware chain from outside-in.
     *
     * middleware[0] runs first, middleware[n-1] runs last before the handler.
     */
    private function buildRunner(RequestHandlerInterface $handler, int $index): callable
    {
        if ($index >= \count($this->middlewareClasses)) {
            // End of chain — call the actual handler
            return static fn (ServerRequestInterface $request): ResponseInterface
                => $handler->handle($request);
        }

        $middlewareClass = $this->middlewareClasses[$index];
        $next = $this->buildRunner($handler, $index + 1);

        return function (ServerRequestInterface $request) use ($middlewareClass, $next): ResponseInterface {
            /** @var MiddlewareInterface $middleware */
            $middleware = $this->container->get($middlewareClass);

            return $middleware->process($request, $next);
        };
    }
}
