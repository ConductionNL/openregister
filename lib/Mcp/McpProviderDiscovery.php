<?php

/**
 * Discovers the MCP tool providers and registers McpToolsService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\AppContainerFinderInterface;
use OCA\OpenRegister\Mcp\BuiltIn\AttributeToolProvider;
use OCA\OpenRegister\Mcp\BuiltIn\FlowMcpToolProvider;
use OCA\OpenRegister\Mcp\BuiltIn\IntegrationsToolProvider;
use OCA\OpenRegister\Mcp\BuiltIn\ObjectsToolProvider;
use OCA\OpenRegister\Mcp\BuiltIn\RegistersToolProvider;
use OCA\OpenRegister\Mcp\BuiltIn\SchemaDerivedToolProvider;
use OCA\OpenRegister\Mcp\BuiltIn\SchemasToolProvider;
use OCA\OpenRegister\Mcp\Service\Mcp\McpToolsService;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;
use OCA\OpenRegister\Mcp\BuiltIn\SchemaDerivedToolServices;

/**
 * Registers McpToolsService with every MCP tool provider it can find: the
 * built-in providers, the providers apps announce, the per-app aliases,
 * the schema-derived providers and the attributed service methods.
 *
 * Application::register() calls register() once.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A registrar names every service it wires.
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)  Factory closures list every constructor argument.
 */
class McpProviderDiscovery {

	/**
	 * Register MCP tool providers (built-ins first).
	 *
	 * Wires the four built-in IMcpToolProvider implementations into
	 * McpToolsService. External apps may call addProvider() after boot
	 * or override the McpToolsService binding to prepend their own providers.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/chat-ai/spec.md#requirement-mcptoolsservice-provider-discovery-refactor
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerService(
			McpToolsService::class,
			fn (ContainerInterface $container): McpToolsService => $this->buildToolsService(container: $container)
		);
	}//end register()

	/**
	 * Build the tools service with every provider, in the order that decides collisions.
	 *
	 * @param ContainerInterface $container The shared container.
	 *
	 * @return McpToolsService
	 */
	private function buildToolsService(ContainerInterface $container): McpToolsService {
		$logger = $container->get('Psr\Log\LoggerInterface');
		$run = new McpDiscoveryRun(container: $container, logger: $logger);
		$run->addAll(providers: $this->builtInProviders(container: $container));

		// Preferred path: apps announce themselves with a listener,
		// the same way they contribute a flow node and the same way
		// core's workflow engine collects operations.
		$this->collectAnnouncedMcpProviders(run: $run);

		// Legacy path, kept for one release so the fleet is never
		// broken mid-migration. Five apps outside OpenRegister still
		// register by container alias; each is skipped here once it
		// announces itself, so a migrated app is never collected twice.
		(new PerAppMcpProviderProbe())->collectPerAppMcpProviders(run: $run);

		// Schema-derived providers (ADR-063 chain 2/3) MUST be
		// appended LAST — McpToolsService is first-wins on tool
		// name, so ordering after every hand-written per-app
		// provider makes hand-written tools win automatically on a
		// collision (REQ-DERIVED-003).
		$this->collectSchemaDerivedMcpProviders(run: $run);

		// Attributed providers (ADR-063 chain 3/3) MUST be collected
		// LAST, after both hand-written and schema-derived providers,
		// so their collision checks see the FULL existing id set:
		// an attributed id colliding with a hand-written id is
		// self-suppressed (hand-written wins, same as derived); an
		// attributed id colliding with a DERIVED id is a discovery-time
		// error (REQ-ATTR-002) — see collectAttributeMcpProviders().
		$this->collectAttributeMcpProviders(run: $run);

		return new McpToolsService(
			providers: $run->providers(),
			logger: $logger
		);
	}//end buildToolsService()

	/**
	 * The built-in providers, in their fixed order.
	 *
	 * @param ContainerInterface $container The shared container.
	 *
	 * @return list<IMcpToolProvider>
	 */
	private function builtInProviders(ContainerInterface $container): array {
		return [
			$container->get(RegistersToolProvider::class),
			$container->get(SchemasToolProvider::class),
			$container->get(ObjectsToolProvider::class),
			$container->get(IntegrationsToolProvider::class),
			$container->get(FlowMcpToolProvider::class),
		];
	}//end builtInProviders()

	/**
	 * Derive one SchemaDerivedToolProvider per owning app that has at least
	 * one schema with a validated `x-openregister-mcp.enabled:true` block,
	 * and append it to $providers AFTER every hand-written provider already
	 * collected (built-ins + collectPerAppMcpProviders()).
	 *
	 * Fail-soft: enumeration or resolution failures are logged and leave
	 * $providers untouched — a broken derivation must never break MCP tool
	 * discovery for the rest of the instance.
	 *
	 * @param McpDiscoveryRun $run The discovery: container, logger and the providers collected so far.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-DERIVED-001 — SchemaDerivedToolProvider emits declarative CRUD tools)
	 */
	private function collectSchemaDerivedMcpProviders(McpDiscoveryRun $run): void {
		try {
			$this->addDerivedProviders(run: $run, schemasByApp: $this->optedInSchemasByApp(run: $run));
		} catch (\Throwable $e) {
			$run->logger->warning(
				'[Application] Schema-derived provider enumeration failed: ' . $e->getMessage()
			);
		}
	}//end collectSchemaDerivedMcpProviders()

	/**
	 * Every schema that opts in to derived MCP tools, grouped by owning app.
	 *
	 * @param McpDiscoveryRun $run The discovery: container, logger and the providers collected so far.
	 *
	 * @return array<string, array> The opted-in schema entries per app id.
	 */
	private function optedInSchemasByApp(McpDiscoveryRun $run): array {
		$schemaMapper = $run->container->get(SchemaMapperInterface::class);
		$ownership = new McpSchemaOwnership(run: $run);

		// Catalog enumeration, not data access: every enabled schema
		// MUST be considered regardless of the resolving request's org
		// context, or the derived tool catalog would silently vary by
		// caller — unlike SchemasToolProvider's user-facing `list`
		// action, RBAC/multitenancy is deliberately bypassed here.
		$schemas = $schemaMapper->findAll(_rbac: false, _multitenancy: false);

		return $ownership->groupOptedInSchemasByApp(schemas: $schemas);
	}//end optedInSchemasByApp()

	/**
	 * Append one SchemaDerivedToolProvider per app, suppressing the tool ids its app already exposes.
	 *
	 * @param McpDiscoveryRun $run          The discovery; receives the providers.
	 * @param array           $schemasByApp The opted-in schema entries per app id.
	 *
	 * @return void
	 */
	private function addDerivedProviders(McpDiscoveryRun $run, array $schemasByApp): void {
		foreach ($schemasByApp as $appId => $schemaEntries) {
			$suppressedIds = $this->collectExistingToolIds(providers: $run->providers(), appId: $appId);

			$run->add(provider: new SchemaDerivedToolProvider(
				appId: $appId,
				schemaEntries: $schemaEntries,
				suppressedIds: $suppressedIds,
				services: $this->derivedServices(run: $run),
			));
		}
	}//end addDerivedProviders()

	/**
	 * The services a schema-derived provider runs its tools with.
	 *
	 * @param McpDiscoveryRun $run The discovery: container and logger.
	 *
	 * @return SchemaDerivedToolServices
	 */
	private function derivedServices(McpDiscoveryRun $run): SchemaDerivedToolServices {
		return new SchemaDerivedToolServices(
			objectService: $run->container->get(ObjectOperationsInterface::class),
			auditTrailMapper: $run->container->get(AuditTrailMapperInterface::class),
			logger: $run->logger,
		);
	}//end derivedServices()

	/**
	 * Collect every tool id already exposed, on the providers list built so
	 * far, by a provider for the given app id — the derived provider's
	 * self-suppression set (REQ-DERIVED-003).
	 *
	 * @param array<IMcpToolProvider> $providers Providers collected so far.
	 * @param string $appId The owning app id.
	 *
	 * @return list<string> Tool ids already claimed for this app.
	 */
	private function collectExistingToolIds(array $providers, string $appId): array {
		$ids = [];

		foreach ($providers as $provider) {
			if ($provider->getAppId() !== $appId) {
				continue;
			}

			foreach ($provider->getTools() as $descriptor) {
				if (isset($descriptor['id']) === true) {
					$ids[] = (string)$descriptor['id'];
				}
			}
		}

		return $ids;
	}//end collectExistingToolIds()

	/**
	 * Derive one AttributeToolProvider per app that declares at least one
	 * `#[McpTool]`-attributed method on its own scannable service classes
	 * (ADR-063 chain 3/3), and append it to $providers.
	 *
	 * MUST run LAST (after collectPerAppMcpProviders() and
	 * collectSchemaDerivedMcpProviders()) so its collision check sees every
	 * hand-written AND derived id already claimed for that app:
	 * - An attributed id colliding with a hand-written id is self-suppressed
	 *   (silent — hand-written wins, same precedence as the derived provider).
	 * - An attributed id colliding with a schema-DERIVED id is a
	 *   developer-facing discovery-time error: the ambiguous attributed tool
	 *   is rejected (logged, skipped) rather than silently resolved
	 *   (REQ-ATTR-002).
	 *
	 * Discovery scope (which classes get reflected) is the provisional
	 * resolution of design.md's DEFERRED_QUESTION: an app opts in by
	 * registering an {@see IMcpScannableServices} implementation under the
	 * alias key `OCA\OpenRegister\Mcp\IMcpScannableServices::<appId>` —
	 * mirrors the existing per-app `IMcpToolProvider::<appId>` convention
	 * used by collectPerAppMcpProviders(). Apps that register nothing under
	 * that key are simply not scanned (opt-in, not enumerate-everything).
	 *
	 * Fail-soft: enumeration, reflection, or resolution failures are logged
	 * and leave $providers untouched for the affected app — a broken scan
	 * must never break MCP tool discovery for the rest of the instance.
	 *
	 * @param McpDiscoveryRun $run The discovery: container, logger and the providers collected so far.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-ATTR-002 — Reflection scanner registers attributed tools in the same catalog)
	 */
	private function collectAttributeMcpProviders(McpDiscoveryRun $run): void {
		try {
			$appManager = $run->container->get('OCP\App\IAppManager');

			foreach ($appManager->getInstalledApps() as $appId) {
				$provider = $this->attributeProviderFor(run: $run, appId: $appId);
				if ($provider !== null) {
					$run->add(provider: $provider);
				}
			}
		} catch (\Throwable $e) {
			$run->logger->warning(
				'[Application] Attributed-tool provider enumeration failed: ' . $e->getMessage()
			);
		}//end try
	}//end collectAttributeMcpProviders()

	/**
	 * The attributed-tool provider of one installed app, or null when it opts
	 * in to nothing usable.
	 *
	 * @param McpDiscoveryRun $run   The discovery: shared container, logger and the providers collected so far.
	 * @param string          $appId The installed app.
	 *
	 * @return AttributeToolProvider|null
	 */
	private function attributeProviderFor(McpDiscoveryRun $run, string $appId): ?AttributeToolProvider {
		$app = $this->leafApp(run: $run, appId: $appId);
		if ($app === null) {
			return null;
		}

		$entries = $this->attributeEntries(run: $run, app: $app);
		if ($entries === []) {
			return null;
		}

		return (new AttributeToolProvider(
			appId: $appId,
			entries: $entries,
			auditTrailMapper: $run->container->get(AuditTrailMapperInterface::class),
			logger: $run->logger
		))->translatedBy(appContainer: $app->container);
	}//end attributeProviderFor()

	/**
	 * The installed app with its own DI container; null when it has none.
	 *
	 * @param McpDiscoveryRun $run   The discovery: shared container and logger.
	 * @param string          $appId The installed app.
	 *
	 * @return McpLeafApp|null
	 */
	private function leafApp(McpDiscoveryRun $run, string $appId): ?McpLeafApp {
		// The `IMcpScannableServices::<appId>` opt-in alias — and the
		// scanned service class itself (ADR-041) — live in the opting-in
		// app's OWN DI container, NOT OR's shared container: a leaf app
		// registers them via $context->registerServiceAlias() in its own
		// Application::register(), which binds into that app's
		// \OC\AppFramework\DependencyInjection\DIContainer. Resolving them
		// off the shared $container silently returns nothing (#390), so
		// both alias and instance resolution must go through the app's
		// own container. Fail-soft: an app with no registered container
		// (or a container whose resolution throws) is simply skipped.
		$appContainer = $this->getRegisteredAppContainer(appId: $appId, container: $run->container);
		if ($appContainer === null) {
			return null;
		}

		return new McpLeafApp(appId: $appId, container: $appContainer, logger: $run->logger);
	}//end leafApp()

	/**
	 * The invocable attributed-tool entries of one app that survive the collision policy.
	 *
	 * @param McpDiscoveryRun $run The discovery: the providers collected so far.
	 * @param McpLeafApp      $app The app and its own container.
	 *
	 * @return list<array<string, mixed>> The entries; none when the app declares nothing usable.
	 */
	private function attributeEntries(McpDiscoveryRun $run, McpLeafApp $app): array {
		$descriptors = $this->scanAttributedTools(app: $app);
		if ($descriptors === []) {
			return [];
		}

		return $this->resolveAttributeEntries(
			app: $app,
			descriptors: $run->withoutCollidingDescriptors(appId: $app->appId, descriptors: $descriptors)
		);
	}//end attributeEntries()

	/**
	 * The attributed-tool descriptors of an app's declared scannable services.
	 *
	 * @param McpLeafApp $app The app, its own container and the logger.
	 *
	 * @return array<int, array<string, mixed>> The descriptors; none when nothing is declared.
	 */
	private function scanAttributedTools(McpLeafApp $app): array {
		$classNames = $this->resolveScannableServiceClasses(app: $app);

		if ($classNames === []) {
			return [];
		}

		return (new AttributeToolScanner())->scanClasses(app: $app, classNames: $classNames);
	}//end scanAttributedTools()

	/**
	 * Obtain the opting-in app's OWN DI container so its
	 * `IMcpScannableServices::<appId>` opt-in alias (and the attributed
	 * service instances) can be resolved from where the leaf app actually
	 * registered them (#390).
	 *
	 * Asks the app container finder bound in OR's container. The finder is
	 * fail-soft: an app without a bootstrapped container yields null, so a
	 * single misbehaving app never fatals the whole MCP catalog. Isolated
	 * behind its own method so unit tests can override the app-container
	 * topology.
	 *
	 * @param string $appId The candidate app id.
	 * @param ContainerInterface $container OR's container, which binds the finder.
	 *
	 * @return ContainerInterface|null The app's own container, or null when unavailable.
	 *
	 * @spec openspec/specs/apphost-observability/spec.md#requirement-a-leaf-apps-provider-must-be-resolved-from-that-apps-own-container
	 */
	protected function getRegisteredAppContainer(
		string $appId,
		ContainerInterface $container,
	): ?ContainerInterface {
		return $container->get(AppContainerFinderInterface::class)->find(appId: $appId);
	}//end getRegisteredAppContainer()

	/**
	 * Resolve one app's declared scannable service classes via the
	 * `IMcpScannableServices::<appId>` alias registered in THAT APP'S OWN
	 * container, or an empty list when the app has not opted in / the alias
	 * fails to resolve.
	 *
	 * The alias is registered by the leaf app in its own
	 * Application::register() and therefore lives in the app's own
	 * \OC\AppFramework\DependencyInjection\DIContainer — never in OR's shared
	 * container. Resolving it off the shared container silently discovered
	 * nothing (#390); {@see collectAttributeMcpProviders()} passes the
	 * per-app container obtained from {@see getRegisteredAppContainer()}.
	 *
	 * @param McpLeafApp $app The opting-in app and its own DI container.
	 *
	 * @return list<string> Candidate scannable service FQCNs (possibly empty).
	 */
	private function resolveScannableServiceClasses(McpLeafApp $app): array {
		$key = 'OCA\\OpenRegister\\Mcp\\IMcpScannableServices::' . $app->appId;

		try {
			if ($app->container->has($key) === false) {
				return [];
			}

			$declaration = $app->container->get($key);
		} catch (\Throwable $e) {
			$app->logger->debug(
				'[Application] Scannable-services alias resolve failed',
				['appId' => $app->appId, 'error' => $e->getMessage()]
			);
			return [];
		}

		if (($declaration instanceof IMcpScannableServices) === false) {
			return [];
		}

		return $declaration->getScannableServiceClasses();
	}//end resolveScannableServiceClasses()

	/**
	 * Resolve every kept descriptor for one app to an invocable entry
	 * (attaching the owning app's own DI-resolved service instance —
	 * ADR-041).
	 *
	 * The service instance is resolved from the owning app's OWN container
	 * (#390) — the scanned class and its dependencies are only reliably
	 * wireable there, not in OR's shared container.
	 *
	 * @param McpLeafApp $app The owning app and its own DI container.
	 * @param array<int, array<string, mixed>> $descriptors Descriptors that survived the collision policy.
	 *
	 * @return list<array<string, mixed>> Invocable entries (descriptor + resolved `instance`).
	 */
	private function resolveAttributeEntries(McpLeafApp $app, array $descriptors): array {
		$entries = [];

		foreach ($descriptors as $descriptor) {
			$entry = $this->withInstance(app: $app, descriptor: $descriptor);
			if ($entry !== null) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}//end resolveAttributeEntries()

	/**
	 * The descriptor with its service instance from the app's own container; null (logged) when it cannot be resolved.
	 *
	 * @param McpLeafApp           $app        The owning app and its own DI container.
	 * @param array<string, mixed> $descriptor The kept descriptor.
	 *
	 * @return array<string, mixed>|null
	 */
	private function withInstance(McpLeafApp $app, array $descriptor): ?array {
		try {
			$descriptor['instance'] = $app->container->get($descriptor['class']);
		} catch (\Throwable $e) {
			$app->logger->warning(
				'[Application] Could not resolve attributed tool service instance',
				[
					'appId' => $app->appId,
					'class' => $descriptor['class'],
					'error' => $e->getMessage(),
				]
			);
			return null;
		}

		return $descriptor;
	}//end withInstance()

	/**
	 * Collect providers from apps that announce themselves with a listener.
	 *
	 * One dispatch, no `info.xml` parsing, no candidate aliases, no container
	 * probing and nothing to cache — the whole apparatus the alias scan needed
	 * exists because it looked for something apps never declared.
	 *
	 * A provider whose appId is already present is skipped, which is what makes
	 * running both paths during the migration safe: an app that has added its
	 * listener is not also collected by the alias scan.
	 *
	 * @param McpDiscoveryRun $run The discovery: container, logger and the providers collected so far.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/or-mcp-registration-event/specs/mcp-discovery/spec.md
	 */
	private function collectAnnouncedMcpProviders(McpDiscoveryRun $run): void {
		try {
			$dispatcher = $run->container->get(\OCP\EventDispatcher\IEventDispatcher::class);
			$event = new RegisterMcpToolProvidersEvent();
			$dispatcher->dispatchTyped($event);

			$this->addUnseenProviders(run: $run, providers: $event->getProviders());
		} catch (\Throwable $e) {
			// Discovery must never take the container down: an app with a
			// broken listener costs its own tools, not everyone else's.
			$run->logger->warning(
				message: '[MCP] Announced-provider collection failed',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
		}//end try

	}//end collectAnnouncedMcpProviders()

	/**
	 * Append each announced provider whose app id is not collected yet, first one wins.
	 *
	 * @param McpDiscoveryRun        $run       The discovery; receives the providers.
	 * @param list<IMcpToolProvider> $providers The announced providers, in announcement order.
	 *
	 * @return void
	 */
	private function addUnseenProviders(McpDiscoveryRun $run, array $providers): void {
		$seen = [];
		foreach ($run->providers() as $existing) {
			$seen[$existing->getAppId()] = true;
		}

		foreach ($providers as $provider) {
			$appId = $provider->getAppId();
			if (isset($seen[$appId]) === false) {
				$seen[$appId] = true;
				$run->add(provider: $provider);
			}
		}
	}//end addUnseenProviders()
}//end class
