<?php

/**
 * OpenConnector Consumers Controller
 *
 * This file contains the controller for handling consumer related operations
 * in the OpenRegister application.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category AppInfo
 * @package  OCA\OpenRegister\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @psalm-suppress UnusedClass
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppInfo;

use OCA\OpenRegister\AppHost\FeatureToggleRegistrar;
use OCA\OpenRegister\AppHost\ObservabilityRegistrar;
use OCA\OpenRegister\Capabilities\CapabilityRegistrar;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MapperRegistrar;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Federation\OpenRegisterCloudFederationProvider;
use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCA\OpenRegister\Listener\ListenerRegistrar;
use OCA\OpenRegister\Mcp\McpProviderDiscovery;
use OCA\OpenRegister\Middleware\MiddlewareRegistrar;
use OCA\OpenRegister\Notification\NotifierRegistrar;
use OCA\OpenRegister\Search\SearchProviderRegistrar;
use OCA\OpenRegister\Service\Configuration\ConfigurationServiceRegistrar;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Flow\FlowRegistrar;
use OCA\OpenRegister\Service\Gdpr\GdprRegistrar;
use OCA\OpenRegister\Service\Integration\IntegrationRegistrar;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCA\OpenRegister\Service\NoteService;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistrar;
use OCA\OpenRegister\Service\Outbound\OutboundClientFactory;
use OCA\OpenRegister\Service\Rbac\RbacRegistrar;
use OCA\OpenRegister\Service\ServiceRegistrar;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\IAppConfig;
use OCP\Security\IContentSecurityPolicyManager;
use Psr\Container\ContainerInterface;

/**
 * Class Application
 *
 * Application class for the OpenRegister app that handles bootstrapping.
 *
 * @category AppInfo
 * @package  OCA\OpenRegister\AppInfo
 *
 * @author  Nextcloud Dev Team
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/nextcloud/server/blob/master/apps-extra/openregister
 *
 * Public services exposed for consumer apps via Nextcloud DI:
 * - {@see \OCA\OpenRegister\Service\RegisterService}            CRUD + helpers on Register entities.
 * - {@see \OCA\OpenRegister\Service\SchemaService}              CRUD + helpers on Schema entities.
 * - {@see \OCA\OpenRegister\Service\ObjectService}              CRUD + search on registered objects.
 * - {@see \OCA\OpenRegister\Service\RegisterResolverService}    Resolve `<context>_register` / `<context>_schema`
 *   IAppConfig keys to slug/UUID strings or hydrated entities; request-scoped cache + tenant aware.
 * - {@see \OCA\OpenRegister\Service\Serializer\RegisterSerializer} Serialize Register entities with `_extend`
 *   expansion (schemas, @self.stats) — single source of truth shared between HTTP and DI callers.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Bootstrap class must reference every
 *   service, mapper, event listener, and middleware registered for the entire app; the
 *   coupling is structural and cannot be reduced without breaking the NC bootstrap contract.
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)  Service registration methods enumerate
 *   all DI bindings in one place per NC bootstrap pattern; splitting them would spread
 *   the wire-up across unrelated files and obscure the dependency graph.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)   Single IBootstrap implementation per NC
 *   app-info convention; all register/boot logic must live in this one class.
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)  IBootstrap::boot(IBootContext) signature
 *   is fixed by the NC framework contract; the $context parameter may not be used in
 *   every version of the boot method.
 * @SuppressWarnings(PHPMD.TooManyMethods)         Bootstrap class groups its register/boot
 *   wire-up into per-concern helper methods (services, listeners, middleware, capabilities);
 *   inlining them to satisfy the method-count threshold would recreate one unreadable
 *   mega-method that the ExcessiveMethodLength suppression already guards against.
 */
class Application extends App implements IBootstrap {
	/**
	 * Application ID for the OpenRegister app
	 *
	 * @var string
	 */
	public const APP_ID = AppIdentity::APP_ID;

	/**
	 * Constructor for the Application class
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	public function __construct() {
		parent::__construct(appName: self::APP_ID);
	}//end __construct()

	/**
	 * Register application components
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	public function register(IRegistrationContext $context): void {
		include_once __DIR__ . '/../../vendor/autoload.php';
		\OCA\OpenRegister\Service\WritePhaseProbe::stamp('or.register.in');

		FeatureToggleRegistrar::register(context: $context);
		ServiceRegistrar::register(context: $context);

		// Every outbound call this app makes goes through the administered
		// proxy (api-as-a-versioned-surface, design D-6). Most gemeenten have
		// no direct egress, so a call that bypasses the proxy does not fail
		// visibly: it works on a developer's laptop and times out in
		// production.
		//
		// 🔑 BOUND UNDER `IClientService` RATHER THAN MIGRATING CALL SITES.
		// This app creates clients from fourteen files across twenty call
		// sites. Editing all twenty would leave the guarantee resting on
		// nobody ever adding a twenty-first, which is exactly the failure D-6
		// names. Overriding the binding means every existing call site is
		// untouched and every future one is covered by construction.
		//
		// The override is scoped to this app's container: nothing resolved
		// outside OpenRegister sees it.
		$context->registerService(
			\OCP\Http\Client\IClientService::class,
			static function ($container): \OCP\Http\Client\IClientService {
				// 🔴 The inner client comes from the SERVER container, not from
				// `$container`. Asking the app container for `IClientService`
				// here would resolve to this very closure and recurse until the
				// stack runs out. `OCP\Server::get()` is the public accessor
				// for the server's own binding, and it is what reaches past
				// the override we are installing.
				return new OutboundClientFactory(
					clientService: \OCP\Server::get(\OCP\Http\Client\IClientService::class),
					proxy: $container->get(\OCA\OpenRegister\Service\Outbound\ProxySettings::class),
				);
			}
		);

		FlowRegistrar::register(context: $context);
		RbacRegistrar::register(context: $context);
		GdprRegistrar::register(context: $context);

		// The contracts other components type-hint, bound to their services.
		ContractBindings::register(context: $context);

		MiddlewareRegistrar::register(context: $context);
		MapperRegistrar::register(context: $context);
		ConfigurationServiceRegistrar::register(context: $context);

		SearchProviderRegistrar::register(context: $context);
		$context->registerReferenceProvider(\OCA\OpenRegister\Reference\ObjectReferenceProvider::class);
		$context->registerCalendarProvider(\OCA\OpenRegister\Calendar\RegisterCalendarProvider::class);

		IntegrationRegistrar::register(context: $context);
		CapabilityRegistrar::register(context: $context);
		ObjectSourceRegistrar::register(context: $context);
		ListenerRegistrar::register(context: $context);
		(new McpProviderDiscovery())->register(context: $context);
		ObservabilityRegistrar::register(context: $context, appId: self::APP_ID);

		NotifierRegistrar::register(context: $context);

		// ADR-019 Phase E (Option B): single umbrella widget hosted on
		// the Nextcloud user-dashboard. Iterates the integration
		// registry client-side and mounts each leaf's `user-dashboard`
		// widget — locked in over the 24-tile alternative because per-
		// integration NC widgets would clutter the global dashboard.
		$context->registerDashboardWidget(
			\OCA\OpenRegister\Dashboard\IntegrationDashboardWidget::class
		);
		\OCA\OpenRegister\Service\WritePhaseProbe::stamp('or.register.out');
	}//end register()

	/**
	 * Boot application components
	 *
	 * @param IBootContext $context The boot context
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	public function boot(IBootContext $context): void {
		\OCA\OpenRegister\Service\WritePhaseProbe::stamp('or.boot.in');
		// Dispatch the deep link registration event so consuming apps
		// (Procest, Pipelinq, etc.) can register their URL patterns.
		// DeepLinkRegistryService uses ContainerInterface for lazy mapper
		// resolution, so no circular DI issues during registration.
		$server = $context->getServerContainer();
		$dispatcher = $server->get(IEventDispatcher::class);
		// Through the registry, so a later read in the same process does not
		// ask a second time.
		$registry = $server->get(DeepLinkRegistryService::class);
		$registry->requestRegistrations(dispatcher: $dispatcher);

		// Register the built-in IntegrationProvider implementations
		// with the IntegrationRegistry. The 5 wrap existing services
		// (FileService / NoteService / TaskService / system tag manager /
		// AuditTrailMapper) and surface them through the unified
		// registry contract. Each provider is constructed lazily — the
		// registry never touches a provider's wrapped service unless a
		// caller actually invokes that provider's CRUD path.
		IntegrationRegistrar::boot(server: $server);
		IntegrationRegistrar::bootLeafRegistry(server: $server);
		ObjectSourceRegistrar::boot(server: $server);
		$this->bootFederation(server: $server);

		// Allow GitHub-hosted avatars in img-src so the CnRoadmapItem
		// component can render submitter faces alongside the issues
		// surfaced by the github-issue-proxy. The browser refuses
		// `<img src="https://avatars.githubusercontent.com/...">` under
		// Nextcloud's default img-src ('self' data: blob:), leaving a
		// broken-image glyph next to every roadmap card. Allowlisting
		// the host here lifts the block instance-wide for any app that
		// proxies GitHub issues through OpenRegister.
		$this->relaxCspForGithubAvatars(server: $server);

		// Allow registering the Web Push Service Worker (openregister-web-push-engine).
		// Nextcloud's default CSP uses a nonce-based script-src, which a Service
		// Worker script cannot carry, so navigator.serviceWorker.register() is
		// rejected with "violates the Content Security Policy". Adding
		// `worker-src 'self'` lets the same-origin SW register.
		$this->relaxCspForWebPushWorker(server: $server);
		\OCA\OpenRegister\Service\WritePhaseProbe::stamp('or.boot.out');
	}//end boot()

	/**
	 * Push an `img-src https://avatars.githubusercontent.com` allowlist
	 * onto Nextcloud's default Content-Security-Policy via
	 * `IContentSecurityPolicyManager::addDefaultPolicy()`. Idempotent —
	 * NC merges policies additively, never narrowing.
	 *
	 * @param mixed $server Server container (passed in from boot()).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/features-roadmap-menu/spec.md
	 */
	private function relaxCspForGithubAvatars($server): void {
		try {
			$cspManager = $server->get(IContentSecurityPolicyManager::class);
			$policy = new ContentSecurityPolicy();
			$policy->addAllowedImageDomain('https://avatars.githubusercontent.com');
			$cspManager->addDefaultPolicy($policy);
		} catch (\Throwable $e) {
			// CSP manager unavailable (rare — would break far more than
			// avatars). Stay silent rather than fail the boot.
		}
	}//end relaxCspForGithubAvatars()

	/**
	 * Allow the same-origin Web Push Service Worker to register by adding
	 * `worker-src 'self'` to Nextcloud's default Content-Security-Policy via
	 * `IContentSecurityPolicyManager::addDefaultPolicy()`. NC's nonce-based
	 * script-src otherwise rejects `navigator.serviceWorker.register()` with a
	 * CSP violation (a SW script cannot carry a request nonce). Idempotent —
	 * NC merges policies additively, never narrowing.
	 *
	 * @param mixed $server Server container (passed in from boot()).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/web-push-delivery/spec.md
	 */
	private function relaxCspForWebPushWorker($server): void {
		try {
			$cspManager = $server->get(IContentSecurityPolicyManager::class);
			$policy = new ContentSecurityPolicy();
			$policy->addAllowedWorkerSrcDomain("'self'");
			$cspManager->addDefaultPolicy($policy);
		} catch (\Throwable $e) {
			// CSP manager unavailable (rare). Stay silent rather than fail boot.
		}
	}//end relaxCspForWebPushWorker()

	/**
	 * Register OpenRegister's OCM cloud-federation provider so cross-instance
	 * shares of registers/schemas/objects ride Nextcloud's Open Cloud Mesh
	 * transport. Guarded: if the federation stack is unavailable, OpenRegister
	 * still runs (federation simply won't be offered).
	 *
	 * @param mixed $server Server container (passed from boot()).
	 *
	 * @return void
	 */
	private function bootFederation($server): void {
		try {
			$manager = $server->get(ICloudFederationProviderManager::class);
			$manager->addCloudFederationProvider(
				'openregister',
				'OpenRegister',
				function () use ($server) {
					return $server->get(OpenRegisterCloudFederationProvider::class);
				}
			);
		} catch (\Throwable $e) {
			try {
				$server->get(\Psr\Log\LoggerInterface::class)->warning(
					'[Federation] could not register OCM provider: ' . $e->getMessage()
				);
			} catch (\Throwable $inner) {
				// Logging unavailable — never take the app down over federation.
			}
		}
	}//end bootFederation()
}//end class
