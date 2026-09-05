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
 * ## Lazy BODIES — but resolving THIS class is not lazy
 *
 * Every registration here is `IRegistrationContext::registerService($name,
 * Closure)`. The closure BODY — which references `OCA\OpenRegister\AppHost\…`
 * classes — is NOT executed at bootstrap; it runs only when the leaf app's DI
 * container is asked to resolve that exact service name, i.e. when a route is
 * dispatched. So once `register()` has been entered, an absent OpenRegister
 * degrades rather than fatals: the first request to an aliased route triggers
 * the closure, fails to autoload the generic class, and surfaces as a 5xx JSON
 * error, with health reporting `orAvailable: failed`.
 *
 * That laziness does NOT extend to reaching this method in the first place, and
 * an earlier version of this docblock claimed it did. To CALL
 * `Bootstrap::register()` the leaf must resolve the symbol
 * `OCA\OpenRegister\AppHost\Bootstrap` itself, and that is an ordinary
 * autoload. If `OCA\OpenRegister\` is not on the autoloader at that moment, the
 * call throws before a single closure has been created.
 *
 * ## The load-order trap, and the prelude that closes it
 *
 * "Not on the autoloader at that moment" is the NORMAL case for many leaves, not
 * an edge case. `OC_App::getEnabledApps()` does `sort($apps)`, and
 * `Coordinator::registerApps()` walks that sorted list calling
 * `OC_App::registerAutoloading($appId, $path)` and then `$application->register()`
 * for ONE APP AT A TIME. So every app's `register()` runs BEFORE the PSR-4 prefix
 * of every alphabetically-LATER app exists. Any leaf whose app id sorts before
 * `openregister` — docudesk, doriath, larpingapp, launchpad, mydash, nldesign,
 * opencatalogi, openconnector, openbuild, … — reaches `register()` while
 * `OCA\OpenRegister\` is NOT autoloadable, on a perfectly healthy instance with
 * OpenRegister enabled.
 *
 * Both outcomes are quiet. GUARDED behind `class_exists()`, the probe answers
 * FALSE and the plumbing is silently skipped — and classes that exist ONLY as
 * aliases registered here (a leaf's `Controller\HealthController` aliasing
 * `AppHost\Controller\GenericHealthController`) then fail to resolve, so those
 * endpoints return 500, not 404. UNGUARDED, the `\Error` aborts the leaf's
 * ENTIRE `register()`; `Coordinator` logs an `emergency` and continues, so the
 * app stays enabled while every listener below the call silently never
 * registered. Measured on doriath, whose audit listener recorded ZERO dispatched
 * events, and independently on openconnector.
 *
 * ADOPTING APPHOST THEREFORE REQUIRES THIS PRELUDE, first thing in the leaf's
 * `register()`, before any AppHost reference including a `class_exists()` probe:
 *
 *     try {
 *         $orPath = \OCP\Server::get(\OCP\App\IAppManager::class)
 *             ->getAppPath('openregister');
 *         \OC_App::registerAutoloading('openregister', $orPath);
 *     } catch (\Throwable) {
 *         // OpenRegister absent/disabled — fall through to the degraded path.
 *     }
 *
 * `OC_App::registerAutoloading()` touches only the autoloader and is idempotent
 * (it early-returns on an `$alreadyRegistered` key). Do NOT substitute
 * `IAppManager::loadApp('openregister')`: it sets `loadedApps[..]=true` and calls
 * `Coordinator::bootApp()`, booting OpenRegister before its own `register()` has
 * run. Do NOT substitute `include_once __DIR__.'/../../../openregister/vendor/
 * autoload.php'` either — it assumes both apps share one apps directory and
 * silently does nothing on a multi-`apps_paths` install.
 *
 * Note that any single app doing this registers the prefix PROCESS-WIDE, which
 * masks the defect for every app registering after it. That is precisely why
 * this failed silently for so long: on a dev instance with one such app present
 * everything resolves, and in CI with a minimal app set it does not. Enforced by
 * hydra gate-64 (apphost-autoload-prelude).
 *
 * No `OCA\OpenRegister\…` symbol is referenced outside a closure in this file
 * (no `use` of OR classes, no `::class` at top level) — that invariant is what
 * keeps the CLOSURES lazy, and it is asserted by the unit test. It says nothing
 * about resolving `Bootstrap` itself, which is the leaf's responsibility above.
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
class Bootstrap {
	/**
	 * Fully-qualified generic class names, kept as plain strings so they are
	 * never autoloaded by referencing this map (only when a closure resolves
	 * one through the container).
	 */
	private const GENERIC_DASHBOARD_CONTROLLER = 'OCA\\OpenRegister\\AppHost\\Controller\\GenericDashboardController';
	private const GENERIC_PREFERENCES_CONTROLLER = 'OCA\\OpenRegister\\AppHost\\Controller\\GenericPreferencesController';
	private const GENERIC_SETTINGS_CONTROLLER = 'OCA\\OpenRegister\\AppHost\\Controller\\GenericSettingsController';
	private const GENERIC_HEALTH_CONTROLLER = 'OCA\\OpenRegister\\AppHost\\Controller\\GenericHealthController';
	private const GENERIC_METRICS_CONTROLLER = 'OCA\\OpenRegister\\AppHost\\Controller\\GenericMetricsController';
	private const GENERIC_STORE_CONTROLLER = 'OCA\\OpenRegister\\AppHost\\Controller\\GenericStoreController';
	private const GENERIC_STORE_SERVICE = 'OCA\\OpenRegister\\AppHost\\Service\\GenericStoreService';
	private const GENERIC_STORE_INSTALLER = 'OCA\\OpenRegister\\AppHost\\Store\\GenericStoreInstaller';

	/**
	 * The catalogue serving a store that exchanges configuration.
	 */
	private const FEDERATED_STORE_CATALOG = 'OCA\\OpenRegister\\AppHost\\Store\\FederatedStoreCatalog';

	/**
	 * The GitHub discovery source, for stores declaring `source: github`.
	 */
	private const GITHUB_STORE_SOURCE = 'OCA\\OpenRegister\\AppHost\\Store\\Source\\GitHubStoreSource';

	/**
	 * Resolves an `installAuth: action:<name>` posture against the leaf app.
	 */
	private const STORE_ACTION_AUTHORIZER = 'OCA\\OpenRegister\\AppHost\\Store\\StoreActionAuthorizer';

	private const GENERIC_SETTINGS_SERVICE = 'OCA\\OpenRegister\\AppHost\\Service\\AppHostSettingsService';
	private const GENERIC_ACTION_AUTH_SERVICE = 'OCA\\OpenRegister\\AppHost\\Service\\GenericActionAuthService';
	private const GENERIC_INITIALIZE_SETTINGS = 'OCA\\OpenRegister\\AppHost\\Repair\\GenericInitializeSettings';
	private const GENERIC_INITIALIZE_ACTIONS = 'OCA\\OpenRegister\\AppHost\\Repair\\GenericInitializeActions';
	private const GENERIC_ADMIN_SETTINGS = 'OCA\\OpenRegister\\AppHost\\Settings\\GenericAdminSettings';
	private const GENERIC_SETTINGS_SECTION = 'OCA\\OpenRegister\\AppHost\\Settings\\GenericSettingsSection';
	private const GENERIC_DEEPLINK_LISTENER = 'OCA\\OpenRegister\\AppHost\\Listener\\GenericDeepLinkRegistrationListener';

	private const GENERIC_SETTINGS_PLANE_SERVICE = 'OCA\\OpenRegister\\AppHost\\Service\\GenericSettingsService';
	private const REGISTER_CONFIG_RESOLVER = 'OCA\\OpenRegister\\AppHost\\Service\\RegisterConfigResolver';

	private const OBSERVABILITY_MANIFEST_LOADER = 'OCA\\OpenRegister\\AppHost\\Observability\\ManifestLoader';
	private const OBSERVABILITY_EXECUTOR = 'OCA\\OpenRegister\\AppHost\\Observability\\HealthCheckExecutor';
	private const OBSERVABILITY_METRICS_ENGINE = 'OCA\\OpenRegister\\AppHost\\Observability\\MetricsEngine';

	private const DEEPLINK_EVENT = 'OCA\\OpenRegister\\Event\\DeepLinkRegistrationEvent';

	/**
	 * Register all standard AppHost plumbing for a leaf app.
	 *
	 * @param IRegistrationContext $context The leaf app's registration context.
	 * @param string $appId The leaf app id (e.g. 'petstore').
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
	public static function register(IRegistrationContext $context, string $appId, array $options = []): void {
		$studly = self::studly(appId: $appId);
		$base = rtrim(string: (string)($options['namespace'] ?? ('OCA\\' . $studly)), characters: '\\');
		$controllerNs = (string)($options['controllerNamespace'] ?? ($base . '\\Controller'));
		$repairNs = (string)($options['repairNamespace'] ?? ($base . '\\Repair'));
		$settingsNs = (string)($options['settingsNamespace'] ?? ($base . '\\Settings'));
		$sectionsNs = (string)($options['sectionsNamespace'] ?? ($base . '\\Sections'));
		$listenerNs = (string)($options['listenerNamespace'] ?? ($base . '\\Listener'));
		$serviceNs = (string)($options['serviceNamespace'] ?? ($base . '\\Service'));

		$sectionId = (string)($options['sectionId'] ?? $appId);
		$sectionName = (string)($options['sectionName'] ?? $studly);
		$sectionIcon = (string)($options['sectionIcon'] ?? 'app-dark.svg');
		$sectionPriority = (int)($options['sectionPriority'] ?? 75);
		$adminPriority = (int)($options['adminPriority'] ?? 10);
		$registerDeepLinks = ($options['deepLinks'] ?? true) !== false;
		$registerObserv = ($options['observability'] ?? true) !== false;

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

		foreach ((array)($options['dashboardWidgets'] ?? []) as $widgetClass) {
			$context->registerDashboardWidget((string)$widgetClass);
		}

		if (isset($options['mcpProvider']) === true) {
			$context->registerServiceAlias(
				'OCA\\OpenRegister\\Mcp\\IMcpToolProvider::' . $appId,
				(string)$options['mcpProvider']
			);
		}
	}//end register()

	/**
	 * Alias the leaf controller class names to the generic controllers, with
	 * the leaf appId injected as the controllers' `$appName`.
	 *
	 * @param IRegistrationContext $context Leaf registration context.
	 * @param string $appId Leaf app id.
	 * @param string $controllerNs Leaf controller namespace.
	 * @param bool $observability Whether to alias health/metrics.
	 *
	 * @return void
	 */
	private static function registerControllers(IRegistrationContext $context, string $appId, string $controllerNs, bool $observability): void {
		self::aliasControllerUnlessLeafDefinesIt(
			context: $context,
			leafClass: $controllerNs . '\\DashboardController',
			factory: static function (ContainerInterface $c) use ($appId) {
				$class = self::GENERIC_DASHBOARD_CONTROLLER;
				return new $class(
					appName: $appId,
					request: $c->get('OCP\\IRequest')
				);
			}
		);

		self::aliasControllerUnlessLeafDefinesIt(
			context: $context,
			leafClass: $controllerNs . '\\PreferencesController',
			factory: static function (ContainerInterface $c) use ($appId) {
				$class = self::GENERIC_PREFERENCES_CONTROLLER;
				return new $class(
					appName: $appId,
					request: $c->get('OCP\\IRequest'),
					config: $c->get('OCP\\IConfig'),
					userSession: $c->get('OCP\\IUserSession')
				);
			}
		);

		self::aliasControllerUnlessLeafDefinesIt(
			context: $context,
			leafClass: $controllerNs . '\\SettingsController',
			factory: static function (ContainerInterface $c) use ($appId) {
				$class = self::GENERIC_SETTINGS_CONTROLLER;
				return new $class(
					appName: $appId,
					request: $c->get('OCP\\IRequest'),
					settingsService: $c->get(self::GENERIC_SETTINGS_SERVICE)
				);
			}
		);

		// Store plane (ADR-080, ADR-114 Decision 4). `unlessLeafDefinesIt` is
		// what makes this a migration rather than a flag day: dossiq ships its
		// own Controller\StoreController today and keeps winning this alias
		// until that class is deleted, so the engine's version takes over per
		// app, on that app's own pull request.
		self::aliasStoreController(context: $context, appId: $appId, controllerNs: $controllerNs);

		if ($observability === false) {
			return;
		}

		self::aliasControllerUnlessLeafDefinesIt(
			context: $context,
			leafClass: $controllerNs . '\\HealthController',
			factory: static function (ContainerInterface $c) use ($appId) {
				$class = self::GENERIC_HEALTH_CONTROLLER;
				return new $class(
					appName: $appId,
					request: $c->get('OCP\\IRequest'),
					manifestLoader: $c->get(self::OBSERVABILITY_MANIFEST_LOADER),
					executor: $c->get(self::OBSERVABILITY_EXECUTOR)
				);
			}
		);

		self::aliasControllerUnlessLeafDefinesIt(
			context: $context,
			leafClass: $controllerNs . '\\MetricsController',
			factory: static function (ContainerInterface $c) use ($appId) {
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
	 * Bind the store controller for one leaf app.
	 *
	 * 🔴 A ROUTE WITHOUT ITS CONTROLLER IS A DISPATCH-TIME 500, NOT A 404.
	 *
	 * `Routes::standard()` declares `/api/store/items` for EVERY app that
	 * adopts the canonical table, but the binding lives here. An app that took
	 * the route table and binds its controllers by hand, rather than calling
	 * {@see self::register()}, therefore serves a route it cannot resolve.
	 * Measured 2026-09-03 on a running instance: decidiq, filinq and planninq
	 * each returned HTTP 500 on `/api/store/items`, on a route none of them
	 * had asked for, while keepiq (which calls `register()`) answered fine.
	 *
	 * This is the one call such an app needs, and it is public for exactly
	 * that reason. It stays a no-op when the leaf ships its own
	 * `Controller\StoreController`, so calling it is always safe.
	 *
	 * @param IRegistrationContext $context      Leaf registration context.
	 * @param string               $appId        Leaf app id.
	 * @param string               $controllerNs Leaf controller namespace (e.g. `OCA\Decidiq\Controller`).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-a-leaf-app-must-declare-its-store-rather-than-implement-one
	 */
	public static function aliasStoreController(IRegistrationContext $context, string $appId, string $controllerNs): void {
		self::aliasControllerUnlessLeafDefinesIt(
			context: $context,
			leafClass: rtrim($controllerNs, '\\') . '\\StoreController',
			factory: static function (ContainerInterface $c) use ($appId) {
				$class = self::GENERIC_STORE_CONTROLLER;
				return new $class(
					appName: $appId,
					request: $c->get('OCP\\IRequest'),
					manifestLoader: $c->get(self::OBSERVABILITY_MANIFEST_LOADER),
					storeService: $c->get(self::GENERIC_STORE_SERVICE),
					installer: $c->get(self::GENERIC_STORE_INSTALLER),
					catalog: $c->get(self::FEDERATED_STORE_CATALOG),
					gitHubSource: $c->get(self::GITHUB_STORE_SOURCE),
					actionAuthorizer: $c->get(self::STORE_ACTION_AUTHORIZER),
					userSession: $c->get('OCP\\IUserSession'),
					groupManager: $c->get('OCP\\IGroupManager'),
					logger: $c->get('Psr\\Log\\LoggerInterface')
				);
			}
		);

	}//end aliasStoreController()

	/**
	 * Alias one leaf controller class name to a generic AppHost controller,
	 * but ONLY when the leaf app does not ship a controller of that name
	 * itself.
	 *
	 * `IRegistrationContext::registerService()` OVERRIDES the container's
	 * autowiring for the given class name. Registering unconditionally
	 * therefore SHADOWED any controller the consuming app already provided:
	 * the leaf's routes still resolved, but they dispatched to OpenRegister's
	 * generic controller instead of the leaf's own. Two symptoms were observed
	 * live on a consuming app:
	 *
	 *   1. Routes calling a method that only exists on the leaf's controller
	 *      (e.g. `dashboard#summary`) 500'd, because the generic controller
	 *      has no such method.
	 *   2. Response-level behaviour the leaf's controller applied — notably a
	 *      Content-Security-Policy built with `allowEvalWasm(true)` — never
	 *      ran, so the served CSP lacked `wasm-unsafe-eval` and every
	 *      WASM-backed feature (Argon2 share/export/import) was blocked.
	 *
	 * A leaf that defines the class wins; a leaf that does not still gets the
	 * generic implementation for free, which is the whole point of AppHost.
	 *
	 * @param IRegistrationContext $context Leaf registration context.
	 * @param string $leafClass Fully-qualified leaf controller class name.
	 * @param \Closure $factory Factory building the generic controller.
	 *
	 * @return void
	 */
	private static function aliasControllerUnlessLeafDefinesIt(IRegistrationContext $context, string $leafClass, \Closure $factory): void {
		// `class_exists()` autoloads, so this resolves the leaf app's own
		// controller through its composer autoloader when it has one.
		if (class_exists($leafClass) === true) {
			return;
		}

		$context->registerService($leafClass, $factory);

	}//end aliasControllerUnlessLeafDefinesIt()

	/**
	 * Register the app-scoped settings + action-auth services under both the
	 * generic class name (so the controllers resolve them) and the leaf's
	 * conventional service class names (so leaf code keeps working).
	 *
	 * @param IRegistrationContext $context Leaf registration context.
	 * @param string $appId Leaf app id.
	 * @param string $serviceNs Leaf service namespace.
	 *
	 * @return void
	 */
	private static function registerServices(IRegistrationContext $context, string $appId, string $serviceNs): void {
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
		$context->registerService($serviceNs . '\\SettingsService', $settingsFactory);

		$actionAuthFactory = static function (ContainerInterface $c) use ($appId) {
			$class = self::GENERIC_ACTION_AUTH_SERVICE;
			return new $class(
				appId: $appId,
				appConfig: $c->get('OCP\\IAppConfig'),
				groupManager: $c->get('OCP\\IGroupManager')
			);
		};
		$context->registerService(self::GENERIC_ACTION_AUTH_SERVICE, $actionAuthFactory);
		$context->registerService($serviceNs . '\\ActionAuthService', $actionAuthFactory);

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
		$context->registerService($serviceNs . '\\RegisterConfigResolver', $registerConfigResolverFactory);
	}//end registerServices()

	/**
	 * Bind the leaf repair-step class names (referenced by info.xml) to the
	 * generic repair steps with the appId + app-scoped services injected.
	 *
	 * @param IRegistrationContext $context Leaf registration context.
	 * @param string $appId Leaf app id.
	 * @param string $repairNs Leaf repair namespace.
	 *
	 * @return void
	 */
	private static function registerRepairSteps(IRegistrationContext $context, string $appId, string $repairNs): void {
		$context->registerService(
			$repairNs . '\\InitializeSettings',
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
			$repairNs . '\\InitializeActions',
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
	 * @param IRegistrationContext $context Leaf registration context.
	 * @param string $appId Leaf app id.
	 * @param string $settingsNs Leaf settings namespace.
	 * @param string $sectionsNs Leaf sections namespace.
	 * @param string $sectionId Section id.
	 * @param string $sectionName Section display name.
	 * @param string $sectionIcon Section icon file.
	 * @param int $sectionPriority Section priority.
	 * @param int $adminPriority Admin form priority.
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
		int $adminPriority,
	): void {
		$context->registerService(
			$settingsNs . '\\AdminSettings',
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
			$sectionsNs . '\\SettingsSection',
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
	 * @param IRegistrationContext $context Leaf registration context.
	 * @param string $appId Leaf app id.
	 * @param string $listenerNs Leaf listener namespace.
	 *
	 * @return void
	 */
	private static function registerDeepLinkListener(IRegistrationContext $context, string $appId, string $listenerNs): void {
		$factory = static function (ContainerInterface $c) use ($appId) {
			$class = self::GENERIC_DEEPLINK_LISTENER;
			return new $class(
				appId: $appId,
				appManager: $c->get('OCP\\App\\IAppManager'),
				logger: $c->get('Psr\\Log\\LoggerInterface')
			);
		};
		$context->registerService(self::GENERIC_DEEPLINK_LISTENER, $factory);
		$context->registerService($listenerNs . '\\DeepLinkRegistrationListener', $factory);

		$context->registerEventListener(self::DEEPLINK_EVENT, $listenerNs . '\\DeepLinkRegistrationListener');
	}//end registerDeepLinkListener()

	/**
	 * Convert a dash/underscore app id into a StudlyCase namespace segment
	 * (e.g. `pet_store` / `pet-store` → `PetStore`).
	 *
	 * @param string $appId The app id.
	 *
	 * @return string StudlyCase form.
	 */
	private static function studly(string $appId): string {
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
