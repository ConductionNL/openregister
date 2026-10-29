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

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\AppContainerFinderInterface;
use OCA\OpenRegister\Kernel\Contracts\AuditTrailMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\RegisterMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\SchemaMapperInterface;
use OCA\OpenRegister\Mcp\BuiltIn\AttributeToolProvider;
use OCA\OpenRegister\Mcp\BuiltIn\FlowMcpToolProvider;
use OCA\OpenRegister\Mcp\BuiltIn\IntegrationsToolProvider;
use OCA\OpenRegister\Mcp\BuiltIn\ObjectsToolProvider;
use OCA\OpenRegister\Mcp\BuiltIn\RegistersToolProvider;
use OCA\OpenRegister\Mcp\BuiltIn\SchemaDerivedToolProvider;
use OCA\OpenRegister\Mcp\BuiltIn\SchemasToolProvider;
use OCA\OpenRegister\Service\Mcp\McpToolsService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

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
			function (ContainerInterface $container) {
				$logger = $container->get('Psr\Log\LoggerInterface');
				$providers = [
					$container->get(RegistersToolProvider::class),
					$container->get(SchemasToolProvider::class),
					$container->get(ObjectsToolProvider::class),
					$container->get(IntegrationsToolProvider::class),
					$container->get(FlowMcpToolProvider::class),
				];

				// Preferred path: apps announce themselves with a listener,
				// the same way they contribute a flow node and the same way
				// core's workflow engine collects operations.
				$this->collectAnnouncedMcpProviders(
					container: $container,
					logger: $logger,
					providers: $providers
				);

				// Legacy path, kept for one release so the fleet is never
				// broken mid-migration. Five apps outside OpenRegister still
				// register by container alias; each is skipped here once it
				// announces itself, so a migrated app is never collected twice.
				(new PerAppMcpProviderProbe())->collectPerAppMcpProviders(
					container: $container,
					logger: $logger,
					providers: $providers
				);

				// Schema-derived providers (ADR-063 chain 2/3) MUST be
				// appended LAST — McpToolsService is first-wins on tool
				// name, so ordering after every hand-written per-app
				// provider makes hand-written tools win automatically on a
				// collision (REQ-DERIVED-003).
				$this->collectSchemaDerivedMcpProviders(
					container: $container,
					logger: $logger,
					providers: $providers
				);

				// Attributed providers (ADR-063 chain 3/3) MUST be collected
				// LAST, after both hand-written and schema-derived providers,
				// so their collision checks see the FULL existing id set:
				// an attributed id colliding with a hand-written id is
				// self-suppressed (hand-written wins, same as derived); an
				// attributed id colliding with a DERIVED id is a discovery-time
				// error (REQ-ATTR-002) — see collectAttributeMcpProviders().
				$this->collectAttributeMcpProviders(
					container: $container,
					logger: $logger,
					providers: $providers
				);

				return new McpToolsService(
					providers: $providers,
					logger: $logger
				);
			}
		);
	}//end register()

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
	 * @param ContainerInterface $container The DI container.
	 * @param \Psr\Log\LoggerInterface $logger PSR logger.
	 * @param array<IMcpToolProvider> $providers Providers array (modified in place by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-DERIVED-001 — SchemaDerivedToolProvider emits declarative CRUD tools)
	 */
	private function collectSchemaDerivedMcpProviders(
		ContainerInterface $container,
		\Psr\Log\LoggerInterface $logger,
		array &$providers,
	): void {
		try {
			$schemaMapper = $container->get(SchemaMapperInterface::class);
			$registerMapper = $container->get(RegisterMapperInterface::class);

			// Catalog enumeration, not data access: every enabled schema
			// MUST be considered regardless of the resolving request's org
			// context, or the derived tool catalog would silently vary by
			// caller — unlike SchemasToolProvider's user-facing `list`
			// action, RBAC/multitenancy is deliberately bypassed here.
			$schemas = $schemaMapper->findAll(_rbac: false, _multitenancy: false);

			$schemasByApp = $this->groupOptedInSchemasByApp(
				schemas: $schemas,
				registerMapper: $registerMapper,
				logger: $logger
			);

			foreach ($schemasByApp as $appId => $schemaEntries) {
				$suppressedIds = $this->collectExistingToolIds(providers: $providers, appId: $appId);

				$providers[] = new SchemaDerivedToolProvider(
					appId: $appId,
					schemaEntries: $schemaEntries,
					suppressedIds: $suppressedIds,
					objectService: $container->get(ObjectService::class),
					auditTrailMapper: $container->get(AuditTrailMapperInterface::class),
					logger: $logger
				);
			}
		} catch (\Throwable $e) {
			$logger->warning(
				'[Application] Schema-derived provider enumeration failed: ' . $e->getMessage()
			);
		}//end try
	}//end collectSchemaDerivedMcpProviders()

	/**
	 * Group every schema with a validated `x-openregister-mcp.enabled:true`
	 * block by its owning app id, each paired with its owning register.
	 *
	 * Owning app id resolution mirrors {@see \OCA\OpenRegister\Service\SemanticTypeResolver::owningAppId()}:
	 * the schema's own `application` field, else the owning register's
	 * `application`. A schema that resolves to no owning app is skipped —
	 * the ABI requires a concrete `getAppId()` per provider instance.
	 *
	 * @param array<int, Schema> $schemas Every schema on the instance.
	 * @param RegisterMapperInterface<Register, Schema> $registerMapper Register mapper (owning-app + owning-register resolution).
	 * @param \Psr\Log\LoggerInterface $logger PSR logger.
	 *
	 * @return array<string, list<array{schema: Schema, register: Register|null}>> Opted-in schemas grouped by owning app id.
	 */
	private function groupOptedInSchemasByApp(
		array $schemas,
		RegisterMapperInterface $registerMapper,
		\Psr\Log\LoggerInterface $logger,
	): array {
		$schemasByApp = [];

		foreach ($schemas as $schema) {
			$configuration = ($schema->getConfiguration() ?? []);
			$annotation = ($configuration['x-openregister-mcp'] ?? null);
			if (is_array($annotation) === false || ($annotation['enabled'] ?? false) !== true) {
				continue;
			}

			$register = $this->resolveSchemaRegister(schema: $schema, registerMapper: $registerMapper, logger: $logger);
			$owningApp = $this->resolveOwningAppId(schema: $schema, register: $register);
			if ($owningApp === null) {
				$logger->debug(
					'[Application] Opted-in schema has no resolvable owning app — skipped',
					['schemaId' => $schema->getId()]
				);
				continue;
			}

			$schemasByApp[$owningApp][] = ['schema' => $schema, 'register' => $register];
		}//end foreach

		return $schemasByApp;
	}//end groupOptedInSchemasByApp()

	/**
	 * Resolve a schema's owning register (the first register whose
	 * `schemas` id-list contains it), or null when the schema is orphaned.
	 *
	 * @param Schema $schema The schema.
	 * @param RegisterMapperInterface<Register, Schema> $registerMapper Register mapper.
	 * @param \Psr\Log\LoggerInterface $logger PSR logger.
	 *
	 * @return Register|null The owning register, or null when none is found.
	 */
	private function resolveSchemaRegister(
		Schema $schema,
		RegisterMapperInterface $registerMapper,
		\Psr\Log\LoggerInterface $logger,
	): ?Register {
		try {
			$registerId = $registerMapper->getFirstRegisterWithSchema(schemaId: (int)$schema->getId());
			if ($registerId === null) {
				return null;
			}

			return $registerMapper->find(id: $registerId, _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			$logger->debug(
				'[Application] Owning-register resolution failed for schema ' . $schema->getId() . ': ' . $e->getMessage()
			);
			return null;
		}
	}//end resolveSchemaRegister()

	/**
	 * Resolve the owning app id: the schema's own `application` field
	 * (present and reliable on real fleet schemas), else the owning
	 * register's `application`. Null when neither names an app.
	 *
	 * @param Schema $schema The candidate schema.
	 * @param Register|null $register The schema's owning register, when resolvable.
	 *
	 * @return string|null The owning app id, or null when undeclared.
	 */
	private function resolveOwningAppId(Schema $schema, ?Register $register): ?string {
		$appId = $schema->getApplication();
		if (is_string($appId) === true && $appId !== '') {
			return $appId;
		}

		if ($register !== null) {
			$registerApp = $register->getApplication();
			if (is_string($registerApp) === true && $registerApp !== '') {
				return $registerApp;
			}
		}

		return null;
	}//end resolveOwningAppId()

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
	 * @param ContainerInterface $container The DI container.
	 * @param \Psr\Log\LoggerInterface $logger PSR logger.
	 * @param array<IMcpToolProvider> $providers Providers array (modified in place by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-ATTR-002 — Reflection scanner registers attributed tools in the same catalog)
	 */
	private function collectAttributeMcpProviders(
		ContainerInterface $container,
		\Psr\Log\LoggerInterface $logger,
		array &$providers,
	): void {
		try {
			$appManager = $container->get('OCP\App\IAppManager');
			$scanner = new AttributeToolScanner();

			foreach ($appManager->getInstalledApps() as $appId) {
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
				$appContainer = $this->getRegisteredAppContainer(appId: $appId, container: $container);
				if ($appContainer === null) {
					continue;
				}

				$classNames = $this->resolveScannableServiceClasses(
					appContainer: $appContainer,
					logger: $logger,
					appId: $appId
				);

				if ($classNames === []) {
					continue;
				}

				$descriptors = $scanner->scanClasses(appId: $appId, classNames: $classNames, logger: $logger);
				if ($descriptors === []) {
					continue;
				}

				$entries = $this->resolveAttributeEntries(
					appContainer: $appContainer,
					logger: $logger,
					appId: $appId,
					descriptors: $descriptors,
					providers: $providers
				);

				if ($entries === []) {
					continue;
				}

				$providers[] = new AttributeToolProvider(
					appId: $appId,
					entries: $entries,
					auditTrailMapper: $container->get(AuditTrailMapperInterface::class),
					logger: $logger
				);
			}//end foreach
		} catch (\Throwable $e) {
			$logger->warning(
				'[Application] Attributed-tool provider enumeration failed: ' . $e->getMessage()
			);
		}//end try
	}//end collectAttributeMcpProviders()

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
	 * @param ContainerInterface $appContainer The opting-in app's own DI container.
	 * @param \Psr\Log\LoggerInterface $logger PSR logger.
	 * @param string $appId The candidate app id.
	 *
	 * @return list<string> Candidate scannable service FQCNs (possibly empty).
	 */
	private function resolveScannableServiceClasses(
		ContainerInterface $appContainer,
		\Psr\Log\LoggerInterface $logger,
		string $appId,
	): array {
		$key = 'OCA\\OpenRegister\\Mcp\\IMcpScannableServices::' . $appId;

		try {
			if ($appContainer->has($key) === false) {
				return [];
			}

			$declaration = $appContainer->get($key);
		} catch (\Throwable $e) {
			$logger->debug(
				'[Application] Scannable-services alias resolve failed',
				['appId' => $appId, 'error' => $e->getMessage()]
			);
			return [];
		}

		if (($declaration instanceof IMcpScannableServices) === false) {
			return [];
		}

		return $declaration->getScannableServiceClasses();
	}//end resolveScannableServiceClasses()

	/**
	 * Resolve every scanned descriptor for one app to an invocable entry
	 * (attaching the owning app's own DI-resolved service instance —
	 * ADR-041), applying the collision policy documented on
	 * {@see collectAttributeMcpProviders()}.
	 *
	 * The service instance is resolved from the owning app's OWN container
	 * (#390) — the scanned class and its dependencies are only reliably
	 * wireable there, not in OR's shared container.
	 *
	 * @param ContainerInterface $appContainer The owning app's own DI container.
	 * @param \Psr\Log\LoggerInterface $logger PSR logger.
	 * @param string $appId The owning app id.
	 * @param array<int, array<string, mixed>> $descriptors Scanned descriptors (see AttributeToolScanner).
	 * @param array<IMcpToolProvider> $providers Providers collected so far (for collision checks).
	 *
	 * @return list<array<string, mixed>> Invocable entries (descriptor + resolved `instance`).
	 */
	private function resolveAttributeEntries(
		ContainerInterface $appContainer,
		\Psr\Log\LoggerInterface $logger,
		string $appId,
		array $descriptors,
		array $providers,
	): array {
		$derivedIds = [];
		$otherIds = [];

		foreach ($providers as $provider) {
			if ($provider->getAppId() !== $appId) {
				continue;
			}

			$ids = array_column($provider->getTools(), 'id');
			if ($provider instanceof SchemaDerivedToolProvider) {
				$derivedIds = array_merge($derivedIds, $ids);
				continue;
			}

			$otherIds = array_merge($otherIds, $ids);
		}

		$entries = [];

		foreach ($descriptors as $descriptor) {
			$id = (string)$descriptor['id'];

			if (in_array($id, $derivedIds, true) === true) {
				$logger->error(
					'[Application] Attributed tool id collides with a schema-derived tool id — rejected',
					['appId' => $appId, 'toolId' => $id]
				);
				continue;
			}

			if (in_array($id, $otherIds, true) === true) {
				// Hand-written-wins precedence — silent self-suppression,
				// same as SchemaDerivedToolProvider's REQ-DERIVED-003.
				continue;
			}

			try {
				$descriptor['instance'] = $appContainer->get($descriptor['class']);
			} catch (\Throwable $e) {
				$logger->warning(
					'[Application] Could not resolve attributed tool service instance',
					[
						'appId' => $appId,
						'class' => $descriptor['class'],
						'error' => $e->getMessage(),
					]
				);
				continue;
			}

			$entries[] = $descriptor;
		}//end foreach

		return $entries;
	}//end resolveAttributeEntries()

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
	 * @param ContainerInterface $container The DI container.
	 * @param \Psr\Log\LoggerInterface $logger PSR logger.
	 * @param array<IMcpToolProvider> $providers Providers array, modified in place.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/or-mcp-registration-event/specs/mcp-discovery/spec.md
	 */
	private function collectAnnouncedMcpProviders(
		ContainerInterface $container,
		\Psr\Log\LoggerInterface $logger,
		array &$providers,
	): void {
		try {
			$dispatcher = $container->get(\OCP\EventDispatcher\IEventDispatcher::class);
			$event = new RegisterMcpToolProvidersEvent();
			$dispatcher->dispatchTyped($event);

			$seen = [];
			foreach ($providers as $existing) {
				$seen[$existing->getAppId()] = true;
			}

			foreach ($event->getProviders() as $provider) {
				$appId = $provider->getAppId();
				if (isset($seen[$appId]) === true) {
					continue;
				}

				$seen[$appId] = true;
				$providers[] = $provider;
			}
		} catch (\Throwable $e) {
			// Discovery must never take the container down: an app with a
			// broken listener costs its own tools, not everyone else's.
			$logger->warning(
				message: '[MCP] Announced-provider collection failed',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
		}//end try

	}//end collectAnnouncedMcpProviders()
}//end class
