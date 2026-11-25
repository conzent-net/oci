<?php

declare(strict_types=1);

/**
 * Switch the e2e fixture site's banner layout and regenerate its script.
 * The a11y workflow loops this over every GDPR layout so axe scans each
 * layout file, not a sample. Run INSIDE the app container:
 *
 *   docker compose exec -T app php scripts/e2e/set-layout.php gdpr/card
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use OCI\Banner\Service\ScriptGenerationService;
use OCI\Http\Kernel\Application;

const FIXTURE_KEY = 'a1b2c3d4e5f6a1b2c3d4e5f6';

$layout = $argv[1] ?? '';
if (!preg_match('#^(gdpr|ccpa)/[a-z0-9_-]+$#', $layout)) {
    fwrite(STDERR, "Usage: set-layout.php gdpr/<slug>\n");
    exit(1);
}

$app = Application::boot(dirname(__DIR__, 2));
$container = $app->getContainer();
$db = $container->get(\Doctrine\DBAL\Connection::class);

$siteId = $db->fetchOne('SELECT id FROM oci_sites WHERE website_key = ?', [FIXTURE_KEY]);
if ($siteId === false) {
    fwrite(STDERR, "Fixture site missing — run seed-fixture.php first\n");
    exit(1);
}

// 'classic' is the default (NULL); layouts.php keys are bare slugs
$db->executeStatement(
    'UPDATE oci_site_banners SET layout_key = ?, custom_layout_id = NULL WHERE site_id = ?',
    [$layout === 'gdpr/classic' ? null : $layout, (int) $siteId],
);

$container->get(ScriptGenerationService::class)->generate((int) $siteId);
echo "Layout {$layout} applied to fixture site {$siteId}\n";
