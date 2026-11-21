<?php

/**
 * OpenRegister AppHost — one leaf app's registration
 *
 * The per-call object {@see Bootstrap::register()} builds for one leaf app:
 * the leaf's registration context and app id, plus the registration steps
 * that bind the leaf's conventional class names to the generic AppHost
 * classes.
 *
 * Like Bootstrap, no `OCA\OpenRegister\…` symbol is referenced here outside a
 * closure: the generic class names are plain strings, so a closure body only
 * autoloads one when the leaf's container resolves that service.
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
 * Registers the standard AppHost plumbing for one leaf app.
 *
 * @spec openspec/specs/apphost-boilerplate/spec.md — Requirement: One-Call Bootstrap
 */
final class LeafRegistration {
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

	/**
	 * The store controller's discovery, install and platform services.
	 */
	private const GENERIC_STORE_CONTROLLER_SERVICES = 'OCA\\OpenRegister\\AppHost\\Controller\\Support\\GenericStoreControllerServices';

	private const GENERIC_SETTINGS_SERVICE = 'OCA\\OpenRegister\\AppHost\\Service\\AppHostSettingsService';
	private const GENERIC_SETTINGS_SERVICES = 'OCA\\OpenRegister\\AppHost\\Service\\AppHostSettingsServices';
	private const GENERIC_ACTION_AUTH_SERVICE = 'OCA\\OpenRegister\\AppHost\\Service\\GenericActionAuthService';
	private const GENERIC_INITIALIZE_SETTINGS = 'OCA\\OpenRegister\\AppHost\\Repair\\GenericInitializeSettings';
	private const GENERIC_INITIALIZE_ACTIONS = 'OCA\\OpenRegister\\AppHost\\Repair\\GenericInitializeActions';
	private const GENERIC_ADMIN_SETTINGS = 'OCA\\OpenRegister\\AppHost\\Settings\\GenericAdminSettings';
	private const GENERIC_SETTINGS_SECTION = 'OCA\\OpenRegister\\AppHost\\Settings\\GenericSettingsSection';
	private const GENERIC_DEEPLINK_LISTENER = 'OCA\\OpenRegister\\AppHost\\Listener\\GenericDeepLinkRegistrationListener';

	/**
	 * Decides which of a leaf app's pages open without a session.
	 */
	private const PUBLIC_PAGE_RESOLVER = 'OCA\\OpenRegister\\AppHost\\Service\\PublicPageResolver';

	private const GENERIC_SETTINGS_PLANE_SERVICE = 'OCA\\OpenRegister\\AppHost\\Service\\GenericSettingsService';
	private const REGISTER_CONFIG_RESOLVER = 'OCA\\OpenRegister\\AppHost\\Service\\RegisterConfigResolver';

	private const OBSERVABILITY_MANIFEST_LOADER = 'OCA\\OpenRegister\\AppHost\\Observability\\ManifestLoader';
	private const OBSERVABILITY_EXECUTOR = 'OCA\\OpenRegister\\AppHost\\Observability\\HealthCheckExecutor';
	private const OBSERVABILITY_METRICS_ENGINE = 'OCA\\OpenRegister\\AppHost\\Observability\\MetricsEngine';

	private const DEEPLINK_EVENT = 'OCA\\OpenRegister\\Event\\DeepLinkRegistrationEvent';

	/**
	 * Constructor.
	 *
	 * @param IRegistrationContext $context The leaf app's registration context.
	 * @param string               $appId   The leaf app id (e.g. 'petstore').
	 */
	public function __construct(
		private readonly IRegistrationContext $context,
		private readonly string $appId,
	) {
	}//end __construct()

	/**
	 * Alias the leaf controller class names to the generic controllers, with
	 * the leaf appId injected as the controllers' `$appName`.
	 *
	 * @param string $controllerNs Leaf controller namespace.
	 *
	 * @return void
	 */
	public function registerControllers(string $controllerNs): void {
		$this->aliasControllerUnlessLeafDefinesIt(
			leafClass: $controllerNs . '\\DashboardController',
			factory: self::dashboardControllerFactory(appId: $this->appId)
		);

		$this->aliasControllerUnlessLeafDefinesIt(
			leafClass: $controllerNs . '\\PreferencesController',
			factory: self::preferencesControllerFactory(appId: $this->appId)
		);

		$this->aliasControllerUnlessLeafDefinesIt(
			leafClass: $controllerNs . '\\SettingsController',
			factory: self::settingsControllerFactory(appId: $this->appId)
		);

		// Store plane (ADR-080, ADR-114 Decision 4). `unlessLeafDefinesIt` is
		// what makes this a migration rather than a flag day: dossiq ships its
		// own Controller\StoreController today and keeps winning this alias
		// until that class is deleted, so the engine's version takes over per
		// app, on that app's own pull request.
		$this->aliasStoreController(controllerNs: $controllerNs);
	}//end registerControllers()

	/**
	 * Alias the leaf health and metrics controller class names to the generic
	 * observability controllers.
	 *
	 * @param string $controllerNs Leaf controller namespace.
	 *
	 * @return void
	 */
	public function registerObservabilityControllers(string $controllerNs): void {
		$this->aliasControllerUnlessLeafDefinesIt(
			leafClass: $controllerNs . '\\HealthController',
			factory: self::healthControllerFactory(appId: $this->appId)
		);

		$this->aliasControllerUnlessLeafDefinesIt(
			leafClass: $controllerNs . '\\MetricsController',
			factory: self::metricsControllerFactory(appId: $this->appId)
		);
	}//end registerControllers()

	/**
	 * Bind the store controller for the leaf app, unless the leaf ships its own.
	 *
	 * @param string $controllerNs Leaf controller namespace.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-a-leaf-app-must-declare-its-store-rather-than-implement-one
	 */
	public function aliasStoreController(string $controllerNs): void {
		$appId = $this->appId;
		$this->aliasControllerUnlessLeafDefinesIt(
			leafClass: rtrim($controllerNs, '\\') . '\\StoreController',
			factory: static function (ContainerInterface $c) use ($appId) {
				$class = self::GENERIC_STORE_CONTROLLER;
				return new $class(
					appName: $appId,
					request: $c->get('OCP\\IRequest'),
					services: $c->get(self::GENERIC_STORE_CONTROLLER_SERVICES)
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
	 * @param string $leafClass Fully-qualified leaf controller class name.
	 * @param \Closure $factory Factory building the generic controller.
	 *
	 * @return void
	 */
	private function aliasControllerUnlessLeafDefinesIt(string $leafClass, \Closure $factory): void {
		// `class_exists()` autoloads, so this resolves the leaf app's own
		// controller through its composer autoloader when it has one.
		if (class_exists($leafClass) === true) {
			return;
		}

		$this->context->registerService($leafClass, $factory);

	}//end aliasControllerUnlessLeafDefinesIt()

	/**
	 * Register the app-scoped settings + action-auth services under both the
	 * generic class name (so the controllers resolve them) and the leaf's
	 * conventional service class names (so leaf code keeps working).
	 *
	 * @param string $serviceNs Leaf service namespace.
	 *
	 * @return void
	 */
	public function registerServices(string $serviceNs): void {
		$settingsFactory = self::settingsServiceFactory(class: self::GENERIC_SETTINGS_SERVICE, appId: $this->appId);
		$this->context->registerService(self::GENERIC_SETTINGS_SERVICE, $settingsFactory);
		$this->context->registerService($serviceNs . '\\SettingsService', $settingsFactory);

		$actionAuthFactory = self::actionAuthServiceFactory(appId: $this->appId);
		$this->context->registerService(self::GENERIC_ACTION_AUTH_SERVICE, $actionAuthFactory);
		$this->context->registerService($serviceNs . '\\ActionAuthService', $actionAuthFactory);

		// ADR-066 settings-plane consumables. Appended AFTER the pre-existing
		// registrations on purpose — the AppHost bootstrap load-order incident
		// (listeners killed by a reorder) makes registration order here part of
		// the contract: never reorder, only append. Both are lazy closures, so
		// a disabled OpenRegister still never fatals NC bootstrap.
		$this->context->registerService(
			self::GENERIC_SETTINGS_PLANE_SERVICE,
			self::settingsServiceFactory(class: self::GENERIC_SETTINGS_PLANE_SERVICE, appId: $this->appId)
		);

		$registerConfigResolverFactory = self::registerConfigResolverFactory(appId: $this->appId);
		$this->context->registerService(self::REGISTER_CONFIG_RESOLVER, $registerConfigResolverFactory);
		$this->context->registerService($serviceNs . '\\RegisterConfigResolver', $registerConfigResolverFactory);
	}//end registerServices()

	/**
	 * Bind the leaf repair-step class names (referenced by info.xml) to the
	 * generic repair steps with the appId + app-scoped services injected.
	 *
	 * @param string $repairNs Leaf repair namespace.
	 *
	 * @return void
	 */
	public function registerRepairSteps(string $repairNs): void {
		$this->context->registerService(
			$repairNs . '\\InitializeSettings',
			self::initializeSettingsFactory(appId: $this->appId)
		);

		$this->context->registerService(
			$repairNs . '\\InitializeActions',
			self::initializeActionsFactory(appId: $this->appId)
		);
	}//end registerRepairSteps()

	/**
	 * Bind the leaf admin-settings class name (referenced by info.xml) to the
	 * generic admin settings form with the leaf metadata.
	 *
	 * @param string               $settingsNs Leaf settings namespace.
	 * @param array{id: string, name: string, icon: string, priority: int, adminPriority: int} $section Section metadata.
	 *
	 * @return void
	 */
	public function registerAdminForm(string $settingsNs, array $section): void {
		$this->context->registerService(
			$settingsNs . '\\AdminSettings',
			self::adminSettingsFactory(appId: $this->appId, section: $section)
		);
	}//end registerAdminForm()

	/**
	 * Bind the leaf settings-section class name (referenced by info.xml) to
	 * the generic settings section with the leaf metadata.
	 *
	 * @param string               $sectionsNs Leaf sections namespace.
	 * @param array{id: string, name: string, icon: string, priority: int, adminPriority: int} $section Section metadata.
	 *
	 * @return void
	 */
	public function registerSettingsSection(string $sectionsNs, array $section): void {
		$this->context->registerService(
			$sectionsNs . '\\SettingsSection',
			self::settingsSectionFactory(appId: $this->appId, section: $section)
		);
	}//end registerSettingsSection()

	/**
	 * Register the generic deep-link listener (manifest-driven) and bind the
	 * leaf listener class name to it. The event listener is registered against
	 * OpenRegister's DeepLinkRegistrationEvent by its string name, so a
	 * disabled OR simply never dispatches the event — no fatal.
	 *
	 * @param string $listenerNs Leaf listener namespace.
	 *
	 * @return void
	 */
	public function registerDeepLinkListener(string $listenerNs): void {
		$appId = $this->appId;
		$factory = static function (ContainerInterface $c) use ($appId) {
			$class = self::GENERIC_DEEPLINK_LISTENER;
			return new $class(
				appId: $appId,
				appManager: $c->get('OCP\\App\\IAppManager'),
				logger: $c->get('Psr\\Log\\LoggerInterface')
			);
		};
		$this->context->registerService(self::GENERIC_DEEPLINK_LISTENER, $factory);
		$this->context->registerService($listenerNs . '\\DeepLinkRegistrationListener', $factory);

		$this->context->registerEventListener(self::DEEPLINK_EVENT, $listenerNs . '\\DeepLinkRegistrationListener');
	}//end registerDeepLinkListener()

	/**
	 * Factory for the generic dashboard controller of a leaf app.
	 *
	 * @param string $appId The leaf app id.
	 *
	 * @return \Closure
	 */
	private static function dashboardControllerFactory(string $appId): \Closure {
		return static function (ContainerInterface $c) use ($appId) {
			$class = self::GENERIC_DASHBOARD_CONTROLLER;
			return new $class(
				appName: $appId,
				request: $c->get('OCP\\IRequest'),
				// The leaf's OWN initial state, so the public flag lands
				// under the leaf app id the SPA reads it with.
				publicPages: $c->get(self::PUBLIC_PAGE_RESOLVER),
				initialState: $c->get('OCP\\AppFramework\\Services\\IInitialState')
			);
		};
	}//end dashboardControllerFactory()

	/**
	 * Factory for the generic preferences controller of a leaf app.
	 *
	 * @param string $appId The leaf app id.
	 *
	 * @return \Closure
	 */
	private static function preferencesControllerFactory(string $appId): \Closure {
		return static function (ContainerInterface $c) use ($appId) {
			$class = self::GENERIC_PREFERENCES_CONTROLLER;
			return new $class(
				appName: $appId,
				request: $c->get('OCP\\IRequest'),
				config: $c->get('OCP\\IConfig'),
				userSession: $c->get('OCP\\IUserSession')
			);
		};
	}//end preferencesControllerFactory()

	/**
	 * Factory for the generic settings controller of a leaf app.
	 *
	 * @param string $appId The leaf app id.
	 *
	 * @return \Closure
	 */
	private static function settingsControllerFactory(string $appId): \Closure {
		return static function (ContainerInterface $c) use ($appId) {
			$class = self::GENERIC_SETTINGS_CONTROLLER;
			return new $class(
				appName: $appId,
				request: $c->get('OCP\\IRequest'),
				settingsService: $c->get(self::GENERIC_SETTINGS_SERVICE)
			);
		};
	}//end settingsControllerFactory()

	/**
	 * Factory for the generic health controller of a leaf app.
	 *
	 * @param string $appId The leaf app id.
	 *
	 * @return \Closure
	 */
	private static function healthControllerFactory(string $appId): \Closure {
		return static function (ContainerInterface $c) use ($appId) {
			$class = self::GENERIC_HEALTH_CONTROLLER;
			return new $class(
				appName: $appId,
				request: $c->get('OCP\\IRequest'),
				manifestLoader: $c->get(self::OBSERVABILITY_MANIFEST_LOADER),
				executor: $c->get(self::OBSERVABILITY_EXECUTOR)
			);
		};
	}//end healthControllerFactory()

	/**
	 * Factory for the generic metrics controller of a leaf app.
	 *
	 * @param string $appId The leaf app id.
	 *
	 * @return \Closure
	 */
	private static function metricsControllerFactory(string $appId): \Closure {
		return static function (ContainerInterface $c) use ($appId) {
			$class = self::GENERIC_METRICS_CONTROLLER;
			return new $class(
				appName: $appId,
				request: $c->get('OCP\\IRequest'),
				manifestLoader: $c->get(self::OBSERVABILITY_MANIFEST_LOADER),
				engine: $c->get(self::OBSERVABILITY_METRICS_ENGINE)
			);
		};
	}//end metricsControllerFactory()

	/**
	 * Factory for an app-scoped settings service: the AppHost settings service
	 * and the ADR-066 settings-plane service take the same dependencies.
	 *
	 * @param string $class The settings service class to build.
	 * @param string $appId The leaf app id.
	 *
	 * @return \Closure
	 */
	private static function settingsServiceFactory(string $class, string $appId): \Closure {
		return static function (ContainerInterface $c) use ($class, $appId) {
			return new $class(
				appId: $appId,
				services: self::settingsServices(c: $c),
				groupManager: $c->get('OCP\\IGroupManager'),
				userSession: $c->get('OCP\\IUserSession')
			);
		};
	}//end settingsServiceFactory()

	/**
	 * Build the services an app-scoped settings service runs on.
	 *
	 * @param ContainerInterface $c The DI container.
	 *
	 * @return object
	 */
	private static function settingsServices(ContainerInterface $c): object {
		$services = self::GENERIC_SETTINGS_SERVICES;
		return new $services(
			appConfig: $c->get('OCP\\IAppConfig'),
			appManager: $c->get('OCP\\App\\IAppManager'),
			container: $c,
			logger: $c->get('Psr\\Log\\LoggerInterface')
		);
	}//end settingsServices()

	/**
	 * Factory for the app-scoped action-auth service.
	 *
	 * @param string $appId The leaf app id.
	 *
	 * @return \Closure
	 */
	private static function actionAuthServiceFactory(string $appId): \Closure {
		return static function (ContainerInterface $c) use ($appId) {
			$class = self::GENERIC_ACTION_AUTH_SERVICE;
			return new $class(
				appId: $appId,
				appConfig: $c->get('OCP\\IAppConfig'),
				groupManager: $c->get('OCP\\IGroupManager')
			);
		};
	}//end actionAuthServiceFactory()

	/**
	 * Factory for the app-scoped register config resolver.
	 *
	 * @param string $appId The leaf app id.
	 *
	 * @return \Closure
	 */
	private static function registerConfigResolverFactory(string $appId): \Closure {
		return static function (ContainerInterface $c) use ($appId) {
			$class = self::REGISTER_CONFIG_RESOLVER;
			return new $class(
				appId: $appId,
				appManager: $c->get('OCP\\App\\IAppManager'),
				container: $c,
				logger: $c->get('Psr\\Log\\LoggerInterface')
			);
		};
	}//end registerConfigResolverFactory()

	/**
	 * Factory for the generic InitializeSettings repair step of a leaf app.
	 *
	 * @param string $appId The leaf app id.
	 *
	 * @return \Closure
	 */
	private static function initializeSettingsFactory(string $appId): \Closure {
		return static function (ContainerInterface $c) use ($appId) {
			$class = self::GENERIC_INITIALIZE_SETTINGS;
			return new $class(
				appId: $appId,
				settingsService: $c->get(self::GENERIC_SETTINGS_SERVICE),
				logger: $c->get('Psr\\Log\\LoggerInterface'),
				appManager: $c->get('OCP\\App\\IAppManager'),
				tokenService: $c->get('OCA\\OpenRegister\\Service\\Credential\\CredentialAppTokenService'),
				applicationRegistrar: $c->get('OCA\\OpenRegister\\Service\\Credential\\DoriathApplicationRegistrar')
			);
		};
	}//end initializeSettingsFactory()

	/**
	 * Factory for the generic InitializeActions repair step of a leaf app.
	 *
	 * @param string $appId The leaf app id.
	 *
	 * @return \Closure
	 */
	private static function initializeActionsFactory(string $appId): \Closure {
		return static function (ContainerInterface $c) use ($appId) {
			$class = self::GENERIC_INITIALIZE_ACTIONS;
			return new $class(
				appId: $appId,
				actionAuth: $c->get(self::GENERIC_ACTION_AUTH_SERVICE),
				appManager: $c->get('OCP\\App\\IAppManager'),
				logger: $c->get('Psr\\Log\\LoggerInterface')
			);
		};
	}//end initializeActionsFactory()

	/**
	 * Factory for the generic admin settings form of a leaf app.
	 *
	 * @param string                                                                           $appId   The leaf app id.
	 * @param array{id: string, name: string, icon: string, priority: int, adminPriority: int} $section Section metadata.
	 *
	 * @return \Closure
	 */
	private static function adminSettingsFactory(string $appId, array $section): \Closure {
		$sectionId = $section['id'];
		$adminPriority = $section['adminPriority'];

		return static function (ContainerInterface $c) use ($appId, $sectionId, $adminPriority) {
			$class = self::GENERIC_ADMIN_SETTINGS;
			return new $class(
				appId: $appId,
				sectionId: $sectionId,
				priority: $adminPriority,
				appManager: $c->get('OCP\\App\\IAppManager'),
				initialState: $c->get('OCP\\AppFramework\\Services\\IInitialState'),
				appConfig: $c->get('OCP\\IAppConfig')
			);
		};
	}//end adminSettingsFactory()

	/**
	 * Factory for the generic settings section of a leaf app.
	 *
	 * @param string                                                                           $appId   The leaf app id.
	 * @param array{id: string, name: string, icon: string, priority: int, adminPriority: int} $section Section metadata.
	 *
	 * @return \Closure
	 */
	private static function settingsSectionFactory(string $appId, array $section): \Closure {
		return static function (ContainerInterface $c) use ($appId, $section) {
			$class = self::GENERIC_SETTINGS_SECTION;
			return new $class(
				sectionId: $section['id'],
				name: $section['name'],
				appId: $appId,
				iconFile: $section['icon'],
				priority: $section['priority'],
				urlGenerator: $c->get('OCP\\IURLGenerator')
			);
		};
	}//end settingsSectionFactory()
}//end class
