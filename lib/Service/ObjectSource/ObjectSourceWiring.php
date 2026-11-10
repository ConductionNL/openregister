<?php

/**
 * The object source registrations of OpenRegister.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use OCA\OpenRegister\Service\Dbal\DatabaseIntrospectionService;
use OCA\OpenRegister\Service\Dbal\DbalConnectionFactory;
use OCA\OpenRegister\Service\Dbal\IntrospectionTools;
use OCA\OpenRegister\Service\Dbal\SqlTypeMapper;
use OCA\OpenRegister\Service\TaskService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/**
 * The wiring behind ObjectSourceRegistrar.
 *
 * ObjectSourceRegistrar keeps the public entry points and delegates each of them here.
 *
 * Registers the object source registry, the built-in object source providers
 * and the DBAL services they use, and adds those providers to the registry.
 *
 * Application::register() calls register() once; Application::boot() calls boot() once.
 */
final class ObjectSourceWiring {

	/**
	 * Stands for the container itself as a constructor argument.
	 */
	private const CONTAINER = '(container)';

	/**
	 * Contract => the service that implements it, for callers outside the Service component.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = [
		TablesCatalogueInterface::class => TablesTableReader::class,
		TablesSchemaReconcilerInterface::class => TablesSchemaSyncService::class,
	];

	/**
	 * The services register() adds, in order: per service, its constructor
	 * arguments by name and the container id each one is resolved from.
	 *
	 * A later registration of the same name replaces an earlier one, so the order matters.
	 *
	 * @var array<class-string, array<string, string>>
	 */
	private const SERVICES = [
		ObjectSourceRegistry::class => [
			'logger' => 'Psr\Log\LoggerInterface',
		],
		FederatedObjectSourceProvider::class => [
			'clientService' => 'OCP\Http\Client\IClientService',
			'logger'        => 'Psr\Log\LoggerInterface',
		],
		OptionalAppBridge::class => [
			'appManager' => 'OCP\App\IAppManager',
			'container'  => self::CONTAINER,
			'logger'     => 'Psr\Log\LoggerInterface',
		],
		CalDavVtodoObjectSourceProvider::class => [
			'taskService' => TaskService::class,
			'app'         => OptionalAppBridge::class,
		],
		UserDirectoryObjectSourceProvider::class => [
			'userManager'  => 'OCP\IUserManager',
			'userSession'  => 'OCP\IUserSession',
			'groupManager' => 'OCP\IGroupManager',
			'logger'       => 'Psr\Log\LoggerInterface',
		],
		GroupObjectSourceProvider::class => [
			'groupManager' => 'OCP\IGroupManager',
			'session'      => \OCA\OpenRegister\Service\LinkSessionRuntime::class,
		],
		OrganisationActingUser::class => [
			'userSession'  => 'OCP\IUserSession',
			'groupManager' => 'OCP\IGroupManager',
		],
		OrganisationObjectSourceProvider::class => [
			'organisationMapper'  => 'OCA\OpenRegister\Db\OrganisationMapperInterface',
			'actingUser'          => OrganisationActingUser::class,
			'logger'              => 'Psr\Log\LoggerInterface',
			'organisationService' => 'OCA\OpenRegister\Service\OrganisationService',
		],
		ContactsObjectSourceProvider::class => [
			'contactsManager' => 'OCP\Contacts\IManager',
			'app'             => OptionalAppBridge::class,
		],
		CalendarEventObjectSourceProvider::class => [
			'calendarEventService' => \OCA\OpenRegister\Service\CalendarEventService::class,
			'app'                  => OptionalAppBridge::class,
		],
		FilesObjectSourceProvider::class => [
			'rootFolder' => 'OCP\Files\IRootFolder',
			'session'    => \OCA\OpenRegister\Service\LinkSessionRuntime::class,
		],
		DeckObjectSourceProvider::class => [
			'app'         => OptionalAppBridge::class,
			'userSession' => 'OCP\IUserSession',
		],
		TalkObjectSourceProvider::class => [
			'app'         => OptionalAppBridge::class,
			'userSession' => 'OCP\IUserSession',
		],
		// DBAL virtual registers: the connection factory depends only on the CredentialStore interface.
		DbalConnectionFactory::class => [
			'credentialStore' => \OCA\OpenRegister\Service\Credential\CredentialStore::class,
			'logger'          => 'Psr\Log\LoggerInterface',
		],
		SqlTypeMapper::class => [
			'logger' => 'Psr\Log\LoggerInterface',
		],
		DatabaseIntrospectionService::class => [
			'tools'          => IntrospectionTools::class,
			'registerMapper' => \OCA\OpenRegister\Db\RegisterMapperInterface::class,
			'schemaMapper'   => \OCA\OpenRegister\Db\SchemaMapperInterface::class,
			'logger'         => 'Psr\Log\LoggerInterface',
		],
		DbalObjectSourceProvider::class => [
			'sourceMapper'      => \OCA\OpenRegister\Db\SourceMapperInterface::class,
			'connectionFactory' => DbalConnectionFactory::class,
			'logger'            => 'Psr\Log\LoggerInterface',
		],
		// Tables provider stack: a guarded reader (the sole Tables boundary), pure mappers, a schema-sync reconciler and the provider.
		TablesUuidDeriver::class => [],
		TablesTableReader::class => [
			'app' => OptionalAppBridge::class,
		],
		TablesColumnMapper::class => [
			'uuidDeriver' => TablesUuidDeriver::class,
			'logger'      => 'Psr\Log\LoggerInterface',
		],
		TablesSchemaSyncService::class => [
			'registerMapper' => \OCA\OpenRegister\Db\RegisterMapperInterface::class,
			'schemaMapper'   => \OCA\OpenRegister\Db\SchemaMapperInterface::class,
			'columnMapper'   => TablesColumnMapper::class,
			'logger'         => 'Psr\Log\LoggerInterface',
		],
		TablesProviderParts::class => [
			'reader'       => TablesTableReader::class,
			'columnMapper' => TablesColumnMapper::class,
			'uuidDeriver'  => TablesUuidDeriver::class,
			'syncService'  => TablesSchemaSyncService::class,
		],
		TablesObjectSourceProvider::class => [
			'parts'   => TablesProviderParts::class,
			'session' => \OCA\OpenRegister\Service\LinkSessionRuntime::class,
		],
	];

	/**
	 * Register the ObjectSourceRegistry (shared), the built-in object source
	 * providers and the DBAL and Tables services they use.
	 *
	 * The registry is shared so `addProvider()` registrations made during
	 * `boot()` persist for the whole request; GetObject auto-wires it.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-source-providers/tasks.md#1-provider-interface-registry
	 */
	public static function register(IRegistrationContext $context): void {
		foreach (array_keys(self::SERVICES) as $class) {
			$context->registerService(
				$class,
				function (ContainerInterface $container) use ($class) {
					return self::build(container: $container, class: $class);
				}
			);
		}

		foreach (self::BINDINGS as $contract => $service) {
			$context->registerServiceAlias($contract, $service);
		}
	}//end register()

	/**
	 * Build one listed service, resolving its constructor arguments from the container in their listed order.
	 *
	 * @param ContainerInterface $container The container to resolve from.
	 * @param class-string       $class     A service listed in SERVICES.
	 *
	 * @return object The new service.
	 */
	private static function build(ContainerInterface $container, string $class): object {
		$arguments = [];
		foreach (self::SERVICES[$class] as $name => $id) {
			$arguments[$name] = $container;
			if ($id !== self::CONTAINER) {
				$arguments[$name] = $container->get($id);
			}
		}

		return new $class(...$arguments);
	}//end build()

	/**
	 * Register the built-in object-source providers with the shared
	 * ObjectSourceRegistry.
	 *
	 * Runs in boot() (post-registration) because addProvider() needs the
	 * registry instance. Guarded so one absent provider never takes the app
	 * down — a failing provider simply won't serve its bound schemas.
	 *
	 * @param mixed $server Server container (passed in from boot()).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-source-providers/tasks.md#5-caldav-vtodo-provider
	 */
	public static function boot($server): void {
		try {
			$registry = $server->get(ObjectSourceRegistry::class);
		} catch (\Throwable $e) {
			return;
		}

		// Register each built-in provider independently so one absent provider
		// never blocks the others — a failing provider simply won't serve its
		// bound schemas.
		$providerClasses = [
			CalDavVtodoObjectSourceProvider::class,
			UserDirectoryObjectSourceProvider::class,
			GroupObjectSourceProvider::class,
			OrganisationObjectSourceProvider::class,
			ContactsObjectSourceProvider::class,
			CalendarEventObjectSourceProvider::class,
			FilesObjectSourceProvider::class,
			DeckObjectSourceProvider::class,
			TalkObjectSourceProvider::class,
			FederatedObjectSourceProvider::class,
			TablesObjectSourceProvider::class,
			// Virtual registers (dbal-virtual-registers): external SQL databases over Doctrine DBAL.
			DbalObjectSourceProvider::class,
			// A saved view's rows as a read-only type (modelling-query-backed-type).
			\OCA\OpenRegister\Service\ObjectSource\ViewObjectSourceProvider::class,
		];
		foreach ($providerClasses as $providerClass) {
			try {
				$registry->addProvider($server->get($providerClass));
			} catch (\Throwable $e) {
				try {
					$server->get(\Psr\Log\LoggerInterface::class)->warning(
						'[ObjectSource] could not register ' . $providerClass . ': ' . $e->getMessage()
					);
				} catch (\Throwable $inner) {
					// Logger unavailable on this build — nothing to do.
				}
			}
		}
	}//end boot()
}//end class
