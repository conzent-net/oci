<?php

declare(strict_types=1);

/**
 * Seed the e2e fixture: a user + a site on domain `localhost` with the
 * website key the docker/testsite pages embed. Idempotent — reruns just
 * regenerate the script. Run INSIDE the app container:
 *
 *   docker compose exec -T app php scripts/e2e/seed-fixture.php
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use OCI\Banner\Service\ScriptGenerationService;
use OCI\Http\Kernel\Application;
use OCI\Site\DTO\CreateSiteInput;
use OCI\Site\Service\SiteCreationService;

const FIXTURE_KEY = 'a1b2c3d4e5f6a1b2c3d4e5f6';
const FIXTURE_DOMAIN = 'localhost';
const FIXTURE_EMAIL = 'e2e-fixture@example.com';

$app = Application::boot(dirname(__DIR__, 2));
$container = $app->getContainer();
$db = $container->get(\Doctrine\DBAL\Connection::class);

$siteId = $db->fetchOne('SELECT id FROM oci_sites WHERE website_key = ?', [FIXTURE_KEY]);

if ($siteId === false) {
    // User
    $userId = $db->fetchOne('SELECT id FROM oci_users WHERE email = ?', [FIXTURE_EMAIL]);
    if ($userId === false) {
        $db->insert('oci_users', [
            'username' => 'e2e-fixture',
            'email' => FIXTURE_EMAIL,
            'email_verified' => 'VERIFIED',
            'first_name' => 'E2E',
            'last_name' => 'Fixture',
            'password' => password_hash('E2eFixture123!', PASSWORD_BCRYPT),
            'role' => 'customer',
            'is_active' => 1,
            // No subscription rows in a fresh CI database — a non-enterprise
            // user without one counts as plan-exceeded and generates an
            // EMPTY script. Enterprise skips every plan gate.
            'is_enterprise' => 1,
        ]);
        $userId = (int) $db->lastInsertId();
    }
    $user = $db->fetchAssociative('SELECT * FROM oci_users WHERE id = ?', [(int) $userId]);

    // Site through the real creation path (banner, categories, languages,
    // script all set up), then pinned to the fixture identity — the
    // creation service rejects dotless domains, so `localhost` is applied
    // after the fact.
    $creation = $container->get(SiteCreationService::class);
    $result = $creation->createSite($user, new CreateSiteInput(
        domain: 'e2e-fixture.example.com',
        siteName: 'E2E fixture site',
    ));
    $siteId = $result->siteId;

    // New sites are born suspended (pending verification) and a suspended
    // site generates an empty script — the fixture must be active.
    $db->update('oci_sites', [
        'domain' => FIXTURE_DOMAIN,
        'website_key' => FIXTURE_KEY,
        'status' => 'active',
    ], ['id' => $siteId]);

    echo "Fixture site created: id={$siteId}\n";
} else {
    echo "Fixture site exists: id={$siteId}\n";
}

$container->get(ScriptGenerationService::class)->generate((int) $siteId);
echo 'Script generated for ' . FIXTURE_KEY . "\n";
