<?php

/**
 * OpenRegister AppHost — Bootstrap
 *
 * One-call registration for an AppHost-adopting leaf app. From the leaf's
 * `Application::register(IRegistrationContext $context)`, a single
 * `\OCA\OpenRegister\AppHost\Bootstrap::register($context, self::APP_ID)`
 * wires every standard plumbing class: the generic controllers, the settings
 * + action-auth services, the install repair steps, the admin settings panel,
 * the deep-link listener, optional dashboard widgets and MCP provider, and the
 * observability aliases (only when the observability engine classes exist).
 *
 * ## Lazy by construction — a disabled OpenRegister never fatals NC bootstrap
 *
 * Every registration here is `IRegistrationContext::registerService($name,
 * Closure)`. The closure body — which references `OCA\OpenRegister\AppHost\…`
 * classes — is NOT executed at bootstrap; it runs only when the leaf app's DI
 * container is asked to resolve that exact service name, i.e. when a route is
 * dispatched. Therefore:
 *
 *   - With OpenRegister disabled/absent, `Application::register()` (and this
 *     method) complete without loading a single OR class, so Nextcloud boots.
 *   - The first request to an aliased route triggers the closure, which fails
 *     to autoload the generic class and surfaces as a 5xx JSON error — the
 *     correct DEGRADED behaviour, and health reports `orAvailable: failed`.
 *
 * No `OCA\OpenRegister\…` symbol is referenced outside a closure in this file
 * (no `use` of OR classes, no `::class` at top level) — that invariant is what
 * keeps bootstrap fatal-free, and it is asserted by the unit test.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category AppHost
 * @package  OCA\OpenRegister\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost;

use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/**
 * Declarative one-call bootstrap for AppHost leaf apps.
 *
 * @spec openspec/changes/apphost-boilerplate-controllers/tasks.md#task-3.1
 * @spec openspec/specs/apphost-boilerplate/spec.md — Requirement: One-Call Bootstrap
 */
class Bootstrap
{
    /**
     * Fully-qualified generic class names, kept as plain strings so they are
     * never autoloaded by referencing this map (only when a closure resolves
     * one through the container).
     */
    private const GENERIC_DASHBOARD_CONTROLLER   = 'OCA\\OpenRegister\\AppHost\\Controller\\GenericDashboardController';
    private const GENERIC_PREFERENCES_CONTROLLER = 'OCA\\OpenRegister\\AppHost\\Controller\\GenericPreferencesController';
    private const GENERIC_SETTINGS_CONTROLLER    = 'OCA\\OpenRegister\\AppHost\\Controller\\GenericSettingsController';
    private const GENERIC_HEALTH_CONTROLLER      = 'OCA\\OpenRegister\\AppHost\\Controller\\GenericHealthController';
    private const GENERIC_METRICS_CONTROLLER     = 'OCA\\OpenRegister\\AppHost\\Controller\\GenericMetricsController';
    private const GENERIC_SETTINGS_SERVICE       = 'OCA\\OpenRegister\\AppHost\\Service\\AppHostSettingsService';
    private const GENERIC_ACTION_AUTH_SERVICE    = 'OCA\\OpenRegister\\AppHost\\Service\\GenericActionAuthService';
    private const GENERIC_INITIALIZE_SETTINGS    = 'OCA\\OpenRegister\\AppHost\\Repair\\GenericInitializeSettings';
    private const GENERIC_INITIALIZE_ACTIONS     = 'OCA\\OpenRegister\\AppHost\\Repair\\GenericInitializeActions';
    private const GENERIC_ADMIN_SETTINGS         = 'OCA\\OpenRegister\\AppHost\\Settings\\GenericAdminSettings';
    private const GENERIC_SETTINGS_SECTION       = 'OCA\\OpenRegister\\AppHost\\Settings\\GenericSettingsSection';
    private const GENERIC_DEEPLINK_LISTENER      = 'OCA\\OpenRegister\\AppHost\\Listener\\GenericDeepLinkRegistrationListener';

    private const GENERIC_SETTINGS_PLANE_SERVICE = 'OCA\\OpenRegister\\AppHost\\Service\\GenericSettingsService';
    private const REGISTER_CONFIG_RESOLVER       = 'OCA\\OpenRegister\\AppHost\\Service\\RegisterConfigResolver';

    private const OBSERVABILITY_MANIFEST_LOADER = 'OCA\\OpenRegister\\AppHost\\Observability\\ManifestLoader';
    private const OBSERVABILITY_EXECUTOR        = 'OCA\\OpenRegister\\AppHost\\Observability\\HealthCheckExecutor';
    private const OBSERVABILITY_METRICS_ENGINE  = 'OCA\\OpenRegister\\AppHost\\Observability\\MetricsEngine';

    private const DEEPLINK_EVENT = 'OCA\\OpenRegister\\Event\\DeepLinkRegistrationEvent';

    /**
     * Register all standard AppHost plumbing for a leaf app.
     *
     * @param IRegistrationContext $context The leaf app's registration context.
     * @param string               $appId   The leaf app id (e.g. 'petstore').
     * @param array<string, mixed> $options Optional overrides:
     *                                      - 'namespace' (string): the leaf app's `OCA\X` base namespace
     *                                      (e.g. `OCA\PetStore`). STRONGLY RECOMMENDED — the fleet's
     *                                      namespaces are not derivable from the app id (petstore →
     *                                      PetStore, opencatalogi → OpenCatalogi, decidesk → Decidesk),
     *                                      so pass it explicitly. When omitted, a StudlyCase guess from
     *                                      the app id is used as a last-resort fallback.
     *                                      - 'controllerNamespace' (string): override just the controller namespace.
     *                                      - 'repairNamespace' (string): override just the repair namespace.
     *                                      - 'settingsNamespace' (string): override just the settings namespace.
     *                                      - 'sectionsNamespace' (string): override just the sections namespace.
     *                                      - 'listenerNamespace' (string): override just the listener namespace.
     *                                      - 'serviceNamespace' (string): override just the service namespace.
     *                                      - 'sectionId' (string): admin section id, default `$appId`.
     *                                      - 'sectionName' (string): admin section display name, default StudlyAppId.
     *                                      - 'sectionIcon' (string): icon file, default 'app-dark.svg'.
     *                                      - 'sectionPriority' (int): default 75.
     *                                      - 'adminPriority' (int): default 10.
     *                                      - 'dashboardWidgets' (string[]): widget classes to register.
     *                                      - 'mcpProvider' (string): MCP tool provider class to alias.
     *                                      - 'deepLinks' (bool): register the deep-link listener, default true.
     *                                      - 'observability' (bool): alias health/metrics controllers, default true.
     *
     * @return void
     *
     * @spec openspec/specs/apphost-boilerplate/spec.md — Requirement: One-Call Bootstrap
     */
    public static function register(IRegistrationContext $context, string $appId, array $options=[]): void
    {
        $studly       = self::studly(appId: $appId);
        $base         = rtrim(string: (string) ($options['namespace'] ?? ('OCA\\'.$studly)), characters: '\\');
        $controllerNs = (string) ($options['controllerNamespace'] ?? ($base.'\\Controller'));
        $repairNs     = (string) ($options['repairNamespace'] ?? ($base.'\\Repair'));
        $settingsNs   = (string) ($options['settingsNamespace'] ?? ($base.'\\Settings'));
        $sectionsNs   = (string) ($options['sectionsNamespace'] ?? ($base.'\\Sections'));
        $listenerNs   = (string) ($options['listenerNamespace'] ?? ($base.'\\Listener'));
        $serviceNs    = (string) ($options['serviceNamespace'] ?? ($base.'\\Service'));

        $sectionId         = (string) ($options['sectionId'] ?? $appId);
        $sectionName       = (string) ($options['sectionName'] ?? $studly);
        $sectionIcon       = (string) ($options['sectionIcon'] ?? 'app-dark.svg');
        $sectionPriority   = (int) ($options['sectionPriority'] ?? 75);
        $adminPriority     = (int) ($options['adminPriority'] ?? 10);
        $registerDeepLinks = ($options['deepLinks'] ?? true) !== false;
        $registerObserv    = ($options['observability'] ?? true) !== false;

        self::registerControllers(context: $context, appId: $appId, controllerNs: $controllerNs, observability: $registerObserv);
        self::registerServices(context: $context, appId: $appId, serviceNs: $serviceNs);
        self::registerRepairSteps(context: $context, appId: $appId, repairNs: $repairNs);
        self::registerAdminSettings(
            context: $context,
            appId: $appId,
            settingsNs: $settingsNs,
            sectionsNs: $sectionsNs,
            sectionId: $sectionId,
            sectionName: $sectionName,
            sectionIcon: $sectionIcon,
            sectionPriority: $sectionPriority,
            adminPriority: $adminPriority
        );

        if ($registerDeepLinks === true) {
            self::registerDeepLinkListener(context: $context, appId: $appId, listenerNs: $listenerNs);
        }

        foreach ((array) ($options['dashboardWidgets'] ?? []) as $widgetClass) {
            $context->registerDashboardWidget((string) $widgetClass);
        }

        if (isset($options['mcpProvider']) === true) {
            $context->registerServiceAlias(
                'OCA\\OpenRegister\\Mcp\\IMcpToolProvider::'.$appId,
                (string) $options['mcpProvider']
            );
        }
    }//end register()

    /**
     * Alias the leaf controller class names to the generic controllers, with
     * the leaf appId injected as the controllers' `$appName`.
     *
     * @param IRegistrationContext $context       Leaf registration context.
     * @param string               $appId         Leaf app id.
     * @param string               $controllerNs  Leaf controller namespace.
     * @param bool                 $observability Whether to alias health/metrics.
     *
     * @return void
     */
    private static function registerControllers(IRegistrationContext $context, string $appId, string $controllerNs, bool $observability): void
    {
        $context->registerService(
            $controllerNs.'\\DashboardController',
            static function (ContainerInterface $c) use ($appId) {
                $class = self::GENERIC_DASHBOARD_CONTROLLER;
                return new $class(
                    appName: $appId,
                    request: $c->get('OCP\\IRequest')
                );
            }
        );

        $context->registerService(
            $controllerNs.'\\PreferencesController',
            static function (ContainerInterface $c) use ($appId) {
                $class = self::GENERIC_PREFERENCES_CONTROLLER;
                return new $class(
                    appName: $appId,
                    request: $c->get('OCP\\IRequest'),
                    config: $c->get('OCP\\IConfig'),
                    userSession: $c->get('OCP\\IUserSession')
                );
            }
        );

        $context->registerService(
            $controllerNs.'\\SettingsController',
            static function (ContainerInterface $c) use ($appId) {
                $class = self::GENERIC_SETTINGS_CONTROLLER;
                return new $class(
                    appName: $appId,
                    request: $c->get('OCP\\IRequest'),
                    settingsService: $c->get(self::GENERIC_SETTINGS_SERVICE)
                );
            }
        );

        if ($observability === false) {
            return;
        }

        $context->registerService(
            $controllerNs.'\\HealthController',
            static function (ContainerInterface $c) use ($appId) {
                $class = self::GENERIC_HEALTH_CONTROLLER;
                return new $class(
                    appName: $appId,
                    request: $c->get('OCP\\IRequest'),
                    manifestLoader: $c->get(self::OBSERVABILITY_MANIFEST_LOADER),
                    executor: $c->get(self::OBSERVABILITY_EXECUTOR)
                );
            }
        );

        $context->registerService(
            $controllerNs.'\\MetricsController',
            static function (ContainerInterface $c) use ($appId) {
                $class = self::GENERIC_METRICS_CONTROLLER;
                return new $class(
                    appName: $appId,
                    request: $c->get('OCP\\IRequest'),
                    manifestLoader: $c->get(self::OBSERVABILITY_MANIFEST_LOADER),
                    engine: $c->get(self::OBSERVABILITY_METRICS_ENGINE)
                );
            }
        );
    }//end registerControllers()

    /**
     * Register the app-scoped settings + action-auth services under both the
     * generic class name (so the controllers resolve them) and the leaf's
     * conventional service class names (so leaf code keeps working).
     *
     * @param IRegistrationContext $context   Leaf registration context.
     * @param string               $appId     Leaf app id.
     * @param string               $serviceNs Leaf service namespace.
     *
     * @return void
     */
    private static function registerServices(IRegistrationContext $context, string $appId, string $serviceNs): void
    {
        $settingsFactory = static function (ContainerInterface $c) use ($appId) {
            $class = self::GENERIC_SETTINGS_SERVICE;
            return new $class(
                appId: $appId,
                appConfig: $c->get('OCP\\IAppConfig'),
                appManager: $c->get('OCP\\App\\IAppManager'),
                container: $c,
                groupManager: $c->get('OCP\\IGroupManager'),
                userSession: $c->get('OCP\\IUserSession'),
                logger: $c->get('Psr\\Log\\LoggerInterface')
            );
        };
        $context->registerService(self::GENERIC_SETTINGS_SERVICE, $settingsFactory);
        $context->registerService($serviceNs.'\\SettingsService', $settingsFactory);

        $actionAuthFactory = static function (ContainerInterface $c) use ($appId) {
            $class = self::GENERIC_ACTION_AUTH_SERVICE;
            return new $class(
                appId: $appId,
                appConfig: $c->get('OCP\\IAppConfig'),
                groupManager: $c->get('OCP\\IGroupManager')
            );
        };
        $context->registerService(self::GENERIC_ACTION_AUTH_SERVICE, $actionAuthFactory);
        $context->registerService($serviceNs.'\\ActionAuthService', $actionAuthFactory);

        // ADR-066 settings-plane consumables. Appended AFTER the pre-existing
        // registrations on purpose — the AppHost bootstrap load-order incident
        // (listeners killed by a reorder) makes registration order here part of
        // the contract: never reorder, only append. Both are lazy closures, so
        // a disabled OpenRegister still never fatals NC bootstrap.
        $settingsPlaneFactory = static function (ContainerInterface $c) use ($appId) {
            $class = self::GENERIC_SETTINGS_PLANE_SERVICE;
            return new $class(
                appId: $appId,
                appConfig: $c->get('OCP\\IAppConfig'),
                appManager: $c->get('OCP\\App\\IAppManager'),
                container: $c,
                groupManager: $c->get('OCP\\IGroupManager'),
                userSession: $c->get('OCP\\IUserSession'),
                logger: $c->get('Psr\\Log\\LoggerInterface')
            );
        };
        $context->registerService(self::GENERIC_SETTINGS_PLANE_SERVICE, $settingsPlaneFactory);

        $registerConfigResolverFactory = static function (ContainerInterface $c) use ($appId) {
            $class = self::REGISTER_CONFIG_RESOLVER;
            return new $class(
                appId: $appId,
                appManager: $c->get('OCP\\App\\IAppManager'),
                container: $c,
                logger: $c->get('Psr\\Log\\LoggerInterface')
            );
        };
        $context->registerService(self::REGISTER_CONFIG_RESOLVER, $registerConfigResolverFactory);
        $context->registerService($serviceNs.'\\RegisterConfigResolver', $registerConfigResolverFactory);
    }//end registerServices()

    /**
     * Bind the leaf repair-step class names (referenced by info.xml) to the
     * generic repair steps with the appId + app-scoped services injected.
     *
     * @param IRegistrationContext $context  Leaf registration context.
     * @param string               $appId    Leaf app id.
     * @param string               $repairNs Leaf repair namespace.
     *
     * @return void
     */
    private static function registerRepairSteps(IRegistrationContext $context, string $appId, string $repairNs): void
    {
        $context->registerService(
            $repairNs.'\\InitializeSettings',
            static function (ContainerInterface $c) use ($appId) {
                $class = self::GENERIC_INITIALIZE_SETTINGS;
                return new $class(
                    appId: $appId,
                    settingsService: $c->get(self::GENERIC_SETTINGS_SERVICE),
                    logger: $c->get('Psr\\Log\\LoggerInterface'),
                    appManager: $c->get('OCP\\App\\IAppManager'),
                    tokenService: $c->get('OCA\\OpenRegister\\Service\\Credential\\CredentialAppTokenService'),
                    applicationRegistrar: $c->get('OCA\\OpenRegister\\Service\\Credential\\DoriathApplicationRegistrar')
                );
            }
        );

        $context->registerService(
            $repairNs.'\\InitializeActions',
            static function (ContainerInterface $c) use ($appId) {
                $class = self::GENERIC_INITIALIZE_ACTIONS;
                return new $class(
                    appId: $appId,
                    actionAuth: $c->get(self::GENERIC_ACTION_AUTH_SERVICE),
                    appManager: $c->get('OCP\\App\\IAppManager'),
                    logger: $c->get('Psr\\Log\\LoggerInterface')
                );
            }
        );
    }//end registerRepairSteps()

    /**
     * Bind the leaf admin-settings + section class names (referenced by
     * info.xml) to the generic admin settings/section with the leaf metadata.
     *
     * @param IRegistrationContext $context         Leaf registration context.
     * @param string               $appId           Leaf app id.
     * @param string               $settingsNs      Leaf settings namespace.
     * @param string               $sectionsNs      Leaf sections namespace.
     * @param string               $sectionId       Section id.
     * @param string               $sectionName     Section display name.
     * @param string               $sectionIcon     Section icon file.
     * @param int                  $sectionPriority Section priority.
     * @param int                  $adminPriority   Admin form priority.
     *
     * @return void
     */
    private static function registerAdminSettings(
        IRegistrationContext $context,
        string $appId,
        string $settingsNs,
        string $sectionsNs,
        string $sectionId,
        string $sectionName,
        string $sectionIcon,
        int $sectionPriority,
        int $adminPriority
    ): void {
        $context->registerService(
            $settingsNs.'\\AdminSettings',
            static function (ContainerInterface $c) use ($appId, $sectionId, $adminPriority) {
                $class = self::GENERIC_ADMIN_SETTINGS;
                return new $class(
                    appId: $appId,
                    sectionId: $sectionId,
                    priority: $adminPriority,
                    appManager: $c->get('OCP\\App\\IAppManager'),
                    initialState: $c->get('OCP\\AppFramework\\Services\\IInitialState'),
                    appConfig: $c->get('OCP\\IAppConfig')
                );
            }
        );

        $context->registerService(
            $sectionsNs.'\\SettingsSection',
            static function (ContainerInterface $c) use ($appId, $sectionId, $sectionName, $sectionIcon, $sectionPriority) {
                $class = self::GENERIC_SETTINGS_SECTION;
                return new $class(
                    sectionId: $sectionId,
                    name: $sectionName,
                    appId: $appId,
                    iconFile: $sectionIcon,
                    priority: $sectionPriority,
                    urlGenerator: $c->get('OCP\\IURLGenerator')
                );
            }
        );
    }//end registerAdminSettings()

    /**
     * Register the generic deep-link listener (manifest-driven) and bind the
     * leaf listener class name to it. The event listener is registered against
     * OpenRegister's DeepLinkRegistrationEvent by its string name, so a
     * disabled OR simply never dispatches the event — no fatal.
     *
     * @param IRegistrationContext $context    Leaf registration context.
     * @param string               $appId      Leaf app id.
     * @param string               $listenerNs Leaf listener namespace.
     *
     * @return void
     */
    private static function registerDeepLinkListener(IRegistrationContext $context, string $appId, string $listenerNs): void
    {
        $factory = static function (ContainerInterface $c) use ($appId) {
            $class = self::GENERIC_DEEPLINK_LISTENER;
            return new $class(
                appId: $appId,
                appManager: $c->get('OCP\\App\\IAppManager'),
                logger: $c->get('Psr\\Log\\LoggerInterface')
            );
        };
        $context->registerService(self::GENERIC_DEEPLINK_LISTENER, $factory);
        $context->registerService($listenerNs.'\\DeepLinkRegistrationListener', $factory);

        $context->registerEventListener(self::DEEPLINK_EVENT, $listenerNs.'\\DeepLinkRegistrationListener');
    }//end registerDeepLinkListener()

    /**
     * Convert a dash/underscore app id into a StudlyCase namespace segment
     * (e.g. `pet_store` / `pet-store` → `PetStore`).
     *
     * @param string $appId The app id.
     *
     * @return string StudlyCase form.
     */
    private static function studly(string $appId): string
    {
        $parts = preg_split(pattern: '/[_\-]+/', subject: $appId);
        if ($parts === false || count($parts) === 0) {
            $parts = [$appId];
        }

        $studly = '';
        foreach ($parts as $part) {
            $studly .= ucfirst($part);
        }//end foreach

        return $studly;
    }//end studly()
}//end class
