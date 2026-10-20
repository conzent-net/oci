<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use OCI\Http\Kernel\Application;
use OCI\Banner\Service\ScriptGenerationService;

$siteId = (int) ($argv[1] ?? 3);

$app = Application::boot(dirname(__DIR__));
$container = $app->getContainer();
$service = $container->get(ScriptGenerationService::class);
$service->generate($siteId);

echo "Script regenerated for site {$siteId}\n";
