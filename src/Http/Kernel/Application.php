<?php

declare(strict_types=1);

namespace OCI\Http\Kernel;

use DI\ContainerBuilder;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use OCI\Http\Middleware\MiddlewarePipeline;
use OCI\Http\Response\ApiResponse;
use OCI\Module\ModuleLoader;
use OCI\Module\ModuleRegistry;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment as TwigEnvironment;

use function FastRoute\simpleDispatcher;

final class Application
{
    private ContainerInterface $container;
    private Dispatcher $dispatcher;
    private LoggerInterface $logger;
    private ModuleRegistry $moduleRegistry;
    private string $environment;
    private bool $debug;

    /** @var array<string, list<class-string>> */
    private array $middlewareGroups = [];

    private function __construct() {}

    /**
     * Boot the application: load config, build DI container, compile routes.
     */
    public static function boot(string $basePath): self
    {
        $app = new self();
        $app->loadEnvironment($basePath);
        $app->environment = $_ENV['APP_ENV'] ?? 'dev';
        $app->debug = filter_var($_ENV['APP_DEBUG'] ?? true, FILTER_VALIDATE_BOOLEAN);

        // Logger (available before container)
        $app->logger = new Logger('oci');
        $logStream = $app->environment === 'test' ? 'php://memory' : 'php://stderr';
        $app->logger->pushHandler(new StreamHandler($logStream, Logger::DEBUG));

        // Modules (discover before container so module services can be registered)
        $modules = ModuleLoader::discover($basePath . '/src/Modules');
        $app->moduleRegistry = new ModuleRegistry($modules);

        // DI Container
        $app->container = $app->buildContainer($basePath);

        // Routes (core + module routes)
        $app->dispatcher = $app->compileRoutes($basePath);

        // Middleware groups
        $middlewareConfig = $basePath . '/config/middleware.php';
        if (file_exists($middlewareConfig)) {
            $app->middlewareGroups = require $middlewareConfig;
        }

        return $app;
    }

    /**
     * Handle an incoming HTTP request and return a response.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Parse JSON request bodies so getParsedBody() works for JSON content
        $request = $this->parseJsonBody($request);

        try {
            $routeInfo = $this->dispatcher->dispatch(
                $request->getMethod(),
                $request->getUri()->getPath(),
            );

            return match ($routeInfo[0]) {
                Dispatcher::NOT_FOUND => $this->errorResponse($request, 404, 'Not Found'),
                Dispatcher::METHOD_NOT_ALLOWED => $this->errorResponse($request, 405, 'Method Not Allowed'),
                Dispatcher::FOUND => $this->dispatchRoute(
                    $request,
                    $routeInfo[1],
                    $routeInfo[2],
                ),
                default => $this->errorResponse($request, 500, 'Internal Server Error'),
            };
        } catch (\Throwable $e) {
            $this->logger->error('Unhandled exception', [
                'exception' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'trace' => $this->debug ? $e->getTraceAsString() : '[hidden]',
            ]);

            $trace = $this->debug
                ? $e->getMessage() . "\n\n" . $e->getFile() . ':' . $e->getLine() . "\n\n" . $e->getTraceAsString()
                : null;

            return $this->errorResponse($request, 500, 'Internal Server Error', $trace);
        }
    }

    /**
     * Create a ServerRequest from PHP globals.
     */
    public static function createRequestFromGlobals(): ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $creator = new ServerRequestCreator($factory, $factory, $factory, $factory);

        return $creator->fromGlobals();
    }

    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    public function getEnvironment(): string
    {
        return $this->environment;
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }

    public function getModuleRegistry(): ModuleRegistry
    {
        return $this->moduleRegistry;
    }

    // ── Private helpers ─────────────────────────────────────

    private function loadEnvironment(string $basePath): void
    {
        if (class_exists(\Dotenv\Dotenv::class)) {
            $dotenv = \Dotenv\Dotenv::createImmutable($basePath, ['.env', '.env.local']);
            $dotenv->safeLoad();
        }
    }

    private function buildContainer(string $basePath): ContainerInterface
    {
        $builder = new ContainerBuilder();

        // Always use autowiring
        $builder->useAutowiring(true);

        // Compile container in production for speed
        if ($this->environment === 'prod') {
            $builder->enableCompilation($basePath . '/var/cache/container');
        }

        // Load service definitions
        $servicesFile = $basePath . '/config/services.php';
        if (file_exists($servicesFile)) {
            $builder->addDefinitions($servicesFile);
        }

        // Register self + module registry
        $builder->addDefinitions([
            self::class => $this,
            LoggerInterface::class => $this->logger,
            ModuleRegistry::class => $this->moduleRegistry,
            'config.base_path' => $basePath,
            'config.environment' => $this->environment,
            'config.debug' => $this->debug,
        ]);

        // Module service definitions (loaded after core — module bindings override core)
        foreach ($this->moduleRegistry->all() as $module) {
            if ($module->servicesFile !== null) {
                $builder->addDefinitions($module->servicesFile);
            }
        }

        return $builder->build();
    }

    private function compileRoutes(string $basePath): Dispatcher
    {
        $routesFile = $basePath . '/config/routes.php';
        $moduleRegistry = $this->moduleRegistry;

        return simpleDispatcher(function (RouteCollector $r) use ($routesFile, $moduleRegistry): void {
            // Core routes
            if (file_exists($routesFile)) {
                $defineRoutes = require $routesFile;
                $defineRoutes($r);
            }

            // Module routes (loaded after core)
            foreach ($moduleRegistry->all() as $module) {
                if ($module->routesFile !== null) {
                    $defineModuleRoutes = require $module->routesFile;
                    $defineModuleRoutes($r);
                }
            }
        });
    }

    /**
     * @param array<string, string> $vars Route parameters
     */
    private function dispatchRoute(
        ServerRequestInterface $request,
        mixed $routeHandler,
        array $vars,
    ): ResponseInterface {
        // Inject route parameters into request attributes
        foreach ($vars as $key => $value) {
            $request = $request->withAttribute($key, $value);
        }

        // Route handler can be:
        // 1. ['handler' => ClassName::class, 'middleware' => 'group_name']
        // 2. ClassName::class (no middleware)
        if (\is_array($routeHandler)) {
            $handlerClass = $routeHandler['handler'];
            $middlewareGroup = $routeHandler['middleware'] ?? null;
        } else {
            $handlerClass = $routeHandler;
            $middlewareGroup = null;
        }

        // Resolve the handler from the container
        $handler = $this->container->get($handlerClass);

        // If there's a middleware group, run through the pipeline
        if ($middlewareGroup !== null && isset($this->middlewareGroups[$middlewareGroup])) {
            $pipeline = new MiddlewarePipeline($this->container);
            foreach ($this->middlewareGroups[$middlewareGroup] as $middlewareClass) {
                $pipeline->pipe($middlewareClass);
            }

            return $pipeline->handle($request, $handler);
        }

        // No middleware — call handler directly
        return $handler->handle($request);
    }

    /**
     * Return an error response — HTML page for browsers, JSON for API/htmx requests.
     */
    private function errorResponse(
        ServerRequestInterface $request,
        int $status,
        string $message,
        ?string $trace = null,
    ): ResponseInterface {
        // API / htmx / JSON requests get JSON
        if (!$this->isBrowserRequest($request)) {
            if ($trace !== null) {
                return ApiResponse::error($message . "\n\n" . $trace, $status);
            }
            return ApiResponse::error($message, $status);
        }

        // Browser requests get a styled HTML error page
        $errorMeta = $this->getErrorMeta($status);

        try {
            /** @var TwigEnvironment $twig */
            $twig = $this->container->get(TwigEnvironment::class);

            $html = $twig->render('errors/error.html.twig', [
                'status' => $status,
                'title' => $errorMeta['title'],
                'subtitle' => $errorMeta['subtitle'],
                'url' => (string) $request->getUri(),
                'trace' => $trace,
            ]);

            return ApiResponse::html($html, $status);
        } catch (\Throwable) {
            // If Twig fails, fall back to JSON
            return ApiResponse::error($message, $status);
        }
    }

    /**
     * Check if the request is from a browser (not API/htmx/JSON).
     */
    private function isBrowserRequest(ServerRequestInterface $request): bool
    {
        // htmx partial requests should get JSON (the main page handles display)
        if ($request->getHeaderLine('HX-Request') === 'true') {
            return false;
        }

        // Explicit JSON requests
        $accept = $request->getHeaderLine('Accept');
        if (str_contains($accept, 'application/json') && !str_contains($accept, 'text/html')) {
            return false;
        }

        // XHR requests (jQuery, fetch with custom header, etc.)
        if ($request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest') {
            return false;
        }

        return str_contains($accept, 'text/html') || str_contains($accept, '*/*');
    }

    /**
     * @return array{title: string, subtitle: string}
     */
    private function getErrorMeta(int $status): array
    {
        return match ($status) {
            401 => ['title' => 'Unauthorized', 'subtitle' => 'Please log in to continue.'],
            402 => ['title' => 'Payment Required', 'subtitle' => 'Upgrade your plan to access this feature.'],
            403 => ['title' => 'Forbidden', 'subtitle' => "You don't have permission to access this page."],
            404 => ['title' => 'Not Found', 'subtitle' => "The page you're looking for doesn't exist."],
            405 => ['title' => 'Method Not Allowed', 'subtitle' => 'This request method is not supported.'],
            500 => ['title' => 'Server Error', 'subtitle' => 'Something went wrong on our end.'],
            default => ['title' => 'Error', 'subtitle' => 'An unexpected error occurred.'],
        };
    }

    /**
     * Parse JSON request body into parsedBody when Content-Type is application/json.
     */
    private function parseJsonBody(ServerRequestInterface $request): ServerRequestInterface
    {
        $contentType = $request->getHeaderLine('Content-Type');
        if (!str_contains($contentType, 'application/json')) {
            return $request;
        }

        $body = (string) $request->getBody();
        if ($body === '') {
            return $request;
        }

        $decoded = json_decode($body, true);
        if (!\is_array($decoded)) {
            return $request;
        }

        return $request->withParsedBody($decoded);
    }
}
