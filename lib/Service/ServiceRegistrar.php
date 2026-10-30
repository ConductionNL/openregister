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

use OCA\OpenRegister\Contract\EmailLinkReaderInterface;
use OCA\OpenRegister\Contract\RegisterSlugResolverInterface;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\File\Pdf\Fallback\NullNcOfficeConverter;
use OCA\OpenRegister\Service\Integration\TimeProvider as IntegrationTimeProvider;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionPass;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionRunner;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Portal\PortalPartyResolver;
use OCA\OpenRegister\Service\SchemaImport\DialectDetector;
use OCA\OpenRegister\Service\SchemaImport\SchemaImportService;
use OCA\OpenRegister\Service\SchemaImport\ThreeWayMerge;
use OCA\OpenRegister\Service\Sync\SourceFetcherRegistry;
use OCA\OpenRegister\Service\Task\TaskInboxService;
use OCA\OpenRegister\Service\Translation\IdentityTranslationProvider;
use OCA\OpenRegister\Service\Translation\TranslationProviderInterface;
use OCA\OpenRegister\Service\Vectorization\Strategies\FileVectorizationStrategy;
use OCA\OpenRegister\Service\Vectorization\Strategies\ObjectVectorizationStrategy;
use OCA\OpenRegister\Service\Vectorization\VectorEmbeddings;
use OCA\OpenRegister\Service\WebPush\HexIconService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

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

		// The named-transition context MUST be shared for the same reason:
		// TransitionEngine declares the action on one instance and the
		// lifecycle listeners read it on another. Unshared, every named call
		// would silently fall back to matching by from/to value.
		$context->registerService(
			LifecycleActionContext::class,
			function () {
				return new LifecycleActionContext();
			}
		);

		// The automatic-transition pass MUST be shared, and for a sharper reason
		// than the two above: it IS the request's state. The listener records
		// into it, ObjectService and TransitionEngine open and close boundaries
		// on it, and the drain reads the lineage the boundary counted. Auto-
		// wired fresh at each injection point, every one of those would hold its
		// own counter: nothing would ever reach depth zero with a record in it,
		// so no automatic transition would ever fire, silently and with no
		// error anywhere.
		$context->registerService(
			AutoTransitionPass::class,
			function ($c) {
				return new AutoTransitionPass(
					runner: $c->get(AutoTransitionRunner::class),
					logger: $c->get(LoggerInterface::class)
				);
			}
		);

		// The register-slug resolver MUST be shared. It memoises one indexed
		// read per candidate list for the life of the request, and a sweep asks
		// the same question at every call site of the same tick. Nextcloud
		// auto-wires a fresh instance at every injection point, so without this
		// registration the memo would be empty every time and the probe would
		// re-read the register table once per call site.
		$context->registerService(
			RegisterSlugResolver::class,
			function ($c) {
				return new RegisterSlugResolver(
					registerMapper: $c->get(RegisterMapper::class),
					logger: $c->get(LoggerInterface::class)
				);
			}
		);

		// The published contract (ADR-084) consuming apps type-hint. Bound to
		// the same shared instance, so an app that injects the interface and one
		// that injects the class share a memo rather than probing twice.
		$context->registerService(
			RegisterSlugResolverInterface::class,
			function ($c) {
				return $c->get(RegisterSlugResolver::class);
			}
		);

		// The published read of an object's linked emails, for consuming apps.
		$context->registerService(
			EmailLinkReaderInterface::class,
			function ($c) {
				return $c->get(EmailLinkService::class);
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
	 * @spec openspec/specs/credential-broker/spec.md#requirement-credential-store-backend-resolution
	 */
	private static function registerSeams(IRegistrationContext $context): void {
		// Credential broker (credential-broker-service + credential-doriath-leaf):
		// bind the CredentialStore abstraction through the CredentialStoreResolver
		// (design D-A) — the Doriath application-vault leaf when the doriath app is
		// eligible (enabled + service classes + application-scoped seam methods +
		// OR self-registration), else the NC encrypted per-user vault leaf. The
		// broker and controller keep depending only on the CredentialStore
		// interface; this factory is the ONLY place the selection happens.
		$context->registerService(
			\OCA\OpenRegister\Service\Credential\CredentialStore::class,
			static function ($c) {
				return $c->get(\OCA\OpenRegister\Service\Credential\CredentialStoreResolver::class)->resolve();
			}
		);

		// Register the default no-op TranslationProvider. Operators replace
		// this binding with a real provider (LibreTranslate / DeepL / etc.)
		// by overriding it in their own app's registration.
		$context->registerService(
			TranslationProviderInterface::class,
			function () {
				return new IdentityTranslationProvider();
			}
		);

		// Bind the dormant Path B PDF anonymisation fallback bridge to its
		// null implementation. Tenants enabling Path B replace this binding
		// with a concrete NcOfficeConverterInterface implementation that
		// talks to Collabora Online / Code (per the
		// `pdf-anonymisation-odt-fallback` scaffold).
		$context->registerService(
			\OCA\OpenRegister\Service\File\Pdf\Fallback\NcOfficeConverterInterface::class,
			function () {
				return new NullNcOfficeConverter();
			}
		);

		// Web Push hex-icon service (openregister-web-push-engine): needs an
		// app-scoped IAppData for its rendered-PNG cache, which is not
		// auto-wirable, so resolve it via IAppDataFactory here.
		$context->registerService(
			\OCA\OpenRegister\Service\WebPush\HexIconService::class,
			function (\Psr\Container\ContainerInterface $c): \OCA\OpenRegister\Service\WebPush\HexIconService {
				return new HexIconService(
					appManager: $c->get(\OCP\App\IAppManager::class),
					appData: $c->get(\OCP\Files\AppData\IAppDataFactory::class)->get('openregister'),
					logger: $c->get(\Psr\Log\LoggerInterface::class)
				);
			}
		);

		// Register the data-sync source-fetcher registry pre-loaded with the
		// built-in REST/OpenRegister fetcher. Apps can register additional
		// fetchers (OData, SOAP, CSV) by resolving and extending this service.
		$context->registerService(
			\OCA\OpenRegister\Service\Sync\SourceFetcherRegistry::class,
			function ($c) {
				$registry = new SourceFetcherRegistry();
				$registry->register($c->get(\OCA\OpenRegister\Service\Sync\RestApiSourceFetcher::class));
				return $registry;
			}
		);

		// Register the standards schema-import service with the bundled,
		// versioned snapshot resources (schema-import-standards). Built
		// explicitly so the resource root is deterministic; the dialect
		// registry inside it makes DCAT/SKOS/ZGW importers follow-ups.
		$context->registerService(
			\OCA\OpenRegister\Service\SchemaImport\SchemaImportService::class,
			function () {
				return new SchemaImportService(
					detector: new DialectDetector(),
					merge: new ThreeWayMerge()
				);
			}
		);
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
		$context->registerService(
			OrganisationService::class,
			function (ContainerInterface $container) {
				return new OrganisationService(
					organisationMapper: $container->get(OrganisationMapper::class),
					userSession: $container->get('OCP\IUserSession'),
					session: $container->get('OCP\ISession'),
					config: $container->get('OCP\IConfig'),
					appConfig: $container->get('OCP\IAppConfig'),
					groupManager: $container->get('OCP\IGroupManager'),
					userManager: $container->get('OCP\IUserManager'),
					logger: $container->get('Psr\Log\LoggerInterface'),
					settingsService: null
				);
			}
		);

		// Register UserService for UserController (after OrganisationService which it depends on).
		$context->registerService(
			\OCA\OpenRegister\Service\UserService::class,
			function (ContainerInterface $container) {
				return new UserService(
					userManager: $container->get('OCP\IUserManager'),
					userSession: $container->get('OCP\IUserSession'),
					config: $container->get('OCP\IConfig'),
					groupManager: $container->get('OCP\IGroupManager'),
					accountManager: $container->get('OCP\Accounts\IAccountManager'),
					logger: $container->get('Psr\Log\LoggerInterface'),
					organisationService: $container->get(OrganisationService::class),
					eventDispatcher: $container->get('OCP\EventDispatcher\IEventDispatcher'),
					avatarManager: $container->get('OCP\IAvatarManager'),
					auditTrailMapper: $container->get(\OCA\OpenRegister\Db\AuditTrailMapper::class),
					secureRandom: $container->get('OCP\Security\ISecureRandom'),
					db: $container->get('OCP\IDBConnection'),
					l10nFactory: $container->get('OCP\L10N\IFactory')
				);
			}
		);
	}//end registerUserAndOrganisation()

	/**
	 * Register the object, file, vectorization, task and note services that are built by hand.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	private static function registerObjectServices(IRegistrationContext $context): void {
		// The task inbox and the portal party resolver read the object store
		// through AbstractObjectMapper, which the autowirer cannot build (it is
		// abstract) and would silently default to null: an inbox row without
		// subject context, and a portal task that can match nobody. Both are
		// wired to the MagicMapper explicitly for that reason.
		$context->registerService(
			TaskInboxService::class,
			function (ContainerInterface $container) {
				return new TaskInboxService(
					tasks: $container->get(\OCA\OpenRegister\Db\TaskMapper::class),
					temporal: $container->get(\OCA\OpenRegister\Service\Task\TaskTemporalProjection::class),
					logger: $container->get('Psr\Log\LoggerInterface'),
					objects: $container->get(MagicMapper::class),
					deliveries: $container->get(\OCA\OpenRegister\Db\PortalTaskDeliveryMapper::class),
					// Reads a located task's subject from its own table instead
					// of searching every magic table for it.
					locator: $container->get(\OCA\OpenRegister\Service\Task\TaskSubjectLocator::class)
				);
			}
		);

		$context->registerService(
			PortalPartyResolver::class,
			function (ContainerInterface $container) {
				return new PortalPartyResolver(
					objects: $container->get(MagicMapper::class)
				);
			}
		);

		$context->registerService(
			CacheHandler::class,
			function (ContainerInterface $container) {
				return new CacheHandler(
					organisationMapper: $container->get(OrganisationMapper::class),
					logger: $container->get('Psr\Log\LoggerInterface'),
					cacheFactory: $container->get('OCP\ICacheFactory'),
					userSession: $container->get('OCP\IUserSession'),
					container: $container,
					registerMapper: $container->get(RegisterMapper::class),
					schemaMapper: $container->get(SchemaMapper::class),
					db: $container->get('OCP\IDBConnection')
				);
			}
		);

		// FolderManagementHandler without FileService to break circular dependency.
		$context->registerService(
			FolderManagementHandler::class,
			function (ContainerInterface $container) {
				return new FolderManagementHandler(
					rootFolder: $container->get('OCP\Files\IRootFolder'),
					objectEntityMapper: $container->get(MagicMapper::class),
					registerMapper: $container->get(RegisterMapper::class),
					userSession: $container->get('OCP\IUserSession'),
					groupManager: $container->get('OCP\IGroupManager'),
					logger: $container->get('Psr\Log\LoggerInterface'),
					auditTrailMapper: $container->get(\OCA\OpenRegister\Db\AuditTrailMapper::class),
					mountCache: $container->get('OCP\Files\Config\IUserMountCache'),
					folderRecorder: $container->get(\OCA\OpenRegister\Db\RegisterFolderRecorder::class),
					fileService: null
				);
			}
		);

		$context->registerService(
			VectorizationService::class,
			function (ContainerInterface $container) {
				$service = new VectorizationService(
					vectorService: $container->get(VectorEmbeddings::class),
					logger: $container->get('Psr\Log\LoggerInterface')
				);

				$fileStrategy = $container->get(FileVectorizationStrategy::class);
				$objectStrategy = $container->get(ObjectVectorizationStrategy::class);
				$service->registerStrategy('file', $fileStrategy);
				$service->registerStrategy('object', $objectStrategy);

				return $service;
			}
		);

		$context->registerService(
			TaskService::class,
			function (ContainerInterface $container) {
				// The write-back gate is resolved lazily and best-effort: without
				// it a PROJECTED VTODO's update is refused (fail closed), and
				// standalone VTODOs are unaffected either way.
				$gate = null;
				try {
					$gate = $container->get(\OCA\OpenRegister\Service\Task\TaskVtodoWriteBackGate::class);
				} catch (\Throwable $e) {
					$container->get('Psr\Log\LoggerInterface')->debug(
						'[Application] TaskVtodoWriteBackGate unavailable for TaskService: ' . $e->getMessage()
					);
				}

				return new TaskService(
					calDavBackend: $container->get('OCA\DAV\CalDAV\CalDavBackend'),
					userSession: $container->get('OCP\IUserSession'),
					logger: $container->get('Psr\Log\LoggerInterface'),
					urlGenerator: $container->get('OCP\IURLGenerator'),
					gate: $gate
				);
			}
		);

		$context->registerService(
			NoteService::class,
			function (ContainerInterface $container) {
				return new NoteService(
					commentsManager: $container->get('OCP\Comments\ICommentsManager'),
					userSession: $container->get('OCP\IUserSession'),
					userManager: $container->get('OCP\IUserManager'),
					logger: $container->get('Psr\Log\LoggerInterface'),
					visibility: $container->get(\OCA\OpenRegister\Service\TimelineVisibilityService::class),
					versions: $container->get(\OCA\OpenRegister\Service\NoteVersionService::class)
				);
			}
		);

		$context->registerService(
			\OCA\OpenRegister\Service\Integration\TimeProvider::class,
			function (ContainerInterface $container) {
				return new IntegrationTimeProvider(
					appConfig: $container->get('OCP\IAppConfig')
				);
			}
		);
	}//end registerObjectServices()
}//end class
