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

use OCA\OpenRegister\Controller\IntegrationControllersRegistrar;
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
use OCA\OpenRegister\Service\Integration\Providers\KvkLookupInterface;
use OCA\OpenRegister\Service\Integration\Providers\KvkProvider;
use OCA\OpenRegister\Service\Integration\Providers\MessageDispatcherInterface;
use OCA\OpenRegister\Service\Integration\Providers\MessageDispatchProvider;
use OCA\OpenRegister\Service\Integration\Providers\OpenCorporatesProvider;
use OCA\OpenRegister\Service\Integration\Providers\OpenCorporatesSearchInterface;
use OCA\OpenRegister\Service\Integration\Providers\OpenProjectProvider;
use OCA\OpenRegister\Service\Integration\Providers\PollsProvider;
use OCA\OpenRegister\Service\Integration\Providers\SharesProvider;
use OCA\OpenRegister\Service\Integration\Providers\TalkProvider;
use OCA\OpenRegister\Service\Integration\Providers\XwikiProvider;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/**
 * The wiring behind IntegrationRegistrar.
 *
 * IntegrationRegistrar keeps the public entry points and delegates each of them here.
 *
 * Registers the integration registry, the external integration router, the
 * built-in integration providers and the services around them, and adds the
 * providers and the leaf loader to the registry at boot.
 *
 * Application::register() calls register() once; Application::boot() calls boot() once.
 */
final class IntegrationWiring {

	/**
	 * Contract => the service that implements it.
	 *
	 * Callers that only read the registry type against its read-side contracts;
	 * registration (addProvider, withProviders, setLeafLoader) stays on
	 * IntegrationRegistry. The lookup and dispatch controllers type against the
	 * provider contracts.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = [
		IntegrationCatalogueInterface::class => IntegrationRegistry::class,
		IntegrationIdIndexInterface::class => IntegrationRegistry::class,
		KvkLookupInterface::class => KvkProvider::class,
		LeafRegistryInterface::class => LeafRegistry::class,
		MessageDispatcherInterface::class => MessageDispatchProvider::class,
		OpenCorporatesSearchInterface::class => OpenCorporatesProvider::class,
		PageWidgetRegistryInterface::class => IntegrationRegistry::class,
	];

	/**
	 * The built-in providers boot() adds to the registry, in this order.
	 *
	 * @var list<class-string>
	 */
	private const BUILTIN_PROVIDERS = [
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

	/**
	 * Register the IntegrationRegistry + ExternalIntegrationRouter
	 * services used by the pluggable integration registry.
	 *
	 * Both are shared per-request singletons. Apps that ship their own
	 * IntegrationProvider implementations register them via
	 * `$this->container->get(IntegrationRegistry::class)->addProvider(...)`
	 * from their own Application::register() hook — see
	 * `openspec/changes/pluggable-integration-registry/proposal.md` (AD-1).
	 * The read-side contracts in BINDINGS alias the same shared registry.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public static function register(IRegistrationContext $context): void {
		self::registerRegistryAndRouter(context: $context);
		self::registerValidators(context: $context);
		self::registerLeafRegistry(context: $context);
		self::registerBuiltinIntegrationProviders(context: $context);
		IntegrationControllersRegistrar::register(context: $context);
		foreach (self::BINDINGS as $contract => $service) {
			$context->registerServiceAlias($contract, $service);
		}
	}//end register()

	/**
	 * Register the shared IntegrationRegistry and the ExternalIntegrationRouter.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private static function registerRegistryAndRouter(IRegistrationContext $context): void {
		$context->registerService(
			IntegrationRegistry::class,
			static fn (ContainerInterface $container) => new IntegrationRegistry(logger: $container->get('Psr\Log\LoggerInterface'))
		);
		$context->registerService(
			ExternalIntegrationRouter::class,
			static fn (ContainerInterface $container) => new ExternalIntegrationRouter(
				appManager: $container->get('OCP\App\IAppManager'),
				container: $container,
				logger: $container->get('Psr\Log\LoggerInterface')
			)
		);
	}//end registerRegistryAndRouter()

	/**
	 * Register the two property-reference validators.
	 *
	 * PropertyReferenceTypeValidator is consumed by schema-property validation
	 * paths that opt in to the referenceType marker (AD-18); it stays separate
	 * from the entity-level validateLinkedTypesValue path so existing schemas
	 * keep validating exactly as before. PropertySemanticReferenceValidator
	 * validates the opt-in `referenceSemanticType` marker (ADR-048) as a
	 * well-formed absolute IRI.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private static function registerValidators(IRegistrationContext $context): void {
		$context->registerService(
			PropertyReferenceTypeValidator::class,
			static fn (ContainerInterface $container) => new PropertyReferenceTypeValidator(
				registry: $container->get(IntegrationRegistry::class),
			)
		);
		$context->registerService(
			PropertySemanticReferenceValidator::class,
			static fn (ContainerInterface $container) => new PropertySemanticReferenceValidator(
				jsonLd: $container->get(\OCA\OpenRegister\Service\JsonLd\JsonLdContextService::class),
			)
		);
	}//end registerValidators()

	/**
	 * Register the LeafRegistry, the cross-app leaf catalogue (ADR-066).
	 *
	 * It collects the leaves sibling apps contribute through
	 * RegisterLeafProvidersEvent, lazily on first read, and lands their data
	 * providers on the shared IntegrationRegistry so existing per-object
	 * routing reaches them.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private static function registerLeafRegistry(IRegistrationContext $context): void {
		$context->registerService(
			LeafRegistry::class,
			static fn (ContainerInterface $container) => new LeafRegistry(
				eventDispatcher: $container->get(\OCP\EventDispatcher\IEventDispatcher::class),
				integrationRegistry: $container->get(IntegrationRegistry::class),
				appManager: $container->get('OCP\App\IAppManager'),
				logger: $container->get('Psr\Log\LoggerInterface')
			)
		);
	}//end registerLeafRegistry()

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
	 * Kept separate from the DI registration in register() because
	 * addProvider() needs the registry instance — i.e. it has to run after
	 * the container has fully bootstrapped. `boot()` is the canonical
	 * post-registration hook for that.
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

		self::addBuiltinProviders(server: $server, registry: $integrationRegistry);
	}//end boot()

	/**
	 * Resolve a logger up-front so a mis-wired factory closure or an absent
	 * wrapped service is visible in the logs instead of a silently-missing tab.
	 * Guarded so a logger-less build still boots.
	 *
	 * @param mixed $server Server container.
	 *
	 * @return \Psr\Log\LoggerInterface|null
	 */
	private static function bootLogger($server): ?\Psr\Log\LoggerInterface {
		try {
			return $server->get(\Psr\Log\LoggerInterface::class);
		} catch (\Throwable $e) {
			return null;
		}
	}//end bootLogger()

	/**
	 * Resolve every built-in provider and add it to the registry.
	 *
	 * Provider construction can fail if a wrapped service is missing on this
	 * NC build, or if the DI factory closure was not updated for a Tier-2
	 * constructor arg. One absent provider does not take the app down — its
	 * tab simply won't render — but it is logged so the cause is diagnosable.
	 *
	 * @param mixed               $server   Server container.
	 * @param IntegrationRegistry $registry The shared registry.
	 *
	 * @return void
	 */
	private static function addBuiltinProviders($server, IntegrationRegistry $registry): void {
		$logger = self::bootLogger(server: $server);
		foreach (self::BUILTIN_PROVIDERS as $providerClass) {
			try {
				$registry->addProvider($server->get($providerClass));
			} catch (\Throwable $e) {
				$logger?->warning(
					'OpenRegister: integration provider failed to construct and was dropped from the registry: {provider} — {message}',
					['provider' => $providerClass, 'message' => $e->getMessage(), 'exception' => $e]
				);
			}
		}
	}//end addBuiltinProviders()

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
