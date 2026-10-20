<?php

/**
 * OCI — Single Entry Point
 *
 * Every HTTP request enters here. Nginx rewrites all non-static
 * requests to this file via try_files.
 *
 * Boot → Route → Middleware → Handler → Response
 */

declare(strict_types=1);

use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use OCI\Http\Kernel\Application;

// Composer autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Boot application
$app = Application::boot(dirname(__DIR__));

// Create request from PHP globals
$request = Application::createRequestFromGlobals();

// Handle request → get response
$response = $app->handle($request);

// Emit response to the client
(new SapiEmitter())->emit($response);
