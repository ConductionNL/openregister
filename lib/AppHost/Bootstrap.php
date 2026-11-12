<?php

/**
 * OpenRegister AppHost — Bootstrap
 *
 * One-call registration for an AppHost-adopting leaf app. From the leaf's
 * `Application::register(IRegistrationContext $context)`, a single
 * `\OCA\OpenRegister\AppHost\Bootstrap::register($context, new LeafAdoption(appId: self::APP_ID))`
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
 * the app's autoloader registration and then `$application->register()`
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
 *         $appManager = \OCP\Server::get(\OCP\App\IAppManager::class);
 *         if ($appManager->isEnabledForAnyone('openregister') === true) {
 *             $orLib = rtrim($appManager->getAppPath('openregister'), '/').'/lib/';
 *             spl_autoload_register(static function (string $class) use ($orLib): void {
 *                 if (str_starts_with($class, 'OCA\\OpenRegister\\') === true) {
 *                     $file = $orLib.str_replace('\\', '/', substr($class, 17)).'.php';
 *                     if (is_file($file) === true) {
 *                         require_once $file;
 *                     }
 *                 }
 *             });
 *         }
 *     } catch (\Throwable) {
 *         // OpenRegister absent/disabled — fall through to the degraded path.
 *     }
 *
 * Prefer housing it in a small `AppInfo\OpenRegisterAutoloader::register()`
 * class (keepiq#712, decidiq) so the "never throws" contract is unit-testable
 * and a second call is a no-op.
 *
 * Use PUBLIC API only. Earlier revisions of this docblock prescribed
 * `\OC_App::registerAutoloading('openregister', $orPath)`; that is private API
 * and Nextcloud 35 REMOVED it (moved to the equally private
 * `OC\App\AppManager`). On 35 the call throws `\Error`, lands in the catch,
 * and the AppHost wiring is skipped in silence. Because OpenRegister ships its
 * own `vendor/autoload.php`, Nextcloud's own registration for it reduces to a
 * PSR-4 prefix over `lib/`, which is exactly what the loader above registers.
 * Do NOT substitute `IAppManager::loadApp('openregister')`: it sets
 * `loadedApps[..]=true` and calls `Coordinator::bootApp()`, booting
 * OpenRegister before its own `register()` has run. Do NOT require OpenRegister's
 * `vendor/autoload.php` either — a relative `include_once` assumes both apps
 * share one apps directory and silently does nothing on a multi-`apps_paths`
 * install, and it pulls OpenRegister's whole dependency tree into the process.
 *
 * Note that any single app doing this registers the prefix PROCESS-WIDE, which
 * masks the defect for every app registering after it. That is precisely why
 * this failed silently for so long: on a dev instance with one such app present
 * everything resolves, and in CI with a minimal app set it does not. Enforced by
 * hydra gate-64 (apphost-autoload-prelude).
 *
 * No `OCA\OpenRegister\…` symbol is referenced outside a closure in this file
 * (no `use` of OR classes, no `::class` at top level) other than its sibling
 * {@see LeafRegistration}, which holds the registration steps and keeps the
 * same invariant — that is what keeps the CLOSURES lazy, and it is asserted by
 * the unit test. The sibling loads through the same autoloader that resolved
 * `Bootstrap`, so it adds no load-order requirement. Neither says anything
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

/**
 * Declarative one-call bootstrap for AppHost leaf apps.
 *
 * @spec openspec/changes/apphost-boilerplate-controllers/tasks.md#task-3.1
 * @spec openspec/specs/apphost-boilerplate/spec.md — Requirement: One-Call Bootstrap
 */
class Bootstrap {
	/**
	 * Register all standard AppHost plumbing for a leaf app.
	 *
	 * @param IRegistrationContext $context  The leaf app's registration context.
	 * @param LeafAdoption         $adoption The leaf app id (e.g. 'petstore') and its optional overrides:
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
	public static function register(IRegistrationContext $context, LeafAdoption $adoption): void {
		self::registerPlanes(leaf: new LeafRegistration(context: $context, appId: $adoption->appId), adoption: $adoption);
		self::registerExtras(context: $context, adoption: $adoption);
	}//end register()

	/**
	 * Wire the controllers, services, repair steps, admin panel and deep links one leaf asked for.
	 *
	 * @param LeafRegistration $leaf     The leaf's registration.
	 * @param LeafAdoption     $adoption The leaf's app id and overrides.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-boilerplate/spec.md — Requirement: One-Call Bootstrap
	 */
	private static function registerPlanes(LeafRegistration $leaf, LeafAdoption $adoption): void {
		$controllerNs = $adoption->namespace(option: 'controllerNamespace', suffix: 'Controller');
		$leaf->registerControllers(controllerNs: $controllerNs);
		if ($adoption->enabled(flag: 'observability') === true) {
			$leaf->registerObservabilityControllers(controllerNs: $controllerNs);
		}

		$leaf->registerServices(serviceNs: $adoption->namespace(option: 'serviceNamespace', suffix: 'Service'));
		$leaf->registerRepairSteps(repairNs: $adoption->namespace(option: 'repairNamespace', suffix: 'Repair'));
		$leaf->registerAdminForm(settingsNs: $adoption->namespace(option: 'settingsNamespace', suffix: 'Settings'), section: $adoption->section());
		$leaf->registerSettingsSection(sectionsNs: $adoption->namespace(option: 'sectionsNamespace', suffix: 'Sections'), section: $adoption->section());
		if ($adoption->enabled(flag: 'deepLinks') === true) {
			$leaf->registerDeepLinkListener(listenerNs: $adoption->namespace(option: 'listenerNamespace', suffix: 'Listener'));
		}
	}//end registerPlanes()

	/**
	 * Register the dashboard widgets and the MCP provider alias one leaf named.
	 *
	 * @param IRegistrationContext $context  Leaf registration context.
	 * @param LeafAdoption         $adoption The leaf's app id and overrides.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-boilerplate/spec.md — Requirement: One-Call Bootstrap
	 */
	private static function registerExtras(IRegistrationContext $context, LeafAdoption $adoption): void {
		foreach ($adoption->dashboardWidgets() as $widgetClass) {
			$context->registerDashboardWidget((string)$widgetClass);
		}

		$mcpProvider = $adoption->mcpProvider();
		if ($mcpProvider !== null) {
			$context->registerServiceAlias('OCA\\OpenRegister\\Mcp\\IMcpToolProvider::' . $adoption->appId, $mcpProvider);
		}
	}//end registerExtras()

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
	 * The controller namespace is the adoption's `controllerNamespace`
	 * override, else its `namespace` plus `\Controller`.
	 *
	 * @param IRegistrationContext $context  Leaf registration context.
	 * @param LeafAdoption         $adoption Leaf app id and namespace (e.g. `['namespace' => 'OCA\Decidiq']`).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-a-leaf-app-must-declare-its-store-rather-than-implement-one
	 */
	public static function aliasStoreController(IRegistrationContext $context, LeafAdoption $adoption): void {
		(new LeafRegistration(context: $context, appId: $adoption->appId))->aliasStoreController(
			controllerNs: $adoption->namespace(option: 'controllerNamespace', suffix: 'Controller')
		);
	}//end aliasStoreController()
}//end class
