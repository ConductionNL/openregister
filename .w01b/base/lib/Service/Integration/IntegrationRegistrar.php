<?php

/**
 * The integration registry registrations of OpenRegister.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

use OCA\OpenRegister\Controller\IntegrationsController;
use OCA\OpenRegister\Controller\ObjectIntegrationsController;
use OCA\OpenRegister\Repair\LogDanglingLinkedTypes;
use OCA\OpenRegister\Service\Integration\BuiltinProviders\AuditTrailProvider;
use OCA\OpenRegister\Service\Integration\BuiltinProviders\FilesProvider;
use OCA\OpenRegister\Service\Integration\BuiltinProviders\NotesProvider;
use OCA\OpenRegister\Service\Integration\BuiltinProviders\TagsProvider;
use OCA\OpenRegister\Service\Integration\BuiltinProviders\TasksProvider;
use OCA\OpenRegister\Service\Integration\Providers\BookmarksProvider;
use OCA\OpenRegister\Service\Integration\Providers\CalendarProvider;
use OCA\OpenRegister\Service\Integration\Providers\ContactsProvider;
use OCA\OpenRegister\Service\Integration\Providers\DeckProvider;
use OCA\OpenRegister\Service\Integration\Providers\EmailProvider;
use OCA\OpenRegister\Service\Integration\Providers\KvkProvider;
use OCA\OpenRegister\Service\Integration\Providers\OpenCorporatesProvider;
use OCA\OpenRegister\Service\Integration\Providers\OpenProjectProvider;
use OCA\OpenRegister\Service\Integration\Providers\PollsProvider;
use OCA\OpenRegister\Service\Integration\Providers\SharesProvider;
use OCA\OpenRegister\Service\Integration\Providers\TalkProvider;
use OCA\OpenRegister\Service\Integration\Providers\XwikiProvider;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/**
 * Registers the integration registry, the external integration router, the
 * built-in integration providers and the services around them, and adds the
 * providers and the leaf loader to the registry at boot.
 *
 * Application::register() calls register() once; Application::boot() calls boot() once.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A registrar names every service it wires.
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)  Factory closures list every constructor argument.
 */
final class IntegrationRegistrar {

	/**
	 * Register the IntegrationRegistry + ExternalIntegrationRouter
	 * services used by the pluggable integration registry.
	 *
	 * Both are shared per-request singletons. Apps that ship their own
	 * IntegrationProvider implementations register them via
	 * `$this->container->get(IntegrationRegistry::class)->addProvider(...)`
	 * from their own Application::register() hook — see
	 * `openspec/changes/pluggable-integration-registry/proposal.md` (AD-1).
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public static function register(IRegistrationContext $context): void {
		$context->registerService(
			IntegrationRegistry::class,
			function (ContainerInterface $container) {
				return new IntegrationRegistry(
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		$context->registerService(
			ExternalIntegrationRouter::class,
			function (ContainerInterface $container) {
				return new ExternalIntegrationRouter(
					appManager: $container->get('OCP\App\IAppManager'),
					container: $container,
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		// PropertyReferenceTypeValidator — consumed by schema-property
		// validation paths that opt in to the new referenceType marker
		// (AD-18). Stays separate from the entity-level
		// validateLinkedTypesValue path so existing schemas keep
		// validating exactly as before.
		$context->registerService(
			PropertyReferenceTypeValidator::class,
			function (ContainerInterface $container) {
				return new PropertyReferenceTypeValidator(
					registry: $container->get(IntegrationRegistry::class),
				);
			}
		);

		// PropertySemanticReferenceValidator — validates the opt-in
		// `referenceSemanticType` property marker (ADR-048, cross-app
		// semantic references) as a well-formed absolute IRI. Standalone,
		// mirroring PropertyReferenceTypeValidator; wire into schema-save
		// when write-time enforcement is desired.
		$context->registerService(
			PropertySemanticReferenceValidator::class,
			function (ContainerInterface $container) {
				return new PropertySemanticReferenceValidator(
					jsonLd: $container->get(\OCA\OpenRegister\Service\JsonLd\JsonLdContextService::class),
				);
			}
		);

		// Repair step LogDanglingLinkedTypes — runs on install +
		// post-migration to surface schemas whose linkedTypes contain
		// ids that the registry can no longer resolve. Strictly
		// informational; never throws, never modifies data.
		$context->registerService(
			LogDanglingLinkedTypes::class,
			function (ContainerInterface $container) {
				return new LogDanglingLinkedTypes(
					registry: $container->get(IntegrationRegistry::class),
					container: $container,
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		// LeafRegistry — the cross-app leaf catalogue (ADR-066). Collects the
		// leaves sibling apps contribute through RegisterLeafProvidersEvent,
		// lazily on first read, and lands their data providers on the shared
		// IntegrationRegistry so existing per-object routing reaches them.
		$context->registerService(
			LeafRegistry::class,
			function (ContainerInterface $container) {
				return new LeafRegistry(
					eventDispatcher: $container->get(\OCP\EventDispatcher\IEventDispatcher::class),
					integrationRegistry: $container->get(IntegrationRegistry::class),
					appManager: $container->get('OCP\App\IAppManager'),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		self::registerBuiltinIntegrationProviders(context: $context);

		// IntegrationsController — read-only API over the registry.
		$context->registerService(
			IntegrationsController::class,
			function (ContainerInterface $container) {
				return new IntegrationsController(
					appName: 'openregister',
					request: $container->get('OCP\IRequest'),
					registry: $container->get(IntegrationRegistry::class),
					userSession: $container->get('OCP\IUserSession'),
					groupManager: $container->get('OCP\IGroupManager'),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		// ObjectIntegrationsController — object-scoped sub-resource
		// dispatch through the registry.
		$context->registerService(
			ObjectIntegrationsController::class,
			function (ContainerInterface $container) {
				return new ObjectIntegrationsController(
					appName: 'openregister',
					request: $container->get('OCP\IRequest'),
					registry: $container->get(IntegrationRegistry::class),
					logger: $container->get('Psr\Log\LoggerInterface'),
					objectService: $container->get(\OCA\OpenRegister\Service\ObjectService::class),
					schemaMapper: $container->get(\OCA\OpenRegister\Db\SchemaMapper::class),
					visibility: $container->get(\OCA\OpenRegister\Service\TimelineVisibilityService::class)
				);
			}
		);
	}//end register()

	/**
	 * Register the integration providers and link services listed in
	 * BuiltinIntegrationServices so they resolve lazily from the container.
	 *
	 * The providers self-register with the IntegrationRegistry during
	 * `boot()` through `bootBuiltinIntegrationProviders()`.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) BuiltinIntegrationServices is a constant table; build() reads it and holds no state.
	 */
	private static function registerBuiltinIntegrationProviders(IRegistrationContext $context): void {
		foreach (array_keys(BuiltinIntegrationServices::FACTORIES) as $class) {
			$context->registerService(
				$class,
				static function (ContainerInterface $container) use ($class) {
					return BuiltinIntegrationServices::build(container: $container, class: $class);
				}
			);
		}
	}//end registerBuiltinIntegrationProviders()

	/**
	 * Resolve every BuiltinProviders/* class and register it with the
	 * shared IntegrationRegistry.
	 *
	 * Kept separate from the DI registration in
	 * `registerIntegrationRegistry()` because addProvider() needs the
	 * registry instance — i.e. it has to run after the container has
	 * fully bootstrapped. `boot()` is the canonical post-registration
	 * hook for that.
	 *
	 * @param mixed $server Server container (passed in from boot()).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public static function boot($server): void {
		try {
			$integrationRegistry = $server->get(
				IntegrationRegistry::class
			);
		} catch (\Throwable $e) {
			// Registry binding not available — skip silently; the
			// service would log its own warning at use-time anyway.
			return;
		}

		$providerClasses = [
			FilesProvider::class,
			NotesProvider::class,
			TasksProvider::class,
			TagsProvider::class,
			AuditTrailProvider::class,
			// Leaves: external (OpenConnector-backed).
			// @spec openspec/specs/integration-xwiki/spec.md (canonical spec).
			XwikiProvider::class,
			// @spec openspec/specs/integration-openproject/spec.md (canonical spec).
			OpenProjectProvider::class,
			// @spec openspec/specs/integration-registry/spec.md (canonical spec).
			KvkProvider::class,
			OpenCorporatesProvider::class,
			// Leaves: NC-native, backend-shipped (wrap existing OR services).
			// @spec openspec/specs/integration-calendar/spec.md (canonical spec).
			CalendarProvider::class,
			// @spec openspec/specs/integration-contacts/spec.md (canonical spec).
			ContactsProvider::class,
			// @spec openspec/specs/integration-deck/spec.md (canonical spec).
			DeckProvider::class,
			// @spec openspec/specs/integration-email/spec.md (canonical spec).
			EmailProvider::class,
			// Leaves: NC-app-backed greenfield (registry surface only;
			// service + link table land in per-leaf follow-ups).
			// @spec openspec/specs/integration-activity/spec.md (canonical spec).
			\OCA\OpenRegister\Service\Integration\Providers\ActivityProvider::class,
			// @spec openspec/specs/integration-analytics/spec.md (canonical spec).
			\OCA\OpenRegister\Service\Integration\Providers\AnalyticsProvider::class,
			// @spec openspec/specs/integration-bookmarks/spec.md (canonical spec).
			BookmarksProvider::class,
			// @spec openspec/specs/integration-collectives/spec.md (canonical spec).
			\OCA\OpenRegister\Service\Integration\Providers\CollectivesProvider::class,
			// @spec openspec/specs/integration-cospend/spec.md (canonical spec).
			\OCA\OpenRegister\Service\Integration\Providers\CospendProvider::class,
			// @spec openspec/specs/integration-flow/spec.md (canonical spec).
			\OCA\OpenRegister\Service\Integration\Providers\FlowProvider::class,
			// @spec openspec/specs/integration-forms/spec.md (canonical spec).
			\OCA\OpenRegister\Service\Integration\Providers\FormsProvider::class,
			// @spec openspec/specs/integration-maps/spec.md (canonical spec).
			\OCA\OpenRegister\Service\Integration\Providers\MapsProvider::class,
			// @spec openspec/specs/integration-photos/spec.md (canonical spec).
			\OCA\OpenRegister\Service\Integration\Providers\PhotosProvider::class,
			// @spec openspec/specs/integration-polls/spec.md (canonical spec).
			PollsProvider::class,
			// @spec openspec/specs/integration-shares/spec.md (canonical spec).
			SharesProvider::class,
			// @spec openspec/specs/integration-talk/spec.md (canonical spec).
			TalkProvider::class,
			// @spec openspec/specs/integration-time-tracker/spec.md (canonical spec).
			\OCA\OpenRegister\Service\Integration\Providers\TimeProvider::class,
		];

		// Resolve a logger up-front so a mis-wired factory closure or an
		// absent wrapped service is visible in the logs instead of a
		// silently-missing tab. Guarded so a logger-less build still boots.
		try {
			$logger = $server->get(\Psr\Log\LoggerInterface::class);
		} catch (\Throwable $e) {
			$logger = null;
		}

		foreach ($providerClasses as $providerClass) {
			try {
				$provider = $server->get($providerClass);
				$integrationRegistry->addProvider($provider);
			} catch (\Throwable $e) {
				// Provider construction can fail if a wrapped service
				// is missing on this NC build, or — as the live-NC E2E
				// surfaced — if the DI factory closure was not updated
				// for a Tier-2 constructor arg. Don't take the whole app
				// down for one absent provider; the failing tab simply
				// won't render. But log loudly so a mis-wired factory is
				// diagnosable rather than silently swallowed.
				if ($logger !== null) {
					$logger->warning(
						'OpenRegister: integration provider failed to construct and was dropped from the registry: {provider} — {message}',
						[
							'provider' => $providerClass,
							'message' => $e->getMessage(),
							'exception' => $e,
						]
					);
				}
			}//end try
		}//end foreach
	}//end boot()

	/**
	 * Install the lazy leaf loader on the shared IntegrationRegistry.
	 *
	 * The loader is a closure that reads the LeafRegistry catalogue, which
	 * dispatches `RegisterLeafProvidersEvent` once and lands any data-provider
	 * leaves on the IntegrationRegistry. Installing it here (rather than
	 * dispatching now) keeps the collect-event LAZY: it fires only when
	 * something actually reads the registry or the leaf catalogue in a request
	 * (ADR-066). Guarded so a registry-less build still boots.
	 *
	 * @param mixed $server Server container (passed in from boot()).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-leaf-provider-registration/specs/leaf-provider-registration/spec.md
	 */
	public static function bootLeafRegistry($server): void {
		try {
			$integrationRegistry = $server->get(IntegrationRegistry::class);
			$integrationRegistry->setLeafLoader(
				static function () use ($server): void {
					// Reading the catalogue triggers LeafRegistry::ensureLoaded(),
					// which dispatches the event and calls addProvider() for each
					// data-provider leaf.
					$server->get(LeafRegistry::class)->getDescriptors();
				}
			);
		} catch (\Throwable $e) {
			// Registry binding not available — skip silently; the leaf catalogue
			// simply stays empty on this build.
			return;
		}

	}//end bootLeafRegistry()
}//end class
