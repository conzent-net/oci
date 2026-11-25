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

// The notice text must carry a cookie-policy link. axe's link-in-text-block
// rule (WCAG 1.4.1) only judges links that exist, and a fixture without one
// let a green, non-underlined link ship to every customer while nine
// layouts passed the nightly. Applied on every run so an old fixture picks
// it up too.
$bannerId = $db->fetchOne(
    'SELECT sb.id FROM oci_site_banners sb
     LEFT JOIN oci_banner_templates bt ON bt.id = sb.banner_template_id
     WHERE sb.site_id = ?
     ORDER BY (bt.cookie_laws = \'gdpr\') DESC, sb.id ASC
     LIMIT 1',
    [(int) $siteId],
);

if ($bannerId !== false) {
    $content = json_decode((string) $db->fetchOne('SELECT content_setting FROM oci_site_banners WHERE id = ?', [(int) $bannerId]), true);
    $content = \is_array($content) ? $content : [];
    // The three notice buttons every real site carries. A site created
    // through SiteCreationService has none until ComplianceCheckService
    // forces them on at the customer's first dashboard visit, which never
    // happens in CI — so the axe loop was scanning a banner with no buttons
    // and never opening the preference center. Same states the compliance
    // check writes.
    $content['gdpr']['cookie_notice']['accept_all_button'] = 1;
    $content['gdpr']['cookie_notice']['reject_all_button'] = 1;
    $content['gdpr']['cookie_notice']['customize_button'] = 1;
    $content['gdpr']['cookie_notice']['cookie_policy_label'] = 1;
    // Show the cookie list inside the category accordions. Without it the
    // runtime strips the tables out entirely, so the fixture never exercised
    // the path that fills them — which is where the duplicate-id defect was
    // hiding: on three layouts only the first copy of each table was ever
    // filled. Needs /api/v1/cookies to answer; if it does not, the tables
    // simply stay on their loading state, which fails nothing.
    $content['gdpr']['cookie_list']['show_cookie_on_banner'] = '1';
    $db->update('oci_site_banners', ['content_setting' => json_encode($content, JSON_UNESCAPED_SLASHES)], ['id' => (int) $bannerId]);

    $container->get(\OCI\Banner\Repository\BannerRepositoryInterface::class)
        ->setCookiePolicyUrl((int) $bannerId, (int) $siteId, 'http://localhost:8106/cookie-policy.html');
    echo "Cookie-policy link enabled on banner {$bannerId}\n";
}

$container->get(ScriptGenerationService::class)->generate((int) $siteId);
echo 'Script generated for ' . FIXTURE_KEY . "\n";
