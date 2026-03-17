<?php

/**
 * OCI Dependency Injection Definitions
 *
 * Uses PHP-DI 7 definition format.
 * The container auto-wires most classes; only define here what needs
 * explicit configuration (interfaces → implementations, scalar config, factories).
 *
 * @see https://php-di.org/doc/php-definitions.html
 */

declare(strict_types=1);

use Doctrine\DBAL\Configuration as DbalConfig;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Monolog\Logger;
use Predis\Client as RedisClient;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCI\Module\ModuleRegistry;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;

use function DI\autowire;
use function DI\get;
use function DI\value;

return [

    // ── Configuration values ────────────────────────────────
    'config.base_url' => $_ENV['APP_URL'] ?? 'http://localhost:8100',
    'config.monetization_model' => $_ENV['MONETIZATION_MODEL'] ?? 'saas',

    // ── Database (Doctrine DBAL) ────────────────────────────
    Connection::class => static function (ContainerInterface $c): Connection {
        $dsnParser = new \Doctrine\DBAL\Tools\DsnParser([
            'mysql' => 'pdo_mysql',
            'mariadb' => 'pdo_mysql',
        ]);

        $url = $_ENV['DATABASE_URL'] ?? 'mysql://oci:oci@mariadb:3306/oci?charset=utf8mb4';
        $params = $dsnParser->parse($url);

        $config = new DbalConfig();

        return DriverManager::getConnection($params, $config);
    },

    // ── Redis ───────────────────────────────────────────────
    RedisClient::class => static function (): RedisClient {
        return new RedisClient($_ENV['REDIS_URL'] ?? 'redis://redis:6379');
    },

    // ── Twig ────────────────────────────────────────────────
    TwigEnvironment::class => static function (ContainerInterface $c): TwigEnvironment {
        $basePath = $c->get('config.base_path');
        $debug = $c->get('config.debug');

        $loader = new FilesystemLoader($basePath . '/templates');

        // Register module template namespaces (@ModuleName/template.html.twig)
        $moduleRegistry = $c->get(ModuleRegistry::class);
        foreach ($moduleRegistry->all() as $module) {
            if ($module->templatesPath !== null) {
                $loader->addPath($module->templatesPath, $module->name);
            }
        }

        $twig = new TwigEnvironment($loader, [
            'cache' => $debug ? false : $basePath . '/var/cache/twig',
            'debug' => $debug,
            'strict_variables' => false,
            'auto_reload' => $debug,
        ]);

        // Global variables available in all templates
        $twig->addGlobal('app_env', $c->get('config.environment'));
        $twig->addGlobal('app_debug', $debug);
        $twig->addGlobal('base_url', $c->get('config.base_url'));
        $twig->addGlobal('monetization_model', $c->get('config.monetization_model'));
        $twig->addGlobal('modules', $moduleRegistry);
        $twig->addGlobal('edition', $c->get(\OCI\Shared\Service\EditionService::class));
        $twig->addGlobal('openrouter_enabled', trim($_ENV['OPENROUTER_API_KEY'] ?? '') !== '');
        // CMP/TCF globals (cmp_id, cmp_name, cmp_valid, tcf_enabled) are set
        // by SessionMiddleware after server-side validation against the IAB registry.

        if ($debug) {
            $twig->addExtension(new \Twig\Extension\DebugExtension());
        }

        return $twig;
    },

    // ── Logger ──────────────────────────────────────────────
    // LoggerInterface is set by Application::boot() — this is a fallback
    LoggerInterface::class => static function (): LoggerInterface {
        return new Logger('oci');
    },

    // ── Repository bindings ─────────────────────────────────
    OCI\Identity\Repository\UserRepositoryInterface::class => autowire(OCI\Identity\Repository\UserRepository::class),
    OCI\Site\Repository\SiteRepositoryInterface::class => autowire(OCI\Site\Repository\SiteRepository::class),
    OCI\Site\Repository\PageviewRepositoryInterface::class => autowire(OCI\Site\Repository\PageviewRepository::class),
    OCI\Site\Repository\LanguageRepositoryInterface::class => autowire(OCI\Site\Repository\LanguageRepository::class),
    OCI\Consent\Repository\ConsentRepositoryInterface::class => autowire(OCI\Consent\Repository\ConsentRepository::class),
    OCI\Policy\Repository\PolicyRepositoryInterface::class => autowire(OCI\Policy\Repository\PolicyRepository::class),
    OCI\Scanning\Repository\ScanRepositoryInterface::class => autowire(OCI\Scanning\Repository\ScanRepository::class),
    OCI\Agency\Repository\AgencyRepositoryInterface::class => autowire(OCI\Agency\Repository\AgencyRepository::class),
    OCI\Shared\Repository\PlanRepositoryInterface::class => class_exists(OCI\Monetization\Repository\PlanRepository::class)
        ? autowire(OCI\Monetization\Repository\PlanRepository::class)
        : autowire(OCI\Shared\Repository\NullPlanRepository::class),
    OCI\Banner\Repository\BannerRepositoryInterface::class => autowire(OCI\Banner\Repository\BannerRepository::class),
    OCI\Banner\Service\LayoutService::class => static function (ContainerInterface $c): OCI\Banner\Service\LayoutService {
        return new OCI\Banner\Service\LayoutService(
            $c->get(Doctrine\DBAL\Connection::class),
            $c->get('config.base_path') . '/resources',
        );
    },
    OCI\Cookie\Repository\CookieCategoryRepositoryInterface::class => autowire(OCI\Cookie\Repository\CookieCategoryRepository::class),
    OCI\Cookie\Repository\CookieRepositoryInterface::class => autowire(OCI\Cookie\Repository\CookieRepository::class),
    OCI\Report\Repository\ReportRepositoryInterface::class => autowire(OCI\Report\Repository\ReportRepository::class),
    OCI\Admin\Repository\AuditLogRepositoryInterface::class => autowire(OCI\Admin\Repository\AuditLogRepository::class),
    OCI\Compliance\Repository\ChecklistRepositoryInterface::class => autowire(OCI\Compliance\Repository\ChecklistRepository::class),
    OCI\Compliance\Service\ChecklistService::class => static function (ContainerInterface $c): OCI\Compliance\Service\ChecklistService {
        return new OCI\Compliance\Service\ChecklistService(
            $c->get(OCI\Compliance\Repository\ChecklistRepositoryInterface::class),
            $c->get('config.base_path') . '/config/conzent-compliance-checklists.json',
        );
    },
    OCI\Notification\Repository\NotificationReadRepositoryInterface::class => autowire(OCI\Notification\Repository\NotificationReadRepository::class),
    OCI\Notification\Service\NotificationService::class => static function (ContainerInterface $c): OCI\Notification\Service\NotificationService {
        return new OCI\Notification\Service\NotificationService(
            $c->get(OCI\Notification\Repository\NotificationReadRepositoryInterface::class),
            $c->get('config.base_path') . '/notifications',
        );
    },

    // ── Monetization (null when module not installed) ──────
    OCI\Monetization\Service\PricingService::class => class_exists(OCI\Monetization\Service\PricingService::class)
        ? autowire(OCI\Monetization\Service\PricingService::class)
        : value(null),
    OCI\Monetization\Service\SubscriptionService::class => class_exists(OCI\Monetization\Service\SubscriptionService::class)
        ? autowire(OCI\Monetization\Service\SubscriptionService::class)
        : value(null),

    // ── Monetization (swap via env var) ─────────────────────
    // OCI\Monetization\Service\MonetizationServiceInterface::class => static function (ContainerInterface $c) {
    //     $model = $c->get('config.monetization_model');
    //     return match ($model) {
    //         'oci'    => $c->get(OCI\Monetization\Service\OciMonetizationService::class),
    //         'saas'   => $c->get(OCI\Monetization\Service\SaasMonetizationService::class),
    //         'hybrid' => $c->get(OCI\Monetization\Service\HybridMonetizationService::class),
    //         default  => throw new \InvalidArgumentException("Unknown monetization model: {$model}"),
    //     };
    // },

];
