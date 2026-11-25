<?php

/**
 * The service registrations of OpenRegister that need a hand-written factory.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Contract\FileTextReaderInterface;
use OCA\OpenRegister\Contract\ObjectFileReaderInterface;
use OCA\OpenRegister\Contract\RegisterSlugResolverInterface;
use OCA\OpenRegister\Db\DbRegistrar;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Files\Service\TextExtraction\FileTextReader;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\File\ObjectFileReader;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\CacheHandlerInterface;
use OCA\OpenRegister\Service\Object\CacheHandlerRuntime;
use OCA\OpenRegister\Service\Object\CacheHandlerStores;
use OCA\OpenRegister\Service\Object\CacheNameScopeSources;
use OCA\OpenRegister\Service\SchemaImport\DialectDetector;
use OCA\OpenRegister\Service\SchemaImport\SchemaImportService;
use OCA\OpenRegister\Service\SchemaImport\ThreeWayMerge;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\User\UserAccountStores;
use OCA\OpenRegister\Service\User\UserPlatform;
use OCA\OpenRegister\Service\User\UserStorage;

/**
 * Registers the services that need a hand-written factory: request-scoped state, replaceable seams, and services that break a circular dependency.
 *
 * Application::register() calls register() once.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A registrar names every service it wires.
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)  Factory closures list every constructor argument.
 */
final class ServiceRegistrar {

	/**
	 * Register every service of this registrar.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	public static function register(IRegistrationContext $context): void {
		// Core binds its own interfaces here; each other area binds its own in its own map.
		DbRegistrar::bind(context: $context, bindings: CoreServiceBindings::BINDINGS);
		self::registerRequestScoped(context: $context);
		self::registerSeams(context: $context);
		self::registerUserAndOrganisation(context: $context);
		self::registerObjectServices(context: $context);
	}//end register()

	/**
	 * Register the services that hold per-request state and must be shared.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	private static function registerRequestScoped(IRegistrationContext $context): void {
		// Register request-scoped LanguageService as a singleton (shared per request).
		$context->registerService(
			LanguageService::class,
			function () {
				return new LanguageService();
			}
		);

		// The register-slug resolver MUST be shared. It memoises one indexed
		// read per candidate list for the life of the request, and a sweep asks
		// the same question at every call site of the same tick. Nextcloud
		// auto-wires a fresh instance at every injection point, so without this
		// registration the memo would be empty every time and the probe would
		// re-read the register table once per call site.
		$context->registerService(RegisterSlugResolver::class, self::buildRegisterSlugResolver(...));

		// The published contract (ADR-084) consuming apps type-hint. Bound to
		// the same shared instance, so an app that injects the interface and one
		// that injects the class share a memo rather than probing twice.
		$context->registerService(
			RegisterSlugResolverInterface::class,
			function ($c) {
				return $c->get(RegisterSlugResolver::class);
			}
		);

		// The published read of an object's attached files, for consuming apps.
		$context->registerService(
			ObjectFileReaderInterface::class,
			function ($c) {
				return $c->get(ObjectFileReader::class);
			}
		);

		// The published text read of one file, for consuming apps (nothing stored).
		$context->registerService(
			FileTextReaderInterface::class,
			function ($c) {
				return $c->get(FileTextReader::class);
			}
		);
	}//end registerRequestScoped()

	/**
	 * Register the replaceable seams and the services that cannot be autowired.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	private static function registerSeams(IRegistrationContext $context): void {
		// Web Push hex-icon service (openregister-web-push-engine): needs an
		// app-scoped IAppData for its rendered-PNG cache, which is not
		// auto-wirable, so resolve it via IAppDataFactory here.

		// Register the standards schema-import service with the bundled,
		// versioned snapshot resources (schema-import-standards). Built
		// explicitly so the resource root is deterministic; the dialect
		// registry inside it makes DCAT/SKOS/ZGW importers follow-ups.
		$context->registerService(\OCA\OpenRegister\Service\SchemaImport\SchemaImportService::class, self::buildSchemaImportService(...));
	}//end registerSeams()

	/**
	 * Register the organisation and user services, the organisation service without its settings service to break a circular dependency.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	private static function registerUserAndOrganisation(IRegistrationContext $context): void {
		// Register OrganisationService without SettingsService to break circular dependency.
		$context->registerService(OrganisationService::class, self::buildOrganisationService(...));

		// Register UserService for UserController (after OrganisationService which it depends on).
		$context->registerService(\OCA\OpenRegister\Service\UserService::class, self::buildUserService(...));
	}//end registerUserAndOrganisation()

	/**
	 * Register the object, file, vectorization and note services that are built by hand.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	private static function registerObjectServices(IRegistrationContext $context): void {
		$context->registerService(CacheHandler::class, self::buildCacheHandler(...));

		// FolderManagementHandler without FileService to break circular dependency.
		$context->registerService(FolderManagementHandler::class, self::buildFolderManagementHandler(...));
	}//end registerObjectServices()

	/**
	 * Build the RegisterSlugResolver service.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return RegisterSlugResolver
	 */
	private static function buildRegisterSlugResolver(ContainerInterface $container): RegisterSlugResolver {
			return new RegisterSlugResolver(
				registerMapper: $container->get(RegisterMapperInterface::class),
				logger: $container->get(LoggerInterface::class)
			);
	}//end buildRegisterSlugResolver()

	/**
	 * Build the OrganisationService service.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return OrganisationServiceInterface
	 */
	private static function buildOrganisationService(ContainerInterface $container): OrganisationServiceInterface {
			return new OrganisationService(
				organisationMapper: $container->get(OrganisationMapperInterface::class),
				userSession: $container->get('OCP\IUserSession'),
				session: $container->get('OCP\ISession'),
				config: $container->get('OCP\IConfig'),
				appConfig: $container->get('OCP\IAppConfig'),
				groupManager: $container->get('OCP\IGroupManager'),
				userManager: $container->get('OCP\IUserManager'),
				logger: $container->get('Psr\Log\LoggerInterface'),
				settingsService: null
			);
	}//end buildOrganisationService()

	/**
	 * Build the UserService service.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return \OCA\OpenRegister\Service\UserService
	 */
	private static function buildUserService(ContainerInterface $container): \OCA\OpenRegister\Service\UserService {
			return new UserService(
				directory: new UserAccountStores(
					userManager: $container->get('OCP\IUserManager'),
					groupManager: $container->get('OCP\IGroupManager'),
					accountManager: $container->get('OCP\Accounts\IAccountManager'),
					avatarManager: $container->get('OCP\IAvatarManager')
				),
				platform: new UserPlatform(
					userSession: $container->get('OCP\IUserSession'),
					eventDispatcher: $container->get('OCP\EventDispatcher\IEventDispatcher'),
					l10nFactory: $container->get('OCP\L10N\IFactory'),
					logger: $container->get('Psr\Log\LoggerInterface')
				),
				storage: new UserStorage(
					config: $container->get('OCP\IConfig'),
					db: $container->get('OCP\IDBConnection'),
					auditTrailMapper: $container->get(\OCA\OpenRegister\Db\AuditTrailMapperInterface::class),
					secureRandom: $container->get('OCP\Security\ISecureRandom'),
					auditEntries: $container->get(\OCA\OpenRegister\Kernel\Contracts\Core\AuditEntryWriterInterface::class)
				),
				organisationService: $container->get(OrganisationServiceInterface::class)
			);
	}//end buildUserService()

	/**
	 * Build the CacheHandler service.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return CacheHandlerInterface
	 */
	private static function buildCacheHandler(ContainerInterface $container): CacheHandlerInterface {
			return new CacheHandler(
				stores: new CacheHandlerStores(
					organisationMapper: $container->get(OrganisationMapperInterface::class),
					registerMapper: $container->get(RegisterMapperInterface::class),
					schemaMapper: $container->get(SchemaMapperInterface::class),
					db: $container->get('OCP\IDBConnection')
				),
				runtime: new CacheHandlerRuntime(
					logger: $container->get('Psr\Log\LoggerInterface'),
					cacheFactory: $container->get('OCP\ICacheFactory'),
					container: $container
				),
				nameScope: new CacheNameScopeSources(
					userSession: $container->get('OCP\IUserSession')
				)
			);
	}//end buildCacheHandler()

	/**
	 * Build the FolderManagementHandler service.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return FolderManagementHandler
	 */
	private static function buildFolderManagementHandler(ContainerInterface $container): FolderManagementHandler {
			return new FolderManagementHandler(
				rootFolder: $container->get('OCP\Files\IRootFolder'),
				objectEntityMapper: $container->get(MagicMapperInterface::class),
				registerMapper: $container->get(RegisterMapperInterface::class),
				userSession: $container->get('OCP\IUserSession'),
				groupManager: $container->get('OCP\IGroupManager'),
				logger: $container->get('Psr\Log\LoggerInterface'),
				auditTrailMapper: $container->get(\OCA\OpenRegister\Db\AuditTrailMapperInterface::class),
				mountCache: $container->get('OCP\Files\Config\IUserMountCache'),
				folderRecorder: $container->get(\OCA\OpenRegister\Kernel\Contracts\Core\RegisterFolderRecordsInterface::class),
				fileService: null,
				// Not FileService itself (that would be the cycle this
				// factory breaks), only the means to resolve it on first use.
				container: $container,
				documentStore: $container->get(\OCA\OpenRegister\Service\File\DocumentStore::class)
			);
	}//end buildFolderManagementHandler()

	/**
	 * Build the SchemaImportService service.
	 *
	 * @return SchemaImportService
	 */
	private static function buildSchemaImportService(): SchemaImportService {
		return new SchemaImportService(detector: new DialectDetector(), merge: new ThreeWayMerge());
	}//end buildSchemaImportService()
}//end class
