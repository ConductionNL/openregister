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
use OCA\OpenRegister\Service\Dbal\SqlTypeMapper;
use OCA\OpenRegister\Service\TaskService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/**
 * Registers the object source registry, the built-in object source providers
 * and the DBAL services they use, and adds those providers to the registry.
 *
 * Application::register() calls register() once; Application::boot() calls boot() once.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A registrar names every service it wires.
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)  Factory closures list every constructor argument.
 */
final class ObjectSourceRegistrar {

	/**
	 * Register the ObjectSourceRegistry (shared) and the built-in
	 * CalDAV-VTODO object-source provider service.
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
		$context->registerService(
			ObjectSourceRegistry::class,
			function (ContainerInterface $container) {
				return new ObjectSourceRegistry(
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		$context->registerService(
			FederatedObjectSourceProvider::class,
			function (ContainerInterface $container) {
				return new FederatedObjectSourceProvider(
					clientService: $container->get('OCP\Http\Client\IClientService'),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		$context->registerService(
			CalDavVtodoObjectSourceProvider::class,
			function (ContainerInterface $container) {
				return new CalDavVtodoObjectSourceProvider(
					taskService: $container->get(TaskService::class),
					appManager: $container->get('OCP\App\IAppManager'),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		$context->registerService(
			UserDirectoryObjectSourceProvider::class,
			function (ContainerInterface $container) {
				return new UserDirectoryObjectSourceProvider(
					userManager: $container->get('OCP\IUserManager'),
					userSession: $container->get('OCP\IUserSession'),
					groupManager: $container->get('OCP\IGroupManager'),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		$context->registerService(
			GroupObjectSourceProvider::class,
			function (ContainerInterface $container) {
				return new GroupObjectSourceProvider(
					groupManager: $container->get('OCP\IGroupManager'),
					userSession: $container->get('OCP\IUserSession'),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		$context->registerService(
			OrganisationObjectSourceProvider::class,
			function (ContainerInterface $container) {
				return new OrganisationObjectSourceProvider(
					organisationMapper: $container->get('OCA\OpenRegister\Db\OrganisationMapper'),
					userSession: $container->get('OCP\IUserSession'),
					groupManager: $container->get('OCP\IGroupManager'),
					logger: $container->get('Psr\Log\LoggerInterface'),
					organisationService: $container->get('OCA\OpenRegister\Service\OrganisationService')
				);
			}
		);

		$context->registerService(
			ContactsObjectSourceProvider::class,
			function (ContainerInterface $container) {
				return new ContactsObjectSourceProvider(
					contactsManager: $container->get('OCP\Contacts\IManager'),
					appManager: $container->get('OCP\App\IAppManager'),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		$context->registerService(
			CalendarEventObjectSourceProvider::class,
			function (ContainerInterface $container) {
				return new CalendarEventObjectSourceProvider(
					calendarEventService: $container->get(\OCA\OpenRegister\Service\CalendarEventService::class),
					appManager: $container->get('OCP\App\IAppManager'),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		$context->registerService(
			FilesObjectSourceProvider::class,
			function (ContainerInterface $container) {
				return new FilesObjectSourceProvider(
					rootFolder: $container->get('OCP\Files\IRootFolder'),
					userSession: $container->get('OCP\IUserSession'),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		$context->registerService(
			DeckObjectSourceProvider::class,
			function (ContainerInterface $container) {
				return new DeckObjectSourceProvider(
					appManager: $container->get('OCP\App\IAppManager'),
					userSession: $container->get('OCP\IUserSession'),
					container: $container,
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		$context->registerService(
			TalkObjectSourceProvider::class,
			function (ContainerInterface $container) {
				return new TalkObjectSourceProvider(
					appManager: $container->get('OCP\App\IAppManager'),
					userSession: $container->get('OCP\IUserSession'),
					container: $container,
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		// DBAL virtual-register services + provider (dbal-virtual-registers).
		// The connection factory depends only on the CredentialStore interface,
		// which is already bound to the active leaf via CredentialStoreResolver.
		$context->registerService(
			\OCA\OpenRegister\Service\Dbal\DbalConnectionFactory::class,
			function (ContainerInterface $container) {
				return new DbalConnectionFactory(
					credentialStore: $container->get(\OCA\OpenRegister\Service\Credential\CredentialStore::class),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		$context->registerService(
			\OCA\OpenRegister\Service\Dbal\SqlTypeMapper::class,
			function (ContainerInterface $container) {
				return new SqlTypeMapper(
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		$context->registerService(
			\OCA\OpenRegister\Service\Dbal\DatabaseIntrospectionService::class,
			function (ContainerInterface $container) {
				return new DatabaseIntrospectionService(
					connectionFactory: $container->get(\OCA\OpenRegister\Service\Dbal\DbalConnectionFactory::class),
					typeMapper: $container->get(\OCA\OpenRegister\Service\Dbal\SqlTypeMapper::class),
					registerMapper: $container->get(\OCA\OpenRegister\Db\RegisterMapper::class),
					schemaMapper: $container->get(\OCA\OpenRegister\Db\SchemaMapper::class),
					diffService: $container->get(\OCA\OpenRegister\Service\Schema\SchemaDiffService::class),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		$context->registerService(
			DbalObjectSourceProvider::class,
			function (ContainerInterface $container) {
				return new DbalObjectSourceProvider(
					sourceMapper: $container->get(\OCA\OpenRegister\Db\SourceMapper::class),
					connectionFactory: $container->get(\OCA\OpenRegister\Service\Dbal\DbalConnectionFactory::class),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		// Tables provider stack — a guarded reader (the sole Tables-boundary),
		// pure mappers, a schema-sync reconciler, and the provider itself.
		$context->registerService(
			TablesUuidDeriver::class,
			function () {
				return new TablesUuidDeriver();
			}
		);

		$context->registerService(
			TablesTableReader::class,
			function (ContainerInterface $container) {
				return new TablesTableReader(
					appManager: $container->get('OCP\App\IAppManager'),
					container: $container,
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		$context->registerService(
			TablesColumnMapper::class,
			function (ContainerInterface $container) {
				return new TablesColumnMapper(
					uuidDeriver: $container->get(TablesUuidDeriver::class),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		$context->registerService(
			TablesSchemaSyncService::class,
			function (ContainerInterface $container) {
				return new TablesSchemaSyncService(
					registerMapper: $container->get(\OCA\OpenRegister\Db\RegisterMapper::class),
					schemaMapper: $container->get(\OCA\OpenRegister\Db\SchemaMapper::class),
					columnMapper: $container->get(TablesColumnMapper::class),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		$context->registerService(
			TablesObjectSourceProvider::class,
			function (ContainerInterface $container) {
				return new TablesObjectSourceProvider(
					reader: $container->get(TablesTableReader::class),
					columnMapper: $container->get(TablesColumnMapper::class),
					uuidDeriver: $container->get(TablesUuidDeriver::class),
					syncService: $container->get(TablesSchemaSyncService::class),
					userSession: $container->get('OCP\IUserSession'),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

	}//end register()

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
