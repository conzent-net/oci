<?php

/**
 * OCI Route Definitions
 *
 * All routes are defined here. Each route maps to a handler class
 * that implements RequestHandlerInterface.
 *
 * Format:
 *   $r->get('/path', HandlerClass::class)                          — no middleware
 *   $r->get('/path', ['handler' => HandlerClass::class, 'middleware' => 'group'])
 *
 * @see config/middleware.php for middleware group definitions
 */

declare(strict_types=1);

use FastRoute\RouteCollector;
use OCI\Dashboard\Controller\ApplyTemplateHandler;
use OCI\Dashboard\Controller\CommissionReportHandler;
use OCI\Dashboard\Controller\ComplianceCheckHandler;
use OCI\Dashboard\Controller\ScriptCheckHandler;
use OCI\Dashboard\Controller\ConsentReportHandler;
use OCI\Dashboard\Controller\CustomerReportHandler;
use OCI\Dashboard\Controller\DashboardHandler;
use OCI\Dashboard\Controller\RecommendationsHandler;
use OCI\Dashboard\Controller\PageviewReportHandler;
use OCI\Dashboard\Controller\SiteStatusHandler;
use OCI\Http\Handler\HealthCheckHandler;
use OCI\Identity\Controller\ForgotPasswordHandler;
use OCI\Identity\Controller\ForgotPasswordPageHandler;
use OCI\Identity\Controller\LoginHandler;
use OCI\Identity\Controller\LoginPageHandler;
use OCI\Identity\Controller\LogoutHandler;
use OCI\Identity\Controller\ResetPasswordHandler;
use OCI\Identity\Controller\ResetPasswordPageHandler;
use OCI\Identity\Controller\GoogleLoginHandler;
use OCI\Identity\Controller\GoogleCallbackHandler;
use OCI\Identity\Controller\AccountSetupHandler;
use OCI\Identity\Controller\AccountProfileHandler;
use OCI\Identity\Controller\AccountDeleteHandler;
use OCI\Banner\Controller\BannerContentHandler;
use OCI\Banner\Controller\BannerContentUpdateHandler;
use OCI\Banner\Controller\BannerListHandler;
use OCI\Banner\Controller\BannerUpdateHandler;
use OCI\Banner\Controller\BannerPurgeCacheHandler;
use OCI\Banner\Controller\GenerateScriptHandler;
use OCI\Banner\Controller\TranslateContentHandler;
use OCI\Scanning\Controller\BeaconIngestHandler;
use OCI\Scanning\Controller\ScanCancelHandler;
use OCI\Scanning\Controller\ScanDetailHandler;
use OCI\Scanning\Controller\ScanListHandler;
use OCI\Scanning\Controller\ScanScheduleHandler;
use OCI\Scanning\Controller\ScanStartHandler;
use OCI\Scanning\Controller\ScanWebhookHandler;
use OCI\Consent\Controller\ConsentLogHandler;
use OCI\Consent\Controller\PageviewLogHandler;
use OCI\Infrastructure\GeoIp\GeoIpHandler;
use OCI\Site\Controller\CreateSiteHandler;
use OCI\Site\Controller\CreateSitePageHandler;
use OCI\Site\Controller\SiteDeleteHandler;
use OCI\Site\Controller\SiteDestroyHandler;
use OCI\Site\Controller\SiteListHandler;
use OCI\Site\Controller\SiteRestoreHandler;
use OCI\Site\Controller\SiteSwitchHandler;
use OCI\Site\Controller\LanguageAddHandler;
use OCI\Site\Controller\LanguageDefaultHandler;
use OCI\Site\Controller\LanguageListHandler;
use OCI\Site\Controller\LanguageRemoveHandler;
use OCI\Site\Controller\SiteUpdateHandler;
use OCI\Site\Controller\GtmAuthHandler;
use OCI\Site\Controller\GtmCallbackHandler;
use OCI\Site\Controller\GtmAccountsHandler;
use OCI\Site\Controller\GtmWizardHandler;
use OCI\Site\Controller\GtmWorkspacesHandler;
use OCI\Site\Controller\GtmCreateWorkspaceHandler;
use OCI\Site\Controller\GtmWizardApplyHandler;
use OCI\Cookie\Controller\CookieListHandler;
use OCI\Cookie\Controller\CookieCreateHandler;
use OCI\Cookie\Controller\CookieUpdateHandler;
use OCI\Cookie\Controller\CookieDeleteHandler;
use OCI\Cookie\Controller\CookieImportHandler;
use OCI\Cookie\Controller\CookieResetObservationsHandler;
use OCI\Cookie\Controller\CategoryListHandler;
use OCI\Cookie\Controller\CategoryAddHandler;
use OCI\Cookie\Controller\CategoryUpdateHandler;
use OCI\Cookie\Controller\CategoryDeleteHandler;
use OCI\Consent\Controller\ConsentListHandler;
use OCI\Consent\Controller\ConsentDetailHandler;
use OCI\Consent\Controller\ConsentAnalyticsHandler;
use OCI\Policy\Controller\PolicyListHandler;
use OCI\Policy\Controller\CookiePolicyWizardHandler;
use OCI\Policy\Controller\PrivacyPolicyWizardHandler;
use OCI\Policy\Controller\CookiePolicySaveHandler;
use OCI\Policy\Controller\PrivacyPolicySaveHandler;
use OCI\Policy\Controller\PolicyTemplateSaveHandler;
use OCI\Policy\Controller\PolicyTemplateDeleteHandler;
use OCI\Policy\Controller\PolicyTemplateApplyHandler;
use OCI\Policy\Controller\PolicyTemplateRenameHandler;
use OCI\Policy\Controller\PolicyClearHandler;
use OCI\Banner\Controller\LayoutListHandler;
use OCI\Banner\Controller\LayoutEditorHandler;
use OCI\Banner\Controller\LayoutSaveHandler;
use OCI\Banner\Controller\LayoutDuplicateHandler;
use OCI\Banner\Controller\LayoutPreviewHandler;
use OCI\Banner\Controller\LayoutDeleteHandler;
use OCI\Modules\ABTest\Controller\ExperimentListHandler;
use OCI\Modules\ABTest\Controller\ExperimentCreateHandler;
use OCI\Modules\ABTest\Controller\ExperimentDetailHandler;
use OCI\Modules\ABTest\Controller\ExperimentUpdateHandler;
use OCI\Modules\ABTest\Controller\ExperimentDeleteHandler;
use OCI\Modules\ABTest\Controller\ExperimentActionHandler;
use OCI\Modules\ABTest\Controller\VariantHandler;
use OCI\Modules\ABTest\Controller\VariantDeleteHandler;
use OCI\Modules\ABTest\Controller\RevenueImpactHandler;
use OCI\Modules\ABTest\Controller\CustomerSignalsHandler;
use OCI\Modules\ABTest\Controller\ImpactExportHandler;
use OCI\Modules\ABTest\Controller\ABTestDashboardHandler;
use OCI\Report\Controller\ReportListHandler;
use OCI\Report\Controller\ReportViewHandler;
use OCI\Report\Controller\ReportGenerateHandler;
use OCI\Report\Controller\ReportScheduleHandler;
use OCI\Report\Controller\ReportDeleteHandler;
use OCI\Report\Controller\ReportSendHandler;
use OCI\Compliance\Controller\ComplianceChecklistHandler;
use OCI\Compliance\Controller\ComplianceChecklistToggleHandler;
use OCI\Admin\Controller\AuditLogHandler;

return static function (RouteCollector $r): void {
    // ── Health ────────────────────────────────────────────
    $r->get('/health', HealthCheckHandler::class);

    // ── Auth ─────────────────────────────────────────────
    $r->get('/login', ['handler' => LoginPageHandler::class, 'middleware' => 'guest']);
    $r->post('/login', ['handler' => LoginHandler::class, 'middleware' => 'guest']);
    $r->get('/logout', ['handler' => LogoutHandler::class, 'middleware' => 'web']);
    $r->get('/forgot-password', ['handler' => ForgotPasswordPageHandler::class, 'middleware' => 'guest']);
    $r->post('/forgot-password', ['handler' => ForgotPasswordHandler::class, 'middleware' => 'guest']);
    $r->get('/reset-password', ['handler' => ResetPasswordPageHandler::class, 'middleware' => 'guest']);
    $r->post('/reset-password', ['handler' => ResetPasswordHandler::class, 'middleware' => 'guest']);
    $r->get('/auth/google', ['handler' => GoogleLoginHandler::class, 'middleware' => 'guest']);
    $r->get('/auth/google/callback', ['handler' => GoogleCallbackHandler::class, 'middleware' => 'guest']);

    // ── Account ─────────────────────────────────────────
    $r->get('/account', ['handler' => AccountProfileHandler::class, 'middleware' => 'web']);
    $r->post('/account', ['handler' => AccountProfileHandler::class, 'middleware' => 'web']);
    $r->get('/account/setup', ['handler' => AccountSetupHandler::class, 'middleware' => 'web']);
    $r->post('/account/setup', ['handler' => AccountSetupHandler::class, 'middleware' => 'web']);

    // ── Public pages ─────────────────────────────────────
    $r->get('/', ['handler' => DashboardHandler::class, 'middleware' => 'web']);

    // ── Site management ──────────────────────────────────
    $r->get('/sites', ['handler' => SiteListHandler::class, 'middleware' => 'web']);
    $r->get('/sites/create', ['handler' => CreateSitePageHandler::class, 'middleware' => 'web']);
    $r->post('/sites/create', ['handler' => CreateSiteHandler::class, 'middleware' => 'web']);

    // ── Language management ────────────────────────────────
    $r->get('/languages', ['handler' => LanguageListHandler::class, 'middleware' => 'web']);

    // ── Banner management ─────────────────────────────────
    $r->get('/banners', ['handler' => BannerListHandler::class, 'middleware' => 'web']);
    $r->get('/banners/content', ['handler' => BannerContentHandler::class, 'middleware' => 'web']);

    // ── Cookie management ─────────────────────────────────
    $r->get('/cookies', ['handler' => CookieListHandler::class, 'middleware' => 'web']);
    $r->get('/categories', ['handler' => CategoryListHandler::class, 'middleware' => 'web']);

    // ── Consent logs ──────────────────────────────────────
    $r->get('/consents', ['handler' => ConsentListHandler::class, 'middleware' => 'web']);
    $r->get('/consents/{id:\d+}', ['handler' => ConsentDetailHandler::class, 'middleware' => 'web']);

    // ── Layouts ──────────────────────────────────────────
    $r->get('/layouts', ['handler' => LayoutListHandler::class, 'middleware' => 'web']);
    $r->get('/layouts/{id:\d+}/edit', ['handler' => LayoutEditorHandler::class, 'middleware' => 'web']);

    // ── A/B Tests ──────────────────────────────────────
    $r->get('/ab-tests', ['handler' => ExperimentListHandler::class, 'middleware' => 'web']);
    $r->get('/ab-tests/{id:\d+}', ['handler' => ExperimentDetailHandler::class, 'middleware' => 'web']);

    // ── Policies ──────────────────────────────────────────
    $r->get('/policies', ['handler' => PolicyListHandler::class, 'middleware' => 'web']);
    $r->get('/policies/cookie', ['handler' => CookiePolicyWizardHandler::class, 'middleware' => 'web']);
    $r->get('/policies/privacy', ['handler' => PrivacyPolicyWizardHandler::class, 'middleware' => 'web']);

    // ── Scan management ──────────────────────────────────
    $r->get('/scans', ['handler' => ScanListHandler::class, 'middleware' => 'web']);
    $r->get('/scans/{id:\d+}', ['handler' => ScanDetailHandler::class, 'middleware' => 'web']);

    // ── Reports ─────────────────────────────────────────
    $r->get('/reports', ['handler' => ReportListHandler::class, 'middleware' => 'web']);
    $r->get('/reports/{id:\d+}', ['handler' => ReportViewHandler::class, 'middleware' => 'web']);

    // ── Compliance Checklist ────────────────────────────
    $r->get('/compliance', ['handler' => ComplianceChecklistHandler::class, 'middleware' => 'web']);

    // ── Dashboard AJAX ───────────────────────────────────
    $r->addGroup('/app/dashboard', static function (RouteCollector $r): void {
        $r->post('/consent-report', ['handler' => ConsentReportHandler::class, 'middleware' => 'web']);
        $r->post('/pageview-report', ['handler' => PageviewReportHandler::class, 'middleware' => 'web']);
        $r->post('/compliance-check', ['handler' => ComplianceCheckHandler::class, 'middleware' => 'web']);
        $r->post('/site-status', ['handler' => SiteStatusHandler::class, 'middleware' => 'web']);
        $r->post('/commission-report', ['handler' => CommissionReportHandler::class, 'middleware' => 'web']);
        $r->post('/customer-report', ['handler' => CustomerReportHandler::class, 'middleware' => 'web']);
        $r->post('/script-check', ['handler' => ScriptCheckHandler::class, 'middleware' => 'web']);
        $r->post('/apply-template', ['handler' => ApplyTemplateHandler::class, 'middleware' => 'web']);
        $r->get('/recommendations', ['handler' => RecommendationsHandler::class, 'middleware' => 'web']);
        $r->get('/ab-summary', ['handler' => ABTestDashboardHandler::class, 'middleware' => 'web']);
    });

    // ── Public API (v1) ─────────────────────────────────
    $r->get('/api/v1/geo_ip', GeoIpHandler::class);
    $r->post('/api/v1/log', PageviewLogHandler::class);

    $r->post('/api/v1/consent', ConsentLogHandler::class);
    $r->post('/api/v1/scan-webhook', ScanWebhookHandler::class);
    $r->post('/api/v1/scan_data', BeaconIngestHandler::class);

    $r->addGroup('/api/v1/consent', static function (RouteCollector $r): void {
        // $r->get('/config/{siteKey}', ['handler' => Consent\Controller\ConfigHandler::class, 'middleware' => 'public_api']);
        // $r->post('/save', ['handler' => Consent\Controller\SaveHandler::class, 'middleware' => 'public_api']);
    });

    // ── Scan AJAX API ──────────────────────────────────────
    $r->addGroup('/app/scans', static function (RouteCollector $r): void {
        $r->post('/start', ['handler' => ScanStartHandler::class, 'middleware' => 'web']);
        $r->post('/schedule', ['handler' => ScanScheduleHandler::class, 'middleware' => 'web']);
        $r->post('/{id:\d+}/cancel', ['handler' => ScanCancelHandler::class, 'middleware' => 'web']);
    });

    // ── Site switch (POST) ─────────────────────────────────
    $r->post('/app/switch-site', ['handler' => SiteSwitchHandler::class, 'middleware' => 'web']);

    // ── Site AJAX API ──────────────────────────────────────
    $r->addGroup('/app/sites', static function (RouteCollector $r): void {
        $r->put('/{id:\d+}', ['handler' => SiteUpdateHandler::class, 'middleware' => 'web']);
        $r->delete('/{id:\d+}', ['handler' => SiteDeleteHandler::class, 'middleware' => 'web']);
        $r->post('/{id:\d+}/restore', ['handler' => SiteRestoreHandler::class, 'middleware' => 'web']);
        $r->post('/{id:\d+}/destroy', ['handler' => SiteDestroyHandler::class, 'middleware' => 'web']);
        $r->post('/{id:\d+}/generate-script', ['handler' => GenerateScriptHandler::class, 'middleware' => 'web']);
    });

    // ── GTM Pages ────────────────────────────────────────────
    $r->addGroup('/gtm', static function (RouteCollector $r): void {
        $r->get('/wizard', ['handler' => GtmWizardHandler::class, 'middleware' => 'web']);
        $r->get('/auth', ['handler' => GtmAuthHandler::class, 'middleware' => 'web']);
        $r->get('/callback', ['handler' => GtmCallbackHandler::class, 'middleware' => 'web']);
    });

    // ── GTM AJAX API ──────────────────────────────────────────
    $r->addGroup('/app/gtm', static function (RouteCollector $r): void {
        $r->get('/accounts', ['handler' => GtmAccountsHandler::class, 'middleware' => 'web']);
        $r->get('/workspaces', ['handler' => GtmWorkspacesHandler::class, 'middleware' => 'web']);
        $r->post('/workspaces/create', ['handler' => GtmCreateWorkspaceHandler::class, 'middleware' => 'web']);
        $r->post('/wizard/apply', ['handler' => GtmWizardApplyHandler::class, 'middleware' => 'web']);
    });

    // ── Language AJAX API ───────────────────────────────────
    $r->addGroup('/app/languages', static function (RouteCollector $r): void {
        $r->post('/add', ['handler' => LanguageAddHandler::class, 'middleware' => 'web']);
        $r->post('/remove', ['handler' => LanguageRemoveHandler::class, 'middleware' => 'web']);
        $r->post('/set-default', ['handler' => LanguageDefaultHandler::class, 'middleware' => 'web']);
    });

    // ── Cookie AJAX API ─────────────────────────────────────
    $r->addGroup('/app/cookies', static function (RouteCollector $r): void {
        $r->post('', ['handler' => CookieCreateHandler::class, 'middleware' => 'web']);
        $r->put('/{id:\d+}', ['handler' => CookieUpdateHandler::class, 'middleware' => 'web']);
        $r->delete('/{id:\d+}', ['handler' => CookieDeleteHandler::class, 'middleware' => 'web']);
        $r->post('/import', ['handler' => CookieImportHandler::class, 'middleware' => 'web']);
        $r->post('/reset-observations', ['handler' => CookieResetObservationsHandler::class, 'middleware' => 'web']);
    });

    // ── Category AJAX API ────────────────────────────────────
    $r->addGroup('/app/categories', static function (RouteCollector $r): void {
        $r->post('', ['handler' => CategoryAddHandler::class, 'middleware' => 'web']);
        $r->put('/{id:\d+}', ['handler' => CategoryUpdateHandler::class, 'middleware' => 'web']);
        $r->delete('/{id:\d+}', ['handler' => CategoryDeleteHandler::class, 'middleware' => 'web']);
    });

    // ── Layout AJAX API ─────────────────────────────────────
    $r->addGroup('/app/layouts', static function (RouteCollector $r): void {
        $r->post('/duplicate', ['handler' => LayoutDuplicateHandler::class, 'middleware' => 'web']);
        $r->post('/preview', ['handler' => LayoutPreviewHandler::class, 'middleware' => 'web']);
        $r->put('/{id:\d+}', ['handler' => LayoutSaveHandler::class, 'middleware' => 'web']);
        $r->delete('/{id:\d+}', ['handler' => LayoutDeleteHandler::class, 'middleware' => 'web']);
    });

    // ── Banner AJAX API ─────────────────────────────────────
    $r->addGroup('/app/banners', static function (RouteCollector $r): void {
        $r->put('/{id:\d+}', ['handler' => BannerUpdateHandler::class, 'middleware' => 'web']);
        $r->post('/purge', ['handler' => BannerPurgeCacheHandler::class, 'middleware' => 'web']);
        $r->post('/content', ['handler' => BannerContentUpdateHandler::class, 'middleware' => 'web']);
        $r->post('/translate', ['handler' => TranslateContentHandler::class, 'middleware' => 'web']);
    });

    // ── Account AJAX API ─────────────────────────────────────
    $r->post('/app/account/delete', ['handler' => AccountDeleteHandler::class, 'middleware' => 'web']);

    // ── Consent AJAX API ─────────────────────────────────────
    $r->addGroup('/app/consents', static function (RouteCollector $r): void {
        $r->post('/analytics', ['handler' => ConsentAnalyticsHandler::class, 'middleware' => 'web']);
    });

    // ── Policy AJAX API ─────────────────────────────────────
    $r->addGroup('/app/policies', static function (RouteCollector $r): void {
        $r->post('/cookie/save', ['handler' => CookiePolicySaveHandler::class, 'middleware' => 'web']);
        $r->post('/privacy/save', ['handler' => PrivacyPolicySaveHandler::class, 'middleware' => 'web']);
        $r->post('/templates', ['handler' => PolicyTemplateSaveHandler::class, 'middleware' => 'web']);
        $r->post('/templates/delete', ['handler' => PolicyTemplateDeleteHandler::class, 'middleware' => 'web']);
        $r->post('/templates/apply', ['handler' => PolicyTemplateApplyHandler::class, 'middleware' => 'web']);
        $r->post('/templates/rename', ['handler' => PolicyTemplateRenameHandler::class, 'middleware' => 'web']);
        $r->post('/clear', ['handler' => PolicyClearHandler::class, 'middleware' => 'web']);
    });

    // ── Report AJAX API ─────────────────────────────────────
    $r->addGroup('/app/reports', static function (RouteCollector $r): void {
        $r->post('/generate', ['handler' => ReportGenerateHandler::class, 'middleware' => 'web']);
        $r->post('/schedule', ['handler' => ReportScheduleHandler::class, 'middleware' => 'web']);
        $r->post('/{id:\d+}/delete', ['handler' => ReportDeleteHandler::class, 'middleware' => 'web']);
        $r->post('/{id:\d+}/send', ['handler' => ReportSendHandler::class, 'middleware' => 'web']);
    });

    // ── A/B Test AJAX API ────────────────────────────────────
    $r->addGroup('/app/ab-tests', static function (RouteCollector $r): void {
        $r->post('', ['handler' => ExperimentCreateHandler::class, 'middleware' => 'web']);
        $r->put('/{id:\d+}', ['handler' => ExperimentUpdateHandler::class, 'middleware' => 'web']);
        $r->delete('/{id:\d+}', ['handler' => ExperimentDeleteHandler::class, 'middleware' => 'web']);
        $r->post('/{id:\d+}/{action:start|pause|complete}', ['handler' => ExperimentActionHandler::class, 'middleware' => 'web']);
        $r->post('/{id:\d+}/variants', ['handler' => VariantHandler::class, 'middleware' => 'web']);
        $r->delete('/{id:\d+}/variants/{variantId:\d+}', ['handler' => VariantDeleteHandler::class, 'middleware' => 'web']);
        $r->get('/{id:\d+}/impact', ['handler' => RevenueImpactHandler::class, 'middleware' => 'web']);
        $r->get('/{id:\d+}/impact/export', ['handler' => ImpactExportHandler::class, 'middleware' => 'web']);
        $r->get('/signals', ['handler' => CustomerSignalsHandler::class, 'middleware' => 'web']);
        $r->post('/signals', ['handler' => CustomerSignalsHandler::class, 'middleware' => 'web']);
    });

    // ── Compliance Checklist AJAX API ─────────────────────
    $r->addGroup('/app/compliance', static function (RouteCollector $r): void {
        $r->post('/toggle', ['handler' => ComplianceChecklistToggleHandler::class, 'middleware' => 'web']);
    });

    // ── Admin ─────────────────────────────────────────────
    $r->get('/admin/audit-log', ['handler' => AuditLogHandler::class, 'middleware' => 'admin']);

    // Webhook and billing routes are provided by the Billing module (src/Modules/Billing/)
    // Admin routes are provided by the Admin module (src/Modules/Admin/)
};
