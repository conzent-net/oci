<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use OCI\Http\Kernel\Application;
use OCI\Banner\Service\ScriptGenerationService;

$app = Application::boot(dirname(__DIR__));
$container = $app->getContainer();
$service = $container->get(ScriptGenerationService::class);
$db = $container->get(\Doctrine\DBAL\Connection::class);

$sites = $db->fetchAllAssociative(
    "SELECT id, domain FROM oci_sites WHERE status = 'active' AND deleted_at IS NULL"
);

echo "Regenerating scripts for " . count($sites) . " active site(s)...\n";

$ok = 0;
$fail = 0;
foreach ($sites as $site) {
    try {
        $service->generate((int) $site['id']);
        echo "  ✓ Site {$site['id']} ({$site['domain']})\n";
        $ok++;
    } catch (\Throwable $e) {
        echo "  ✗ Site {$site['id']} ({$site['domain']}): {$e->getMessage()}\n";
        $fail++;
    }
}

echo "Done: {$ok} ok, {$fail} failed\n";
