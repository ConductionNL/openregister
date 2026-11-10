<?php

/**
 * OpenRegister Import Handler
 *
 * This file contains the handler class for importing configurations
 * from various sources in the OpenRegister application.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 * @spec openspec/specs/data-import-export/spec.md
 * @spec openspec/specs/data-import-export/spec.md
 * @spec openspec/specs/data-import-export/spec.md
 * @spec openspec/specs/workflow-in-import/spec.md#requirement-schema-hook-wiring-during-import
 * @spec openspec/specs/data-import-export/spec.md
 * @spec openspec/specs/data-import-export/spec.md
 */

namespace OCA\OpenRegister\Service\Configuration;

use OCA\OpenRegister\Service\Object\SaveObject\SlugRequest;
use DateTime;
use Exception;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Authorization\GroupProvisioner;
use OCA\OpenRegister\Service\Authorization\RbacGroupCollector;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Files\Service\File\RegisterFolderProvisionerInterface;
use OCA\OpenRegister\Kernel\Contracts\NoteServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Schema\SchemaChangeSet;
use OCA\OpenRegister\Service\Archival\SelectionListSeeder;
use OCA\OpenRegister\Service\Schema\SchemaVersioningService;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCA\OpenRegister\Service\TaskService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Class ImportHandler
 *
 * Handles importing configurations from JSON data, files, and applications.
 *
 * @package OCA\OpenRegister\Service\Configuration
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.UnusedPrivateField)
 * Reason: Configuration import requires comprehensive dependencies and complex validation logic.
 *         Reserved fields for future features.
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class ImportHandler {

	/**
	 * The result of an import that wrote nothing: every key present, every count zero.
	 *
	 * @var array<string, mixed>
	 */
	private const EMPTY_IMPORT_RESULT = [
		'registers' => [],
		'schemas' => [],
		'workflows' => ['deployed' => [], 'updated' => [], 'unchanged' => [], 'failed' => []],
		'endpoints' => [],
		'sources' => [],
		'mappings' => [],
		'jobs' => [],
		'synchronizations' => [],
		'rules' => [],
		'objects' => [],
		// Per-entity resilience: how many entities were skipped (with a logged
		// warning) instead of aborting the import.
		'skipped' => [
			'registers' => 0,
			'schemas' => 0,
			'objects' => 0,
			'mappings' => 0,
			'seedObjects' => 0,
		],
		// The entities left alone because they are already present at the same
		// or a newer version, so a caller can tell "nothing needed doing" from
		// "everything failed" (both arrive as `objects: []`).
		'unchanged' => ['objects' => 0],
		// The entities this import REJECTED, named with the reason. A rejected
		// SCHEMA propagates: the registers declaring it are created without the
		// link, so the damage surfaces layers away with nothing pointing back here.
		'failed' => ['schemas' => [], 'objects' => []],
	];

	/**
	 * Schema mapper instance for handling schema operations.
	 *
	 * @var SchemaMapperInterface The schema mapper instance.
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Register mapper instance for handling register operations.
	 *
	 * @var RegisterMapperInterface The register mapper instance.
	 */
	private readonly RegisterMapperInterface $registerMapper;

	/**
	 * Object mapper instance for handling object operations.
	 *
	 * @var MagicMapperInterface The object mapper instance.
	 */
	private readonly MagicMapperInterface $objectEntityMapper;

	/**
	 * Magic mapper instance for handling magic table operations.
	 *
	 * @var MagicMapperInterface|null The magic mapper instance (optional, set via setter).
	 */
	private ?MagicMapperInterface $magicMapper = null;

	/**
	 * MagicMapper for routing objects to correct magic table.
	 *
	 * @var MagicMapperInterface|null The object mapper instance (optional, set via setObjectMapper).
	 */
	private ?MagicMapperInterface $routingMapper = null;

	/**
	 * Configuration mapper instance for handling configuration operations.
	 *
	 * @var ConfigurationMapper The configuration mapper instance.
	 */
	private readonly ConfigurationMapper $configurationMapper;

	/**
	 * App config for storing version information.
	 *
	 * @var IAppConfig The app config instance.
	 */
	private readonly IAppConfig $appConfig;

	/**
	 * Logger instance for logging operations.
	 *
	 * @var LoggerInterface The logger instance.
	 */
	private readonly LoggerInterface $logger;

	/**
	 * App data path for resolving file paths.
	 *
	 * @var string The app data path.
	 */
	private readonly string $appDataPath;

	/**
	 * Object service for object CRUD operations.
	 *
	 * @var ObjectOperationsInterface|null The object service instance.
	 */
	private ?ObjectOperationsInterface $objectService = null;

	/**
	 * Map of registers indexed by slug during import.
	 *
	 * @var array<string, Register> Registers indexed by slug.
	 */
	private array $registersMap = [];

	/**
	 * Map of schemas indexed by slug during import.
	 *
	 * @var array<string, Schema> Schemas indexed by slug.
	 */
	private array $schemasMap = [];

	/**
	 * Keeps local changes to app-shipped schemas through the import.
	 *
	 * @var ShippedSchemaBaseline
	 */
	private ShippedSchemaBaseline $shippedBaseline;

	/**
	 * Creates and updates the Configuration of an app import.
	 *
	 * @var AppConfigurationRecord
	 */
	private AppConfigurationRecord $appConfigurations;

	/**
	 * Mapping mapper instance for handling mapping operations.
	 *
	 * @var MappingMapper The mapping mapper instance.
	 */
	private readonly MappingMapper $mappingMapper;

	/**
	 * OpenConnector configuration service for optional integration.
	 *
	 * @var mixed The OpenConnector configuration service or null.
	 */
	private mixed $connectorConfigSvc = null;



	/**
	 * Decides the slug of a seed object so it cannot take a slug another live
	 * object holds (#4037); null sets the seed slug as sent.
	 *
	 * @var \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugHandler|null
	 */
	private ?\OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugHandler $slugHandler = null;

	/**
	 * Optional provisioner that gives every register an app import returns its Files folder.
	 *
	 * @var RegisterFolderProvisionerInterface|null
	 */
	private ?RegisterFolderProvisionerInterface $folderProvisioner = null;

	/**
	 * Optional user session for tasks/notes that require a logged-in actor.
	 *
	 * @var IUserSession|null
	 */
	private ?IUserSession $userSession = null;

	/**
	 * Optional group manager used to resolve a fallback admin acting user
	 * when there is no logged-in session (occ/installer/cron import path).
	 *
	 * @var IGroupManager|null
	 */
	private ?IGroupManager $groupManager = null;

	/**
	 * Optional user manager used as a secondary fallback to resolve any
	 * enabled user as the acting user when the admin group is empty.
	 *
	 * @var IUserManager|null
	 */
	private ?IUserManager $userManager = null;

	/**
	 * Optional provisioner that creates the Nextcloud groups this configuration
	 * declares. Null on hosts where it could not be resolved — provisioning is
	 * then skipped and the import proceeds unchanged.
	 *
	 * @var GroupProvisioner|null
	 */
	private ?GroupProvisioner $groupProvisioner = null;

	/**
	 * Classifies a schema change an import makes, bumps its version and
	 * writes the changelog, as an edit through the schema API does (#4102).
	 * Null where it could not be resolved; the import then runs unchanged.
	 *
	 * @var SchemaVersioningService|null
	 */
	private ?SchemaVersioningService $schemaVersioning = null;

	/**
	 * Writes the selectielijst categories an app ships; optional, set by the factory.
	 *
	 * @var SelectionListSeeder|null
	 */
	private ?SelectionListSeeder $selectionListSeeder = null;

	/**
	 * The shipped version of each seed object, built on first use from the app config.
	 *
	 * @var SeedVersionLedger|null
	 */
	private ?SeedVersionLedger $seedVersions = null;

	/**
	 * The hash of the content each seed object was last shipped with, loaded on first use.
	 *
	 * @var SeedVersionLedger|null
	 */
	private ?SeedVersionLedger $seedHashes = null;

	/**
	 * Decides what a re-import writes to a stored seed whose version did not move.
	 *
	 * @var ShippedSeedContent
	 */
	private ShippedSeedContent $shippedContent;

	/**
	 * Creates the files, notes and tasks of a seed object.
	 *
	 * @var SeedRelatedItems
	 */
	private SeedRelatedItems $relatedItems;

	/**
	 * Refuses an import on a duplicate slug.
	 *
	 * @var ImportDuplicates
	 */
	private ImportDuplicates $duplicates;

	/**
	 * Refuses a schema that cannot be imported and normalises its properties.
	 *
	 * @var ImportedSchemaShape
	 */
	private ImportedSchemaShape $schemaShape;

	/**
	 * Counts and names the schemas an import rejected.
	 *
	 * @var RejectedSchemaReport
	 */
	private RejectedSchemaReport $rejectedSchemas;

	/**
	 * Collector for declared RBAC group ids. Dependency-free value object,
	 * created lazily via {@see self::rbacGroupCollector()}.
	 *
	 * @var RbacGroupCollector|null
	 */
	private ?RbacGroupCollector $rbacGroupCollector = null;

	/**
	 * Constructor for ImportHandler.
	 *
	 * @param SchemaMapperInterface $schemaMapper The schema mapper.
	 * @param RegisterMapperInterface $registerMapper The register mapper.
	 * @param MagicMapperInterface $objectEntityMapper The object entity mapper.
	 * @param ConfigurationMapper $configurationMapper The configuration mapper.
	 * @param MappingMapper $mappingMapper The mapping mapper.
	 * @param IAppConfig $appConfig The app config.
	 * @param LoggerInterface $logger The logger interface.
	 * @param string $appDataPath The app data path.
	 * @param ObjectOperationsInterface $objectService The object service.
	 * @param ?\OCA\OpenRegister\Service\Oas\OasRequestValidator $schemaShapeValidator Optional schema-shape validator used at import time.
	 * @param ?\OCA\OpenRegister\Service\ShippedBaseline\ShippedConfigurationGuard $shippedGuard Optional
	 *        guard that keeps local changes to an app-shipped schema.
	 * @param ?AppImportJobRecorder $importJobRecorder Stamps each app import with an import job id and
	 *        records the jobs that created objects; null imports untagged, as before.
	 * @param ?SchemaImportInstaller $schemaInstaller Installs a schema's flows and webhooks when an
	 *        import fired no SchemaUpdatedEvent; null leaves an unchanged re-import installing nothing.
	 */
	public function __construct(
		SchemaMapperInterface $schemaMapper,
		RegisterMapperInterface $registerMapper,
		MagicMapperInterface $objectEntityMapper,
		ConfigurationMapper $configurationMapper,
		MappingMapper $mappingMapper,
		IAppConfig $appConfig,
		LoggerInterface $logger,
		string $appDataPath,
		ObjectOperationsInterface $objectService,
		?\OCA\OpenRegister\Service\Oas\OasRequestValidator $schemaShapeValidator = null,
		?\OCA\OpenRegister\Service\ShippedBaseline\ShippedConfigurationGuard $shippedGuard = null,
		private readonly ?AppImportJobRecorder $importJobRecorder = null,
		private readonly ?SchemaImportInstaller $schemaInstaller = null,
	) {
		$this->schemaMapper = $schemaMapper;
		$this->registerMapper = $registerMapper;
		$this->objectEntityMapper = $objectEntityMapper;
		$this->configurationMapper = $configurationMapper;
		$this->mappingMapper = $mappingMapper;
		$this->appConfig = $appConfig;
		$this->logger = $logger;
		$this->appDataPath = $appDataPath;
		$this->objectService = $objectService;
		$this->shippedContent = new ShippedSeedContent();
		$this->relatedItems = new SeedRelatedItems(logger: $logger);
		$this->duplicates = new ImportDuplicates(registerMapper: $registerMapper, schemaMapper: $schemaMapper, logger: $logger);
		$this->shippedBaseline = new ShippedSchemaBaseline(guard: $shippedGuard, logger: $logger);
		$this->appConfigurations = new AppConfigurationRecord(configurationMapper: $configurationMapper, logger: $logger);
		$this->schemaShape = new ImportedSchemaShape(validator: $schemaShapeValidator, logger: $logger);
		$this->rejectedSchemas = new RejectedSchemaReport(logger: $logger);
	}//end __construct()

	/**
	 * Set the OpenConnector ConfigurationService dependency.
	 *
	 * This method allows setting the OpenConnector configuration service
	 * after construction for optional integration.
	 *
	 * @param mixed $service The OpenConnector configuration service.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function setOpenConnectorConfigurationService(mixed $service): void {
		$this->connectorConfigSvc = $service;
	}//end setOpenConnectorConfigurationService()



	/**
	 * Inject the TaskService used by seed-related-items to create VTODO tasks.
	 *
	 * @param TaskService|null $taskService Optional task service.
	 *
	 * @return void
	 */
	public function setTaskService(?TaskService $taskService): void {
		$this->relatedItems->useTaskService($taskService);
	}//end setTaskService()

	/**
	 * Inject the NoteService used by seed-related-items to attach comments.
	 *
	 * @param NoteServiceInterface|null $noteService Optional note service.
	 *
	 * @return void
	 */
	public function setNoteService(?NoteServiceInterface $noteService): void {
		$this->relatedItems->useNoteService($noteService);
	}//end setNoteService()

	/**
	 * Inject the slug handler that decides the slug of a seed object (#4037).
	 *
	 * @param \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugHandler|null $slugHandler Optional slug handler.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
	 */
	public function setSlugHandler(?\OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugHandler $slugHandler): void {
		$this->slugHandler = $slugHandler;
	}//end setSlugHandler()

	/**
	 * Inject the FileService used by seed-related-items to attach files.
	 *
	 * @param FileService|null $fileService Optional file service.
	 *
	 * @return void
	 */
	public function setFileService(?FileService $fileService): void {
		$this->relatedItems->useFileService($fileService);
	}//end setFileService()

	/**
	 * Inject the provisioner importFromApp() uses to give imported registers their folder.
	 *
	 * @param RegisterFolderProvisionerInterface|null $provisioner Optional provisioner; without it the
	 *                                                             first upload makes the folder instead.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-an-app-imported-register-has-its-files-folder-when-the-import-returns-req-rfai-001
	 */
	public function setRegisterFolderProvisioner(?RegisterFolderProvisionerInterface $provisioner): void {
		$this->folderProvisioner = $provisioner;
	}//end setRegisterFolderProvisioner()

	/**
	 * Inject the IUserSession used to detect whether a logged-in actor
	 * exists at seed time. Tasks + notes are skipped without one.
	 *
	 * @param IUserSession|null $userSession Optional user session.
	 *
	 * @return void
	 */
	public function setUserSession(?IUserSession $userSession): void {
		$this->userSession = $userSession;
	}//end setUserSession()

	/**
	 * Inject the IGroupManager used to resolve a fallback admin acting user
	 * when the import runs without a logged-in session (occ/installer/cron).
	 *
	 * @param IGroupManager|null $groupManager Optional group manager.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/import-resilient-per-entity-and-no-user-context/spec.md
	 */
	public function setGroupManager(?IGroupManager $groupManager): void {
		$this->groupManager = $groupManager;
	}//end setGroupManager()

	/**
	 * Inject the IUserManager used as a secondary fallback to resolve any
	 * enabled user as the acting user when the admin group is empty.
	 *
	 * @param IUserManager|null $userManager Optional user manager.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/import-resilient-per-entity-and-no-user-context/spec.md
	 */
	public function setUserManager(?IUserManager $userManager): void {
		$this->userManager = $userManager;
	}//end setUserManager()

	/**
	 * Set the declared-group provisioner.
	 *
	 * Optional: when null, imports run exactly as before and declare no groups.
	 *
	 * @param GroupProvisioner|null $groupProvisioner Optional group provisioner.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function setGroupProvisioner(?GroupProvisioner $groupProvisioner): void {
		$this->groupProvisioner = $groupProvisioner;
	}//end setGroupProvisioner()

	/**
	 * Set the schema versioning service.
	 *
	 * Optional: when null, imported schema changes are written unclassified,
	 * as they were before #4102.
	 *
	 * @param SchemaVersioningService|null $schemaVersioning Optional versioning service.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function setSchemaVersioning(?SchemaVersioningService $schemaVersioning): void {
		$this->schemaVersioning = $schemaVersioning;
	}//end setSchemaVersioning()

	/**
	 * Set the selectielijst seeder.
	 *
	 * Optional: when null, `components.selectionLists` is not imported.
	 *
	 * @param SelectionListSeeder|null $selectionListSeeder The seeder.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function setSelectionListSeeder(?SelectionListSeeder $selectionListSeeder): void {
		$this->selectionListSeeder = $selectionListSeeder;
	}//end setSelectionListSeeder()

	/**
	 * Lazily resolve the dependency-free RBAC group collector.
	 *
	 * @return RbacGroupCollector The collector instance.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function rbacGroupCollector(): RbacGroupCollector {
		if ($this->rbacGroupCollector === null) {
			$this->rbacGroupCollector = new RbacGroupCollector();
		}

		return $this->rbacGroupCollector;
	}//end rbacGroupCollector()

	/**
	 * Create every Nextcloud group this configuration declares.
	 *
	 * Runs BEFORE the content-hash skip check on purpose. Provisioning is a
	 * membership-free `groupExists()`/`createGroup()` pass, so re-running it on
	 * an unchanged configuration costs one existence check per group — and
	 * placing it after the skip would mean a group an administrator deleted by
	 * hand is never restored, because the very configuration that declares it
	 * is the one being skipped.
	 *
	 * The declared set is persisted to app config so the reconciler can restore
	 * groups for apps whose declaration is not readable from disk (a virtual
	 * OpenBuild app ships no `register.json`).
	 *
	 * Never throws: a configuration import that succeeded must not be reported
	 * as failed because a group backend refused a write.
	 *
	 * @param array<string, mixed> $data The decoded configuration document.
	 * @param string|null $appId The declaring app id, when known.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function provisionDeclaredGroups(array $data, ?string $appId): void {
		if ($this->groupProvisioner === null) {
			return;
		}

		try {
			$declared = $this->rbacGroupCollector()->fromDocument(document: $data);
			if (empty($declared) === true) {
				return;
			}

			$this->groupProvisioner->provision(groups: $declared, declaredBy: ($appId ?? 'unknown'));

			if ($appId !== null) {
				$this->appConfig->setValueString(
					'openregister',
					"declared_groups_{$appId}",
					json_encode(array_values($declared))
				);
			}
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[ImportHandler] declared-group provisioning failed: ' . $e->getMessage(),
				context: ['exception' => $e, 'appId' => $appId]
			);
		}//end try
	}//end provisionDeclaredGroups()

	/**
	 * Resolve the acting user for import-time object/folder operations.
	 *
	 * Precedence, mirroring the architecture's documented acting-user rule
	 * (explicit user → session → fallback):
	 *
	 *  1. The logged-in session user, when present (HTTP request path). A real
	 *     session is NEVER overridden, so behaviour is unchanged when a user is
	 *     authenticated.
	 *  2. The first member of the `admin` group (occ/installer/cron path, where
	 *     there is no session).
	 *  3. Any first enabled user, when the admin group cannot be used.
	 *  4. `null` when none is resolvable — callers then skip only the
	 *     user-dependent operation, never the whole import.
	 *
	 * @return IUser|null The resolved acting user, or null when none available.
	 *
	 * @spec openspec/specs/import-resilient-per-entity-and-no-user-context/spec.md
	 */
	private function resolveActingUser(): ?IUser {
		// A real session user always wins - do not override authenticated callers.
		$sessionUser = $this->userSession?->getUser();
		if ($sessionUser !== null) {
			return $sessionUser;
		}

		// No session (occ/installer/cron): prefer the first admin.
		try {
			if ($this->groupManager !== null) {
				$adminGroup = $this->groupManager->get('admin');
				if ($adminGroup !== null) {
					$admins = $adminGroup->getUsers();
					if (count($admins) > 0) {
						return reset($admins);
					}
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[ImportHandler] Failed to resolve admin acting user from admin group: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}

		// Secondary fallback: any first enabled user.
		try {
			if ($this->userManager !== null) {
				$users = $this->userManager->search('', 1, 0);
				if (count($users) > 0) {
					return reset($users);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[ImportHandler] Failed to resolve any fallback acting user: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}

		$this->logger->warning(
			message: '[ImportHandler] No acting user resolvable for import - user-dependent ops (folders) may skip',
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
		return null;
	}//end resolveActingUser()

	/**
	 * Set the MagicMapper dependency for ensuring magic mapper tables exist.
	 *
	 * This method allows setting the MagicMapper after construction for
	 * pre-creating magic mapper tables before seed data import.
	 *
	 * @param MagicMapperInterface $magicMapper The magic mapper instance.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function setMagicMapper(MagicMapperInterface $magicMapper): void {
		$this->magicMapper = $magicMapper;
	}//end setMagicMapper()

	/**
	 * Set the MagicMapper dependency for routing objects to storage.
	 *
	 * This method allows setting the MagicMapper after construction for
	 * routing seed data objects to the correct magic table.
	 *
	 * @param MagicMapperInterface $objectMapper The object mapper instance.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function setObjectMapper(MagicMapperInterface $objectMapper): void {
		$this->routingMapper = $objectMapper;
	}//end setObjectMapper()

	/**
	 * Import a register from configuration data.
	 *
	 * @param array               $data    The register data.
	 * @param SchemaImportOptions $options The owner, app id, version and force flag of the import.
	 *
	 * @return Register The imported register.
	 *
	 * @throws Exception If import fails.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function importRegister(array $data, SchemaImportOptions $options = new SchemaImportOptions()): Register {
		return $this->importRegisterDefinition(data: $data, options: $options);
	}//end importRegister()

	/**
	 * Import a register from configuration data, with the caller's owner, app id, version and force flag.
	 *
	 * @param array               $data    The register data.
	 * @param SchemaImportOptions $options The owner, app id, version and force flag of the import.
	 *
	 * @return Register The imported register.
	 *
	 * @throws Exception If import fails.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function importRegisterDefinition(array $data, SchemaImportOptions $options): Register {
		try {
			// Ensure data is consistently an array by converting any stdClass objects.
			$data = (new ArrayStructure())->toArrays(data: $data);

			// Remove id, uuid, and organisation from the data.
			// Organisation is instance-specific and should not be imported.
			unset($data['id'], $data['uuid'], $data['organisation']);

			$existingRegister = $this->findRegisterForImport(data: $data, options: $options);
			if ($existingRegister === null) {
				return $this->createImportedRegister(data: $data, options: $options);
			}

			// A SKIPPED REGISTER UPDATE MUST STILL ADOPT THE SCHEMAS THIS
			// RUN CREATED.
			//
			// The version gate below is about the register's OWN fields
			// (title, description, …): nothing to write when the incoming
			// definition is not newer. It is NOT about the schema links.
			// `$data['schemas']` arriving here is the id list the schema
			// pass just resolved, and that pass creates rows whatever the
			// register version says — a register.d fragment adds a schema
			// without ever touching the register's version. Returning on
			// the version gate without that list therefore leaves those
			// brand-new schema rows attached to nothing.
			//
			// That orphaning is what makes the NEXT import fork a twin.
			// `computeRegisterScopedSchemaIds()` builds each slug's
			// candidate set from the register's stored `schemas` list, so
			// the missing ids are missing from the set too;
			// `findBySlugInIds()` cannot see the app's own row, and the
			// register-scoped branch of resolveExistingSchemaForImport()
			// concludes — correctly, from what it was given — that the
			// target register does not own this slug yet and creates a new
			// schema. Every re-import repeats it. That is how one
			// opencatalogi install reached 92 schemas with 25 slugs
			// duplicated and page/menu/glossary present three times over
			// (28 Aug / 3 Sep / 4 Sep), each copy identical but for its
			// uuid: the slugs that STAYED in the register's list
			// (publication, document) upserted correctly, and only the
			// unlinked ones forked. See WOO-563 and
			// ImportHandlerRegisterSchemaLinkOnVersionSkipTest.
			//
			// Union, never replace, for the same reason the update path
			// below unions (#2935): a link this run can prove gets added,
			// a link it merely cannot see is left alone.
			// Compare versions using version_compare for proper semver comparison.
			if ($options->force === false && $this->registerVersionIsCurrent(register: $existingRegister, data: $data) === true) {
				$this->logger->debug(
					message: '[ImportHandler] Skipping register import as existing version is newer or equal.',
					context: ['file' => __FILE__, 'line' => __LINE__]
				);

				// Skip the register's own fields, NOT its schema links.
				// The full-update path below already unions them (#2935);
				// this path is the one that used to drop them, so it needs
				// the same union before it returns.
				return $this->linkImportedSchemas(
					register: $existingRegister,
					importedSchemaIds: ($data['schemas'] ?? null)
				);
			}

			$data = $this->withUnionedSchemaIds(register: $existingRegister, data: $data);
			$existingRegister = $this->registerMapper->updateFromArray(id: $existingRegister->getId(), object: $data);
			$this->stampImportProvenance(register: $existingRegister, options: $options);

			return $this->registerMapper->update($existingRegister);
		} catch (Exception $e) {
			$this->logger->error(
				message: '[ImportHandler] Failed to import register: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			throw new Exception('Failed to import register: ' . $e->getMessage());
		}//end try
	}//end importRegisterDefinition()

	/**
	 * Find the stored register an import updates, by slug.
	 *
	 * RBAC and multitenancy are disabled so a register of any app or tenant is
	 * found; a miss here would create a duplicate.
	 *
	 * @param array $data The register data; `slug` is required.
	 * @param SchemaImportOptions $options The caller's app id and version, for the duplicate error.
	 *
	 * @return Register|null The stored register, or null when there is none.
	 *
	 * @throws Exception When several registers share the slug.
	 */
	private function findRegisterForImport(array $data, SchemaImportOptions $options): ?Register {
		try {
			$existingRegister = $this->registerMapper->find(
				id: strtolower($data['slug']),
				_rbac: false,
				_multitenancy: false
			);
			$this->logger->debug(
				message: '[ImportHandler] Found existing register during import',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'slug' => $data['slug'],
					'registerId' => $existingRegister->getId(),
					'application' => $existingRegister->getApplication(),
				]
			);
			return $existingRegister;
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			$this->logger->debug(
				message: "[ImportHandler] Register '{$data['slug']}' not found, will create new one",
				context: ['file' => __FILE__, 'line' => __LINE__, 'appId' => $options->appId]
			);
		} catch (\OCP\AppFramework\Db\MultipleObjectsReturnedException $e) {
			$this->duplicates->refuseRegister(slug: $data['slug'], source: ImportDuplicates::source(appId: $options->appId, version: $options->version));
		}//end try

		return null;
	}//end findRegisterForImport()

	/**
	 * Whether the stored register's version is at or above the incoming one.
	 *
	 * @param Register $register The stored register.
	 * @param array $data The incoming register data.
	 *
	 * @return bool True when the incoming definition is not newer.
	 */
	private function registerVersionIsCurrent(Register $register, array $data): bool {
		$existingVersion = $register->getVersion() ?? '0.0.0';
		return version_compare($data['version'], $existingVersion, '<=') === true;
	}//end registerVersionIsCurrent()

	/**
	 * The incoming data with its schema ids unioned with the register's current ones.
	 *
	 * @param Register $register The stored register.
	 * @param array $data The incoming register data.
	 *
	 * @return array The data to write.
	 */
	private function withUnionedSchemaIds(Register $register, array $data): array {
		if (isset($data['schemas']) === true && is_array($data['schemas']) === true) {
			$existingSchemaIds = $register->getSchemas();
			if (is_array($existingSchemaIds) === true && $existingSchemaIds !== []) {
				$data['schemas'] = $this->unionSchemaIds(
					currentIds: $existingSchemaIds,
					incomingIds: $data['schemas']
				);
			}
		}

		return $data;
	}//end withUnionedSchemaIds()

	/**
	 * Create a register from import data, stamped with the caller's owner and app.
	 *
	 * @param array $data The register data.
	 * @param SchemaImportOptions $options The caller's owner and app id.
	 *
	 * @return Register The created register.
	 */
	private function createImportedRegister(array $data, SchemaImportOptions $options): Register {
		$register = $this->registerMapper->createFromArray($data);
		if ($this->stampImportProvenance(register: $register, options: $options) === true) {
			$register = $this->registerMapper->update($register);
		}

		return $register;
	}//end createImportedRegister()

	/**
	 * Set the caller's owner and app id on a register, where given.
	 *
	 * @param Register $register The register to stamp.
	 * @param SchemaImportOptions $options The caller's owner and app id.
	 *
	 * @return bool True when anything was set.
	 */
	private function stampImportProvenance(Register $register, SchemaImportOptions $options): bool {
		$stamped = false;
		if ($options->owner !== null) {
			$register->setOwner($options->owner);
			$stamped = true;
		}

		if ($options->appId !== null) {
			$register->setApplication($options->appId);
			$stamped = true;
		}

		return $stamped;
	}//end stampImportProvenance()

	/**
	 * Merge two schema-id lists, keeping every id either side can prove.
	 *
	 * ONE implementation of the union rule, called from both places that need
	 * it: the full register update and the version-gated skip path. They used
	 * to carry a copy each, with different `array_unique` flags — harmless for
	 * the ids actually stored (ints, or numeric strings after a JSON round
	 * trip) but exactly the shape that rots, since the rule is stated at length
	 * beside each copy. #2935 was this rule holding in one place and not
	 * another.
	 *
	 * The rule: union, never replace. A link this run can prove gets added, a
	 * link it merely cannot see is left alone. The failure direction is then a
	 * STALE link, which `occ openregister:registers:relink-schemas` reports and
	 * repairs; replacing instead loses reachable data with no error anywhere.
	 *
	 * @param array $currentIds  The ids the register already lists.
	 * @param array $incomingIds The ids this import resolved.
	 *
	 * @return array The merged list, de-duplicated and re-indexed.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function unionSchemaIds(array $currentIds, array $incomingIds): array {
		return array_values(array_unique(array_merge($currentIds, $incomingIds)));
	}//end unionSchemaIds()

	/**
	 * Attach the schema ids this import resolved to an existing register,
	 * without ever removing a link the register already holds.
	 *
	 * Called from the version-gated skip path, which is the one that used to
	 * drop them (the full-update path has unioned since #2935). The schema
	 * pass creates rows independently of the register's version — an app that
	 * adds a schema through a `register.d` fragment routinely leaves the
	 * register's own `version` untouched — so a register that returns early
	 * from the version gate without adopting those ids leaves them orphaned,
	 * and the next import cannot resolve them (see the comment at the call
	 * site, and WOO-563).
	 *
	 * Union semantics, matching the update path: an id this run proved is
	 * added, and an id the register already lists is kept even when this run
	 * could not see it. Persists only when the merge actually adds something,
	 * so a no-op import stays a no-op write.
	 *
	 * @param Register   $register          The register already stored.
	 * @param mixed      $importedSchemaIds The `schemas` value this import resolved (an id list, or null).
	 *
	 * @return Register The register, updated in the database when links were added.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */

	private function linkImportedSchemas(Register $register, mixed $importedSchemaIds): Register {
		if (is_array($importedSchemaIds) === false || $importedSchemaIds === []) {
			return $register;
		}

		$currentIds = $register->getSchemas();
		if (is_array($currentIds) === false) {
			$currentIds = [];
		}

		$merged = $this->unionSchemaIds(currentIds: $currentIds, incomingIds: $importedSchemaIds);

		// Compare against the DEDUPED current list, not the raw one. Comparing
		// against the raw list would report "something changed" for a register
		// whose stored list already held a duplicate, and write a silently
		// de-duplicated row from a path whose whole premise is that it writes
		// nothing. Repairing that duplicate is not this path's job.
		if ($merged === array_values(array_unique($currentIds))) {
			// Nothing new to link — leave the row untouched.
			return $register;
		}

		$this->logger->info(
			message: '[ImportHandler] Linking schemas created by this import to the existing register.',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'registerId' => $register->getId(),
				'registerSlug' => $register->getSlug(),
				'schemasBefore' => count($currentIds),
				'schemasAfter' => count($merged),
			]
		);

		$register->setSchemas($merged);

		// Persist, but hand back the entity we mutated rather than update()'s
		// return value. QBMapper::update() returns that same instance, so the
		// two are identical at runtime; the difference is the declared type.
		// update() is typed as the generic Entity, and a test double that never
		// configured it returns a bare Entity stub, which the Register return
		// type here then rejects with a TypeError — on a path that, before
		// WOO-563, never wrote at all and so never met that stub
		// (ImportServiceRegisterAutoCreateTest, CI run of #3638).
		$this->registerMapper->update($register);

		return $register;
	}//end linkImportedSchemas()

	/**
	 * Import a single mapping from configuration data.
	 *
	 * Creates a new mapping or updates an existing one based on slug matching.
	 * Follows the same find-or-create pattern used by importSchema and importRegister.
	 *
	 * @param ImportRun $run  The import: its slug-to-ID map, configuration, version and force flag.
	 * @param array     $data The mapping data from the JSON config.
	 *
	 * @return Mapping|null The imported mapping entity or null if skipped.
	 *
	 * @throws Exception If import fails.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function importMapping(ImportRun $run, array $data): ?Mapping {
		$slug = $data['slug'] ?? $data['name'] ?? null;

		if ($slug === null) {
			$this->logger->warning(
				message: '[ImportHandler] Mapping has no slug or name — skipping',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}

		// Associate mapping with the configuration.
		$data['configurations'] = [$run->configuration->getUuid()];
		$version = $run->version;

		// Check if mapping already exists by slug.
		$existingMapping = null;
		if (isset($run->slugsAndIdsMap[$slug]) === true) {
			try {
				$existingMapping = $this->mappingMapper->find(id: $run->slugsAndIdsMap[$slug], includeNullOrg: true);
			} catch (Exception $e) {
				$this->logger->debug(
					message: '[ImportHandler] Existing mapping lookup failed, will create new',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'slug' => $slug,
						'error' => $e->getMessage(),
					]
				);
			}
		}

		if ($existingMapping !== null) {
			// Version check: only update if imported version is higher.
			$importedVersion = $data['version'] ?? $version ?? '0.0.1';
			$existingVersion = $existingMapping->getVersion() ?? '0.0.0';

			if ($run->force === false && version_compare($importedVersion, $existingVersion, '<=') === true) {
				$this->logger->debug(
					message: "[ImportHandler] Skipping mapping '{$slug}': v{$importedVersion} <= v{$existingVersion}",
					context: ['file' => __FILE__, 'line' => __LINE__]
				);
				return $existingMapping;
			}

			// Update existing mapping.
			$data['version'] = $importedVersion;
			return $this->mappingMapper->updateFromArray(
				id: $existingMapping->getId(),
				data: $data
			);
		}

		// Create new mapping.
		if (isset($data['version']) === false) {
			$data['version'] = $version ?? '0.0.1';
		}

		return $this->mappingMapper->createFromArray(data: $data);
	}//end importMapping()

	/**
	 * The generation of the rules an import writes by; raised when they change, so a stored hash does not skip it.
	 */
	private const IMPORT_RULES = 'seed-content-3:';

	/**
	 * Whether an incoming schema says anything different from the stored one.
	 *
	 * @param array<string, mixed> $data The incoming schema definition.
	 * @param Schema $existing The schema already stored.
	 *
	 * @return bool True when the properties, the required list, the authorization or the annotations differ.
	 */
	private function schemaContentDiffers(array $data, Schema $existing): bool {
		return (new ImportedSchemaComparison())->differs(data: $data, existing: $existing);
	}//end schemaContentDiffers()

	/**
	 * Resolve the existing schema (if any) for an incoming schema import.
	 *
	 * Dispatches to exactly one of three resolution strategies, most specific
	 * first: register-scoped (PER-REGISTER SLUG-UNIQUENESS, openspec/changes/
	 * per-register-schema-slug-uniqueness) when the caller knows which
	 * register(s) this slug targets, application-scoped as a fallback, and
	 * finally the historical global (organisation-scoped) lookup. Extracted
	 * from {@see importSchema()} as three guard-clause helpers (no branch
	 * falls through to another) so each strategy stays independently readable.
	 *
	 * @param ImportedSchemaLookup $lookup The schema data, the target registers' schema ids and the importing
	 *                                     app id and version (the version for duplicate-error reporting).
	 *
	 * @return Schema|null The resolved existing schema, or null when none matches.
	 */
	private function resolveExistingSchemaForImport(ImportedSchemaLookup $lookup): ?Schema {
		if ($lookup->registerSchemaIds !== null) {
			return $this->resolveSchemaWithinRegisterScope(lookup: $lookup, registerSchemaIds: $lookup->registerSchemaIds);
		}

		$appId = $lookup->options->appId;
		if ($appId !== null && $appId !== '') {
			return $this->resolveSchemaByApplication(data: $lookup->data, appId: $appId);
		}

		return $this->resolveSchemaGlobally(data: $lookup->data, options: $lookup->options);
	}//end resolveExistingSchemaForImport()

	/**
	 * PER-REGISTER SLUG-UNIQUENESS: resolve strictly within the target
	 * register(s)' own (pre-import) schema id set via
	 * `SchemaMapper::findBySlugInIds()`. A same-slug schema owned elsewhere is
	 * only logged for visibility — it is never reused.
	 *
	 * Outside that set, the importing app's OWN row with this slug that no
	 * register lists is adopted rather than forked: an import that stopped
	 * after creating its schemas and before linking them leaves such a row,
	 * and scoping to the register's list alone made every later import create
	 * a twin (dossiq, 29 Sep: 17 slugs twice).
	 *
	 * @param ImportedSchemaLookup $lookup            The schema data and the importing application id.
	 * @param array                $registerSchemaIds Pre-import schema ids of the target register(s).
	 *
	 * @return Schema|null The schema already in scope, or null (create a new one).
	 */
	private function resolveSchemaWithinRegisterScope(ImportedSchemaLookup $lookup, array $registerSchemaIds): ?Schema {
		$data = $lookup->data;
		$appId = $lookup->options->appId;
		$existingSchema = $this->schemaMapper->findBySlugInIds(slug: $data['slug'], schemaIds: $registerSchemaIds);
		if ($existingSchema !== null) {
			return $existingSchema;
		}

		$orphan = $this->findOwnUnlinkedSchema(slug: (string)$data['slug'], appId: $appId);
		if ($orphan !== null) {
			return $orphan;
		}

		// Visibility only: a same-slug row exists but is not (yet) in the
		// target register's own set. The caller still (correctly) creates its
		// own schema rather than binding to this one.
		try {
			$foreign = $this->schemaMapper->find($data['slug'], _multitenancy: false);
			if ($foreign->getId() !== null) {
				$this->logger->debug(
					message: sprintf(
						"[ImportHandler] Schema slug '%s' already exists elsewhere (schema id %d) but "
						. "not in the target register's schema set; creating this register's OWN schema.",
						$data['slug'],
						$foreign->getId()
					),
					context: ['file' => __FILE__, 'line' => __LINE__, 'slug' => $data['slug']]
				);
			}
		} catch (\Throwable $ignore) {
			// No pre-existing schema anywhere (or ambiguous) — nothing to note.
			$this->logger->debug(
				message: '[ImportHandler] No unambiguous pre-existing schema outside the register scope',
				context: [
					'slug' => $data['slug'],
					'exception' => $ignore->getMessage(),
				]
			);
		}

		return null;
	}//end resolveSchemaWithinRegisterScope()

	/**
	 * The importing app's own schema row with this slug that no register
	 * lists, the highest version when there are several; null without one or
	 * without an app id.
	 *
	 * @param string      $slug  The schema slug.
	 * @param string|null $appId The importing application id.
	 *
	 * @return Schema|null The row to adopt, or null.
	 *
	 * @spec openspec/specs/schema-import/spec.md#requirement-an-import-adopts-its-apps-own-unlinked-schema
	 */
	private function findOwnUnlinkedSchema(string $slug, ?string $appId): ?Schema {
		if ($appId === null || $appId === '' || $slug === '') {
			return null;
		}

		try {
			$candidates = $this->schemaMapper->findBySlug(slug: $slug, limit: 50, _rbac: false);
		} catch (\Throwable $e) {
			return null;
		}

		$best = null;
		foreach ($candidates as $candidate) {
			if ($candidate->getApplication() !== $appId || $candidate->getSlug() !== $slug) {
				continue;
			}

			if ($this->registerMapper->getAllRegisterIdsWithSchema((int)$candidate->getId()) !== []) {
				continue;
			}

			if ($best === null || version_compare((string)($candidate->getVersion() ?? '0'), (string)($best->getVersion() ?? '0')) > 0) {
				$best = $candidate;
			}
		}

		return $best;
	}//end findOwnUnlinkedSchema()

	/**
	 * Application-scoped fallback: resolve via
	 * `SchemaMapper::findByApplicationAndSlug()`. Used only when the caller
	 * has no register context for this slug (see
	 * {@see resolveExistingSchemaForImport()}).
	 *
	 * @param array $data The schema data (MUST carry a non-empty `slug`).
	 * @param string $appId The importing application id.
	 *
	 * @return Schema|null The app-owned schema, or null (create a new one).
	 */
	private function resolveSchemaByApplication(array $data, string $appId): ?Schema {
		$existingSchema = $this->schemaMapper->findByApplicationAndSlug(slug: $data['slug'], application: $appId);
		if ($existingSchema !== null) {
			return $existingSchema;
		}

		// Visibility: if we own none but a DIFFERENT app already owns the
		// slug, surface the collision instead of silently forking. The
		// caller still (correctly) creates its own schema.
		try {
			$foreign = $this->schemaMapper->find($data['slug'], _multitenancy: false);
			$foreignApp = $foreign->getApplication();
			if ($foreignApp !== null && $foreignApp !== '' && $foreignApp !== $appId) {
				$this->logger->warning(
					message: sprintf(
						"[ImportHandler] Schema slug '%s' is already owned by app '%s'; "
						. "app '%s' will create its OWN schema to avoid a cross-app collision.",
						$data['slug'],
						$foreignApp,
						$appId
					),
					context: ['file' => __FILE__, 'line' => __LINE__, 'slug' => $data['slug']]
				);
			}
		} catch (\Throwable $ignore) {
			// No pre-existing schema (or ambiguous) — nothing to warn about.
			$this->logger->debug(
				message: '[ImportHandler] No unambiguous pre-existing schema to check the owning app of',
				context: [
					'slug' => $data['slug'],
					'exception' => $ignore->getMessage(),
				]
			);
		}

		return null;
	}//end resolveSchemaByApplication()

	/**
	 * Historical global (organisation-scoped) fallback: resolve via
	 * `SchemaMapper::find()`. Used only when the caller has neither register
	 * nor application context for this slug (see
	 * {@see resolveExistingSchemaForImport()}) — manual/UI-driven single
	 * imports, preserved for backward compatibility.
	 *
	 * @param array               $data    The schema data (MUST carry a non-empty `slug`).
	 * @param SchemaImportOptions $options The importing application id and version, for duplicate-error reporting.
	 *
	 * @return Schema|null The globally-resolved schema, or null (create a new one).
	 */
	private function resolveSchemaGlobally(array $data, SchemaImportOptions $options): ?Schema {
		try {
			return $this->schemaMapper->find($data['slug'], _multitenancy: false);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			$msg = "Schema '{$data['slug']}' not found, will create new one";
			$this->logger->debug(message: '[ImportHandler] ' . $msg, context: ['file' => __FILE__, 'line' => __LINE__]);
		} catch (\OCA\OpenRegister\Exception\ValidationException $e) {
			$msg = "Schema '{$data['slug']}' not found (ValidationException), will create new one";
			$this->logger->debug(message: '[ImportHandler] ' . $msg, context: ['file' => __FILE__, 'line' => __LINE__]);
		} catch (\OCP\AppFramework\Db\MultipleObjectsReturnedException $e) {
			$this->duplicates->refuseSchema(slug: $data['slug'], source: ImportDuplicates::source(appId: $options->appId, version: $options->version));
		}

		return null;
	}//end resolveSchemaGlobally()

	/**
	 * Import a schema from configuration data.
	 *
	 * @param array $data The schema data with slugs to be converted to IDs.
	 * @param array $slugsAndIdsMap Slugs with their IDs for quick lookup.
	 * @param string|null $owner The owner of the schema.
	 * @param string|null $appId The application ID importing the schema.
	 * @param string|null $version The version of the import.
	 * @param bool $force Force import even if version is not newer.
	 * @param array|null $registerSchemaIds PER-REGISTER SLUG-UNIQUENESS (openspec/changes/
	 *                                      per-register-schema-slug-uniqueness): when non-null,
	 *                                      the pre-import schema ids of the register(s) this
	 *                                      import declares this slug for. The existing schema is
	 *                                      resolved strictly within this set (not globally, not
	 *                                      merely per-application) so two different registers may
	 *                                      each own a distinct schema with the same slug, while a
	 *                                      schema legitimately shared by multiple registers stays
	 *                                      one row. `null` preserves the previous
	 *                                      application-scoped/global fallback for schemas with no
	 *                                      register context in this import.
	 *
	 * @return Schema The imported schema.
	 *
	 * @throws Exception If import fails.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Force flag to override version checks
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function importSchema(
		array $data,
		array $slugsAndIdsMap,
		?string $owner = null,
		?string $appId = null,
		?string $version = null,
		bool $force = false,
		?array $registerSchemaIds = null,
	): Schema {
		$options = new SchemaImportOptions(owner: $owner, appId: $appId, version: $version, force: $force);
		$definition = new ImportedSchemaLookup(data: $data, registerSchemaIds: $registerSchemaIds, options: $options);
		return $this->importSchemaDefinition(definition: $definition, slugsAndIdsMap: $slugsAndIdsMap);
	}//end importSchema()

	/**
	 * Import a schema definition, resolved within the target registers when they are known.
	 *
	 * @param ImportedSchemaLookup $definition     The schema data, the target registers' schema ids and the
	 *                                             owner, app id, version and force flag of the import.
	 * @param array                $slugsAndIdsMap Slugs with their IDs for quick lookup.
	 *
	 * @return Schema The imported schema.
	 *
	 * @throws Exception If import fails.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function importSchemaDefinition(ImportedSchemaLookup $definition, array $slugsAndIdsMap): Schema {
		$data = $definition->data;
		$options = $definition->options;
		$this->schemaShape->assertShape(data: $data);
		$this->schemaShape->assertSlug(data: $data);

		try {
			unset($data['id'], $data['uuid'], $data['organisation']);
			$data = $this->withoutOrphanCleanupForNonAdmin(data: $data);
			$data = $this->normaliseImportedProperties(data: $data, slugsAndIdsMap: $slugsAndIdsMap);

			// A slug is resolved within the target register(s) when the caller
			// knows them (per-register-schema-slug-uniqueness), so an app never
			// reuses another register's schema that merely shares the slug.
			$lookup = new ImportedSchemaLookup(data: $data, registerSchemaIds: $definition->registerSchemaIds, options: $options);
			$existingSchema = $this->resolveExistingSchemaForImport(lookup: $lookup);

			if ($existingSchema !== null) {
				$update = new ImportedSchemaUpdate(data: $data, existing: $existingSchema);
				return $this->withUnlinkableActionsAsWarnings(save: fn (): Schema => $this->updateImportedSchema(update: $update, options: $options));
			}

			return $this->withUnlinkableActionsAsWarnings(save: fn (): Schema => $this->createImportedSchema(data: $data, options: $options));
		} catch (Exception $e) {
			$this->logger->error(
				message: '[ImportHandler] Failed to import schema: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			throw new Exception('Failed to import schema: ' . $e->getMessage(), $e->getCode(), $e);
		}//end try
	}//end importSchemaDefinition()

	/**
	 * Run a schema write of the import with an unlinkable notification url action as a warning.
	 *
	 * Like every other notification fault, it must not abort the register on import.
	 *
	 * @param callable(): Schema $save The write.
	 *
	 * @return Schema The stored schema.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function withUnlinkableActionsAsWarnings(callable $save): Schema {
		$wasWarning = $this->schemaMapper->warnOnUnlinkableNotificationActions();
		try {
			return $save();
		} finally {
			if ($wasWarning === false) {
				$this->schemaMapper->refuseUnlinkableNotificationActions();
			}
		}
	}//end withUnlinkableActionsAsWarnings()

	/**
	 * Drop `orphanCleanup` from a schema a signed-in non-administrator imports.
	 *
	 * The flag makes saves soft-delete rows and importing into a register needs only
	 * its `manage` rule. An import without a session (an app at boot, a repair step)
	 * keeps the flag.
	 *
	 * @param array $data The schema data.
	 *
	 * @return array The schema data.
	 *
	 * @spec openspec/specs/referential-integrity/spec.md
	 */
	private function withoutOrphanCleanupForNonAdmin(array $data): array {
		$user = $this->userSession?->getUser();
		if ($user === null || $this->groupManager?->isAdmin($user->getUID()) === true) {
			return $data;
		}

		$configuration = ($data['configuration'] ?? null);
		if (is_string($configuration) === true) {
			$configuration = json_decode($configuration, associative: true);
		}

		if (is_array($configuration) === true && array_key_exists('orphanCleanup', $configuration) === true) {
			unset($configuration['orphanCleanup']);
			$data['configuration'] = $configuration;
		}

		return $data;
	}//end withoutOrphanCleanupForNonAdmin()

	/**
	 * Normalise every property of an imported schema and map its slugs to ids.
	 *
	 * @param array $data           The schema data.
	 * @param array $slugsAndIdsMap Slugs with their IDs from this import.
	 *
	 * @return array The schema data.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function normaliseImportedProperties(array $data, array $slugsAndIdsMap): array {
		if (($data['properties'] ?? null) === null) {
			return $data;
		}

		$references = new ImportReferenceMap(slugsAndIds: $slugsAndIdsMap);
		foreach ($data['properties'] as $key => &$property) {
			$property = $this->schemaShape->normalisedProperty(key: $key, property: $property);
			$property = $this->normaliseImportedProperty(property: $property, references: $references);
		}

		unset($property);
		return $data;
	}//end normaliseImportedProperties()

	/**
	 * Map one imported property's slugs to ids.
	 *
	 * @param array              $property   The property definition, its shape already normalised.
	 * @param ImportReferenceMap $references This import's slug-to-id map.
	 *
	 * @return array The normalised property.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function normaliseImportedProperty(array $property, ImportReferenceMap $references): array {
		$property = $this->mapImportedPropertySchemaRefs(property: $property, references: $references);
		$property = $this->resolveImportedObjectConfigurations(property: $property);

		return $this->mapImportedLegacyRegisters(property: $property, references: $references);
	}//end normaliseImportedProperty()

	/**
	 * Map a property's `$ref` and its `items.$ref` from slugs to schema ids.
	 *
	 * The items reference is written to `items.$ref`, never to the array
	 * property's own `$ref`: an int `$ref` on an array-typed property survives
	 * into the schema handed to Opis, which then fails with a message naming
	 * neither property nor schema.
	 *
	 * @param array              $property   The property definition.
	 * @param ImportReferenceMap $references This import's slug-to-id map.
	 *
	 * @return array The property.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function mapImportedPropertySchemaRefs(array $property, ImportReferenceMap $references): array {
		if (($property['$ref'] ?? null) !== null) {
			$property['$ref'] = $references->resolve(reference: $property['$ref'], entities: $this->schemasMap);
		}

		if (($property['items']['$ref'] ?? null) !== null) {
			$property['items']['$ref'] = $references->resolve(reference: $property['items']['$ref'], entities: $this->schemasMap);
		}

		return $property;
	}//end mapImportedPropertySchemaRefs()

	/**
	 * Map the legacy `register` keys of a property and its items from slugs to ids.
	 *
	 * @param array              $property   The property definition.
	 * @param ImportReferenceMap $references This import's slug-to-id map.
	 *
	 * @return array The property.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function mapImportedLegacyRegisters(array $property, ImportReferenceMap $references): array {
		if (($property['register'] ?? null) !== null) {
			$property['register'] = $references->resolve(reference: $property['register'], entities: $this->registersMap);
		}

		if (is_array($property['items'] ?? null) === true && isset($property['items']['register']) === true) {
			$property['items']['register'] = $references->resolve(reference: $property['items']['register'], entities: $this->registersMap);
		}

		return $property;
	}//end mapImportedLegacyRegisters()

	/**
	 * Resolve the register and schema slugs in a property's and its items' `objectConfiguration`.
	 *
	 * @param array $property The property definition.
	 *
	 * @return array The property.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function resolveImportedObjectConfigurations(array $property): array {
		$property = $this->withResolvedObjectConfiguration(definition: $property, where: 'schema property');

		if (($property['items'] ?? null) !== null && is_object($property['items']) === true) {
			$property['items'] = (array)$property['items'];
		}

		if (is_array($property['items'] ?? null) === false) {
			return $property;
		}

		$property['items'] = $this->withResolvedObjectConfiguration(definition: $property['items'], where: 'array items schema property');

		return $property;
	}//end resolveImportedObjectConfigurations()

	/**
	 * Resolve the register and schema slugs of a definition's `objectConfiguration`, object form included.
	 *
	 * @param array  $definition The property or items definition.
	 * @param string $where      Where the configuration sits, for the log.
	 *
	 * @return array The definition.
	 */
	private function withResolvedObjectConfiguration(array $definition, string $where): array {
		if (is_object($definition['objectConfiguration'] ?? null) === true) {
			$definition['objectConfiguration'] = (array)$definition['objectConfiguration'];
		}

		if (is_array($definition['objectConfiguration'] ?? null) === true) {
			$definition['objectConfiguration'] = $this->resolveConfigurationSlugs(config: $definition['objectConfiguration'], where: $where);
		}

		return $definition;
	}//end withResolvedObjectConfiguration()

	/**
	 * Resolve the `register` and `schema` slugs of one objectConfiguration to ids.
	 *
	 * @param array  $config The objectConfiguration.
	 * @param string $where  Where the configuration sits, for the log message.
	 *
	 * @return array The configuration.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function resolveConfigurationSlugs(array $config, string $where): array {
		if (($config['register'] ?? null) !== null) {
			$config = $this->resolveConfigurationRegister(config: $config, where: $where);
		}

		if (($config['schema'] ?? null) !== null) {
			$config = $this->resolveConfigurationSchema(config: $config, where: $where);
		}

		return $config;
	}//end resolveConfigurationSlugs()

	/**
	 * Resolve an objectConfiguration's `register` slug to an id.
	 *
	 * A register not yet known is looked up and cached; one that does not
	 * exist yet is dropped and resolved after the registers are imported.
	 *
	 * @param array  $config The objectConfiguration.
	 * @param string $where  Where the configuration sits, for the log message.
	 *
	 * @return array The configuration.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function resolveConfigurationRegister(array $config, string $where): array {
		$registerSlug = (string)$config['register'];
		if (($this->registersMap[$registerSlug] ?? null) !== null) {
			$config['register'] = $this->registersMap[$registerSlug]->getId();
			return $config;
		}

		if ($registerSlug === '') {
			return $config;
		}

		try {
			$existingRegister = $this->registerMapper->find($registerSlug, _multitenancy: false);
			$config['register'] = $existingRegister->getId();
			$this->registersMap[$registerSlug] = $existingRegister;
		} catch (\OCP\AppFramework\Db\DoesNotExistException|ValidationException $e) {
			$msg = 'Register with slug %s not found during %s import (will be resolved after registers are imported).';
			$this->logger->debug(
				message: '[ImportHandler] ' . sprintf($msg, $registerSlug, $where),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			unset($config['register']);
		}

		return $config;
	}//end resolveConfigurationRegister()

	/**
	 * Resolve an objectConfiguration's `schema` slug to an id.
	 *
	 * A schema not yet known is looked up and cached; one that does not
	 * exist yet is dropped and resolved after the schemas are imported.
	 *
	 * @param array  $config The objectConfiguration.
	 * @param string $where  Where the configuration sits, for the log message.
	 *
	 * @return array The configuration.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function resolveConfigurationSchema(array $config, string $where): array {
		$schemaSlug = (string)$config['schema'];
		if ($schemaSlug === '') {
			return $config;
		}

		if (($this->schemasMap[$schemaSlug] ?? null) !== null) {
			$config['schema'] = $this->schemasMap[$schemaSlug]->getId();
			return $config;
		}

		try {
			$existingSchema = $this->schemaMapper->find($schemaSlug, _multitenancy: false);
			$config['schema'] = $existingSchema->getId();
			$this->schemasMap[$schemaSlug] = $existingSchema;
		} catch (\OCP\AppFramework\Db\DoesNotExistException|ValidationException $e) {
			$msg = 'Schema with slug %s not found during %s import (will be resolved after schemas are imported).';
			$this->logger->debug(
				message: '[ImportHandler] ' . sprintf($msg, $schemaSlug, $where),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			unset($config['schema']);
		}

		return $config;
	}//end resolveConfigurationSchema()

	/**
	 * Update an existing schema from an import, unless nothing changed.
	 *
	 * The incoming definition is first passed through the shipped-baseline
	 * guard, so a property an instance added survives the upgrade; the
	 * baseline is recorded only after the write succeeds.
	 *
	 * @param ImportedSchemaUpdate $update  The normalised definition and the stored schema.
	 * @param SchemaImportOptions  $options The caller's settings.
	 *
	 * @return Schema The stored schema.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function updateImportedSchema(ImportedSchemaUpdate $update, SchemaImportOptions $options): Schema {
		if ($this->isUnchangedReimport(update: $update, options: $options) === true) {
			return $update->existing;
		}

		return $this->writeChangedSchema(update: $update, options: $options);
	}//end updateImportedSchema()

	/**
	 * Write a schema an import changed, with its version and changelog entry.
	 *
	 * @param ImportedSchemaUpdate $update  The normalised definition and the stored schema.
	 * @param SchemaImportOptions  $options The caller's settings.
	 *
	 * @return Schema The stored schema.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function writeChangedSchema(ImportedSchemaUpdate $update, SchemaImportOptions $options): Schema {
		$existing = $update->existing;
		$changeLog = new ImportedSchemaChangeLog(versioning: $this->schemaVersioning, logger: $this->logger, appId: $options->appId);
		$guarded = $this->shippedBaseline->resolve(update: $update, options: $options);
		$changeSet = $changeLog->classify(existing: $existing, data: $guarded->data);
		$data = $guarded->data;
		if (version_compare($update->incomingVersion(), $update->existingVersion(), '>') === false) {
			$data = $this->withRetainedSchemaVersion(update: new ImportedSchemaUpdate(data: $data, existing: $existing), changeSet: $changeSet);
		}

		$schema = $this->storeUpdatedSchema(update: new ImportedSchemaUpdate(data: $data, existing: $existing), options: $options);
		$this->shippedBaseline->recordPending(pending: $guarded, options: $options);
		$changeLog->record(schema: $schema, changeSet: $changeSet);

		return $schema;
	}//end writeChangedSchema()

	/**
	 * Write an updated schema with the import's ownership, installing it when no event did.
	 *
	 * @param ImportedSchemaUpdate $update  The definition to write and the stored schema.
	 * @param SchemaImportOptions  $options The caller's settings.
	 *
	 * @return Schema The stored schema.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function storeUpdatedSchema(ImportedSchemaUpdate $update, SchemaImportOptions $options): Schema {
		$id = $update->existing->getId();
		$eventsBefore = $this->schemaMapper->updateEventCount(schemaId: (int)$id);
		$schema = $this->schemaMapper->updateFromArray(id: $id, object: $update->data);
		$schema = $this->schemaMapper->update($this->withImportOwnership(schema: $schema, options: $options));
		$this->installWhenNoEventFired(schema: $schema, eventsBefore: $eventsBefore);

		return $schema;
	}//end storeUpdatedSchema()

	/**
	 * Whether a re-import may be skipped: its version is not newer and its content is unchanged.
	 *
	 * The version is an optimisation, not the source of truth: apps change a
	 * schema through a register.d fragment without bumping its version, so a
	 * content difference still applies the update (#2075/#2082).
	 *
	 * @param ImportedSchemaUpdate $update  The normalised definition and the stored schema.
	 * @param SchemaImportOptions  $options The caller's settings.
	 *
	 * @return bool True when the import changes nothing.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function isUnchangedReimport(ImportedSchemaUpdate $update, SchemaImportOptions $options): bool {
		if ($options->force === true || version_compare($update->incomingVersion(), $update->existingVersion(), '<=') === false) {
			return false;
		}

		if ($this->schemaContentDiffers(data: $update->data, existing: $update->existing) === false) {
			$this->logger->debug(
				message: '[ImportHandler] Skipping schema import: version not newer and content unchanged.',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return true;
		}

		$this->logger->info(
			message: '[ImportHandler] Schema version not newer but content differs; applying update anyway.',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'schema_slug' => $update->existing->getSlug(),
				'schema_id' => $update->existing->getId(),
			]
		);
		return false;
	}//end isUnchangedReimport()

	/**
	 * The schema data with the stored version kept, bumped when the change classification says so.
	 *
	 * Used when the import's version is not newer: an import never moves a
	 * schema's version back (#4163).
	 *
	 * @param ImportedSchemaUpdate $update    The guarded definition and the stored schema.
	 * @param SchemaChangeSet|null $changeSet The classified change, null when not classified.
	 *
	 * @return array The data.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function withRetainedSchemaVersion(ImportedSchemaUpdate $update, ?SchemaChangeSet $changeSet): array {
		$data = $update->data;
		if ($update->existing->getVersion() !== null) {
			$data['version'] = $update->existingVersion();
		}

		if ($changeSet !== null && $changeSet->hasChanges() === true) {
			$data['version'] = $this->schemaVersioning->nextVersion(existing: $update->existing, changeSet: $changeSet);
		}

		return $data;
	}//end withRetainedSchemaVersion()

	/**
	 * Create a schema from an import.
	 *
	 * @param array               $data    The normalised schema data.
	 * @param SchemaImportOptions $options The caller's settings.
	 *
	 * @return Schema The stored schema.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function createImportedSchema(array $data, SchemaImportOptions $options): Schema {
		$schema = $this->schemaMapper->createFromArray($data);
		$this->shippedBaseline->recordCreated(data: $data, options: $options);

		return $this->schemaMapper->update($this->withImportOwnership(schema: $schema, options: $options));
	}//end createImportedSchema()

	/**
	 * Set the import's owner and application on a schema, where given.
	 *
	 * @param Schema              $schema  The schema.
	 * @param SchemaImportOptions $options The caller's settings.
	 *
	 * @return Schema The schema.
	 */
	private function withImportOwnership(Schema $schema, SchemaImportOptions $options): Schema {
		if ($options->owner !== null) {
			$schema->setOwner($options->owner);
		}

		if ($options->appId !== null) {
			$schema->setApplication($options->appId);
		}

		return $schema;
	}//end withImportOwnership()

	/**
	 * Run the schema installers when the import's saves fired no update event.
	 *
	 * A save that changed nothing fires no SchemaUpdatedEvent, so the listeners
	 * that install a schema's flows and notification webhooks did not run. The
	 * import runs them itself, so a re-import still restores what the schema
	 * declares. When an event did fire, the listeners already ran and this
	 * does nothing, so nothing runs twice.
	 *
	 * @param Schema $schema The imported schema, as saved.
	 * @param int $eventsBefore SchemaMapper::updateEventCount() before the import's saves.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-an-import-of-an-unchanged-schema-still-installs-what-it-declares
	 */
	private function installWhenNoEventFired(Schema $schema, int $eventsBefore): void {
		if ($this->schemaInstaller === null) {
			return;
		}

		if ($this->schemaMapper->updateEventCount(schemaId: (int)$schema->getId()) !== $eventsBefore) {
			return;
		}

		$this->schemaInstaller->install(schema: $schema);
	}//end installWhenNoEventFired()

	/**
	 * Compute a stable content hash of a configuration's definitional payload.
	 *
	 * Used by the app-level import fast-skip (#426): the skip fires only when the app
	 * version is not newer AND this hash matches the last import, so a schema/register
	 * definition change re-imports even when the app version is unchanged. The hash
	 * covers `components` (registers, schemas, objects, mappings, …) — the parts whose
	 * changes should re-trigger the version-gated import — and falls back to the whole
	 * payload when `components` is absent. Key order is taken from the source config
	 * files (deterministic for an unchanged release), so no normalisation is needed; a
	 * reordering at most triggers one extra import pass. The import's own rules
	 * (IMPORT_RULES) are part of the hash: when they change, the same content
	 * imports differently, so every app imports once more.
	 *
	 * @param array $data The configuration data (pre-mutation snapshot).
	 *
	 * @return string A hex sha256 digest of the definitional payload.
	 */
	private function computeDefinitionHash(array $data): string {
		$definitional = ($data['components'] ?? $data);
		$encoded = json_encode($definitional);
		if ($encoded === false) {
			$encoded = '';
		}

		return hash('sha256', self::IMPORT_RULES.$encoded);
	}//end computeDefinitionHash()

	/**
	 * Compute, per schema slug, the union of the schema ids already attached
	 * (pre-import) to the register(s) this import declares that slug for.
	 *
	 * PER-REGISTER SLUG-UNIQUENESS (openspec/changes/per-register-schema-slug-uniqueness).
	 * Reads the RAW `components.registers.*.schemas` slug lists — this MUST run
	 * before the schema import pass mutates anything, since that pass is what
	 * later replaces those slug lists with resolved numeric ids (~:1975). For
	 * every register a slug is declared under, the register's CURRENT (i.e.
	 * pre-this-import) `Register::getSchemas()` id list contributes to that
	 * slug's candidate set; a register that does not exist yet contributes an
	 * empty set (nothing to resolve against — the slug must be created fresh).
	 * A slug declared by more than one register in this import unions all of
	 * their existing ids, so a schema intentionally shared across registers
	 * (the many-to-many case) still resolves correctly for either of them.
	 *
	 * A schema slug that no register in this import declares (rare: a schema
	 * defined standalone in `components.schemas` without any
	 * `components.registers.*.schemas` reference) is simply absent from the
	 * returned map; `importSchema()` treats a missing key the same as `null`
	 * and falls back to its previous application-scoped/global resolution.
	 *
	 * @param array $data The full configuration payload, pre-mutation.
	 *
	 * @return array<string, int[]> Lower-cased schema slug => candidate schema ids.
	 */
	private function computeRegisterScopedSchemaIds(array $data): array {
		$registers = ($data['components']['registers'] ?? null);
		if (is_array($registers) === false) {
			return [];
		}

		// Lower(schemaSlug) => [lower(registerSlug), ...] this import's registers
		// declare wanting that slug.
		$registersForSlug = [];
		foreach ($registers as $registerSlug => $registerData) {
			if (is_array($registerData) === false) {
				continue;
			}

			$schemaSlugs = ($registerData['schemas'] ?? null);
			if (is_array($schemaSlugs) === false) {
				continue;
			}

			$registerSlugLower = strtolower((string)$registerSlug);
			foreach ($schemaSlugs as $schemaSlug) {
				if (is_string($schemaSlug) === false || $schemaSlug === '') {
					continue;
				}

				$registersForSlug[strtolower($schemaSlug)][] = $registerSlugLower;
			}
		}//end foreach

		if ($registersForSlug === []) {
			return [];
		}

		// Lower(registerSlug) => its pre-import schema id list, fetched once and
		// cached across every schema slug that references the same register.
		$regSchemaIds = [];
		$result = [];
		foreach ($registersForSlug as $schemaSlugLower => $registerSlugs) {
			$ids = [];
			foreach (array_unique($registerSlugs) as $registerSlugLower) {
				if (array_key_exists($registerSlugLower, $regSchemaIds) === false) {
					try {
						// CRITICAL: disable RBAC and multitenancy, mirroring importRegister()'s
						// own lookup (~:764) — this precompute must see a register that exists
						// regardless of the importing context's current tenant/permissions, or a
						// real register would be misread as "doesn't exist yet" and this slug
						// would wrongly fork a duplicate schema instead of resolving to the
						// existing one.
						$existingRegister = $this->registerMapper->find(
							id: $registerSlugLower,
							_rbac: false,
							_multitenancy: false
						);
						$regSchemaIds[$registerSlugLower] = $existingRegister->getSchemas();
					} catch (\Throwable $ignore) {
						// Register does not exist yet (fresh import) — nothing to scope against.
						$regSchemaIds[$registerSlugLower] = [];
					}
				}

				foreach ($regSchemaIds[$registerSlugLower] as $candidateId) {
					if (is_numeric($candidateId) === true) {
						$ids[] = (int)$candidateId;
					}
				}
			}//end foreach

			$result[$schemaSlugLower] = array_values(array_unique($ids));
		}//end foreach

		return $result;
	}//end computeRegisterScopedSchemaIds()

	/**
	 * Import configuration data from JSON structure.
	 *
	 * This is the core import method that processes all configuration components
	 * including schemas, registers, and objects. It handles version checking,
	 * entity mapping, and optional OpenConnector integration.
	 *
	 * @param array $data The configuration data to import.
	 * @param Configuration|null $configuration The configuration entity for tracking (REQUIRED).
	 * @param string|null $owner The owner of the imported entities.
	 * @param string|null $appId The application ID.
	 * @param string|null $version The configuration version.
	 * @param bool $force Force import regardless of version checks.
	 *
	 * @return array The import results containing created/updated entities.
	 *
	 * @throws Exception If configuration entity is missing or import fails.
	 *
	 * @phpstan-return array{
	 *     registers: array<Register>,
	 *     schemas: array<Schema>,
	 *     objects: array<ObjectEntity>,
	 *     endpoints: array,
	 *     sources: array,
	 *     mappings: array<Mapping>,
	 *     jobs: array,
	 *     synchronizations: array,
	 *     rules: array,
	 *     skipped: array{registers: int, schemas: int, objects: int, mappings: int, seedObjects: int},
	 *     failed: array{schemas: list<array{key: string, slug: string, error: string}>}
	 * }
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Force flag to override version checks
	 * @SuppressWarnings(PHPMD.NPathComplexity)       JSON import requires many conditional transformations
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Multi-component import has many branching conditions
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Full configuration import involves many entity types
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function importFromJson(
		array $data,
		?Configuration $configuration = null,
		?string $owner = null,
		?string $appId = null,
		?string $version = null,
		bool $force = false,
	): array {
		// CRITICAL: Configuration entity is required for proper tracking.
		if ($configuration === null) {
			$errorMsg = 'importFromJson must be called with a Configuration entity. ';
			$errorMsg .= 'Direct imports without a Configuration are not allowed to ensure ';
			$errorMsg .= 'proper entity tracking. Please create a Configuration entity first before importing.';
			throw new Exception($errorMsg);
		}

		// Ensure data is consistently an array by converting any stdClass objects.
		$run = new ImportRun(data: (new ArrayStructure())->toArrays(data: $data), configuration: $configuration);
		$run->owner = $owner;
		// Extract appId and version from data if not provided as parameters.
		$run->appId = $appId ?? ($run->data['appId'] ?? null);
		$run->version = $version ?? ($run->data['version'] ?? null);
		$run->force = $force;

		return $this->runImport(run: $run);
	}//end importFromJson()

	/**
	 * Run every phase of a configuration import.
	 *
	 * @param ImportRun $run The import.
	 *
	 * @return array The import result.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function runImport(ImportRun $run): array {
		// Content hash of the definitional payload, computed ONCE from the incoming
		// data before any import step mutates it (schema import resolves $refs and
		// stamps ids into it). The same value is reused for the skip check below and
		// for the post-import store, so an unchanged release always produces an
		// identical hash on the next run.
		$run->definitionHash = $this->computeDefinitionHash(data: $run->data);

		// Create the Nextcloud groups this configuration declares. Deliberately
		// ahead of the content-hash skip below: the skip means "the stored data
		// already matches", which says nothing about whether the GROUPS those
		// authorization blocks name still exist.
		$this->provisionDeclaredGroups(data: $run->data, appId: $run->appId);

		if ($this->isUnchangedConfiguration(run: $run) === true) {
			// The `skipped`/`failed` keys are present and zeroed on this path
			// too: a caller that reads them must not have to know which return
			// it got, and "nothing was imported" is not the same answer as
			// "something was rejected".
			return self::EMPTY_IMPORT_RESULT;
		}

		$this->logForcedImport(run: $run);
		$this->importComponents(run: $run);
		$this->finishImport(run: $run);

		return $run->result;
	}//end runImport()

	/**
	 * Whether the stored content hash of this app's configuration equals the incoming one.
	 *
	 * The CONTENT HASH decides, on its own. It covers the fully-merged
	 * configuration (monolith plus every register.d fragment), so an identical
	 * hash means importing would write exactly what is already stored.
	 *
	 * A version comparison is deliberately not part of it: three apps fold a
	 * digest into the version (`1.2.3+frag.a1b2c3d4`, ADR-037), and
	 * version_compare orders the `+…` part lexically, so whether a skip fired
	 * depended on how two md5 hashes happened to sort. A CHANGED fragment
	 * changes the hash, and a never-stored hash ('' on an install predating the
	 * hash) never matches, so those import as before (#426).
	 *
	 * @param ImportRun $run The import.
	 *
	 * @return bool True when the import can be skipped.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function isUnchangedConfiguration(ImportRun $run): bool {
		if ($run->appId === null || $run->version === null || $run->force === true) {
			return false;
		}

		$storedVersion = $this->appConfig->getValueString('openregister', "imported_config_{$run->appId}_version", '');
		$storedHash = $this->appConfig->getValueString('openregister', "imported_config_{$run->appId}_hash", '');
		if ($storedHash === '' || $storedHash !== $run->definitionHash) {
			return false;
		}

		$this->logger->debug(
			message: "[ImportHandler] Skipping {$run->appId}: config content unchanged (v{$run->version}, stored v{$storedVersion})",
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
		return true;
	}//end isUnchangedConfiguration()

	/**
	 * Log that a forced import bypasses the version check.
	 *
	 * @param ImportRun $run The import.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function logForcedImport(ImportRun $run): void {
		if ($run->force === true && $run->appId !== null && $run->version !== null) {
			$msg = "Force import enabled for app {$run->appId} version {$run->version} - bypassing version check";
			$this->logger->debug(message: '[ImportHandler] ' . $msg, context: ['file' => __FILE__, 'line' => __LINE__]);
		}
	}//end logForcedImport()

	/**
	 * Import the schemas, registers, mappings, selection lists and listed objects of a configuration.
	 *
	 * @param ImportRun $run The import.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function importComponents(ImportRun $run): void {
		// Reset the maps for this import.
		$this->registersMap = [];
		$this->schemasMap = [];
		$run->result = self::EMPTY_IMPORT_RESULT;

		// PER-REGISTER SLUG-UNIQUENESS (openspec/changes/per-register-schema-slug-uniqueness):
		// computed ONCE, from the RAW (pre-mutation) `components.registers.*.schemas` slug
		// lists, before the schema pass touches anything, so importSchema() resolves
		// "does the TARGET register already own this slug" instead of "does ANY
		// app/register own this slug" (how a foreign same-slug schema gets silently reused).
		$run->slugToSchemaIds = $this->computeRegisterScopedSchemaIds(data: $run->data);

		$this->importSchemaComponents(run: $run);
		$this->importRegisterComponents(run: $run);
		$this->importMappingComponents(run: $run);
		$this->seedSelectionLists(run: $run);

		// Resolve `@ref:<slug>` seed-reference tokens to concrete target UUIDs
		// before the import loop: the referenced schema properties are
		// `format: uuid`, so the tokens must be rewritten (and the targets given
		// a stable id) before validation runs inside saveObject().
		$run->data = $this->resolveSeedReferenceTokens(data: $run->data, appId: $run->appId);
		$this->importListedObjects(run: $run);
	}//end importComponents()

	/**
	 * Import the schemas of a configuration in two passes.
	 *
	 * Pass 1 creates every schema without resolving cross-references, pass 2
	 * resolves them once every schema exists, so no lookup fails on a schema
	 * declared later in the file.
	 *
	 * @param ImportRun $run The import.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function importSchemaComponents(ImportRun $run): void {
		if (($run->data['components']['schemas'] ?? null) === null || is_array($run->data['components']['schemas']) === false) {
			return;
		}

		$run->slugsAndIdsMap = $this->schemaMapper->getSlugToIdMap();
		$this->logger->debug(
			message: '[ImportHandler] Starting TWO-PASS schema import process',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'totalSchemas' => count($run->data['components']['schemas']),
				'schemaKeys' => array_keys($run->data['components']['schemas']),
			]
		);

		$schemasToResolve = $this->createSchemasWithoutReferences(run: $run);
		$this->resolveSchemaReferences(run: $run, schemasToResolve: $schemasToResolve);

		$this->logger->debug(
			message: '[ImportHandler] Schema import process completed (TWO-PASS)',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'importedCount' => count($run->result['schemas']),
				'importedSchemas' => $this->schemaSlugs(schemas: $run->result['schemas']),
			]
		);
	}//end importSchemaComponents()

	/**
	 * The slugs of a list of schemas.
	 *
	 * @param array<Schema> $schemas The schemas.
	 *
	 * @return array<string|null> Their slugs, in order.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function schemaSlugs(array $schemas): array {
		return array_map(fn ($schema) => $schema->getSlug(), $schemas);
	}//end schemaSlugs()

	/**
	 * Pass 1: create every schema of the configuration without resolving cross-references.
	 *
	 * @param ImportRun $run The import.
	 *
	 * @return array<int|string, array> The created schemas' data by component key, for pass 2.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function createSchemasWithoutReferences(ImportRun $run): array {
		$this->logger->debug(
			message: '[ImportHandler] PASS 1: Creating all schemas without cross-reference resolution',
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
		$schemasToResolve = [];
		foreach ($run->data['components']['schemas'] as $key => $schemaData) {
			$component = $this->schemaComponent(key: $key, schemaData: $schemaData);
			if ($this->createSchemaWithoutReferences(run: $run, component: $component) === true) {
				$schemasToResolve[$key] = $component->data;
			}
		}

		$this->rejectedSchemas->warn(run: $run);
		$this->logger->debug(
			message: '[ImportHandler] Pass 1 completed - all schemas created',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'createdCount' => count($run->result['schemas']),
				'createdSchemas' => $this->schemaSlugs(schemas: $run->result['schemas']),
			]
		);

		return $schemasToResolve;
	}//end createSchemasWithoutReferences()

	/**
	 * A schema component with its title and slug defaulted to its key.
	 *
	 * The component key IS the slug in every app configuration this handler
	 * has received: the registers list schemas by key. pipelinq shipped sixteen
	 * schemas without a `slug`, importSchema() rejected all sixteen, and the
	 * re-import still reported success. A slug that is present but blank is
	 * left alone: that is a mistake to reject, not to paper over.
	 *
	 * @param int|string $key        The component key.
	 * @param array      $schemaData The schema data.
	 *
	 * @return ImportComponent The keyed schema data.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function schemaComponent(int|string $key, array $schemaData): ImportComponent {
		$this->logger->debug(
			message: '[ImportHandler] Processing schema (Pass 1)',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'schemaKey' => $key,
				'schemaTitle' => $schemaData['title'] ?? 'no title',
				'schemaSlug' => $schemaData['slug'] ?? $key,
			]
		);

		if (isset($schemaData['title']) === false && is_string($key) === true) {
			$schemaData['title'] = $key;
		}

		if (array_key_exists('slug', $schemaData) === false && is_string($key) === true) {
			$schemaData['slug'] = $key;
		}

		return new ImportComponent(key: $key, data: $schemaData);
	}//end schemaComponent()

	/**
	 * Pass 1 for one schema: create it with the schema map blanked, so no $ref resolves yet.
	 *
	 * Blanking `schemasMap` is a TEMPORARY mutation of shared state, so it is
	 * undone in `finally`, on EVERY path. Undone on the success path only, one
	 * rejected schema left the map empty for every later schema, and the
	 * registers were created without their links (softwarecatalog: 2 rejected
	 * schemas, 18 of 20 links missing). See ImportHandlerSchemaMapRestoreTest.
	 *
	 * @param ImportRun       $run       The import.
	 * @param ImportComponent $component The schema component.
	 *
	 * @return bool True when the schema was created and needs pass 2.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function createSchemaWithoutReferences(ImportRun $run, ImportComponent $component): bool {
		$savedSchemasMap = $this->schemasMap;
		$this->schemasMap = [];
		$schema = null;

		try {
			$schema = $this->importSchemaComponent(run: $run, component: $component);
		} catch (Exception $e) {
			$component->error = $e;
			$this->rejectedSchemas->record(run: $run, rejection: $component);
		} finally {
			// Restore on every path — success, rejection, or an \Error escaping this loop entirely.
			$this->schemasMap = $savedSchemasMap;
		}

		if ($schema === null) {
			return false;
		}

		$this->schemasMap[$schema->getSlug()] = $schema;
		$run->result['schemas'][] = $schema;
		$this->logger->debug(
			message: '[ImportHandler] Successfully created schema (Pass 1)',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'schemaKey' => $component->key,
				'schemaSlug' => $schema->getSlug(),
				'schemaId' => $schema->getId(),
			]
		);
		return true;
	}//end createSchemaWithoutReferences()

	/**
	 * Import one schema component without forcing, scoped to the ids its registers declare.
	 *
	 * @param ImportRun       $run       The import.
	 * @param ImportComponent $component The schema component.
	 *
	 * @return Schema The created or updated schema.
	 *
	 * @throws Exception When the schema is rejected.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function importSchemaComponent(ImportRun $run, ImportComponent $component): Schema {
		$schemaSlugLower = strtolower((string)($component->data['slug'] ?? $component->key));
		return $this->importSchema(
			data: $component->data,
			slugsAndIdsMap: $run->slugsAndIdsMap,
			owner: $run->owner,
			appId: $run->appId,
			version: $run->version,
			force: $run->force,
			registerSchemaIds: $run->slugToSchemaIds[$schemaSlugLower] ?? null
		);
	}//end importSchemaComponent()

	/**
	 * Pass 2: resolve the cross-references of every schema pass 1 created.
	 *
	 * @param ImportRun                $run              The import.
	 * @param array<int|string, array> $schemasToResolve The created schemas' data by component key.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function resolveSchemaReferences(ImportRun $run, array $schemasToResolve): void {
		$this->logger->debug(
			message: '[ImportHandler] PASS 2: Resolving schema cross-references',
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		foreach ($schemasToResolve as $key => $schemaData) {
			if (isset($schemaData['title']) === false && is_string($key) === true) {
				$schemaData['title'] = $key;
			}

			$this->resolveSchemaComponentReferences(run: $run, component: new ImportComponent(key: $key, data: $schemaData));
		}
	}//end resolveSchemaReferences()

	/**
	 * Pass 2 for one schema: re-import it, forced, with the schema map populated.
	 *
	 * Schemas pass 1 skipped as up-to-date (or reported as failed) are not in
	 * the map: the expected case on every boot of an unchanged config, so that
	 * is logged at debug rather than as one warning per schema per import.
	 *
	 * @param ImportRun       $run       The import.
	 * @param ImportComponent $component The schema component.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function resolveSchemaComponentReferences(ImportRun $run, ImportComponent $component): void {
		$schemaSlug = $component->data['slug'] ?? $component->key;
		if (($this->schemasMap[$schemaSlug] ?? null) === null) {
			$this->logger->debug(
				message: '[ImportHandler] Schema not found in map for Pass 2 - skipping cross-reference resolution',
				context: ['file' => __FILE__, 'line' => __LINE__, 'schemaSlug' => $schemaSlug]
			);
			return;
		}

		try {
			$this->logger->debug(
				message: '[ImportHandler] Resolving cross-references for schema (Pass 2)',
				context: ['file' => __FILE__, 'line' => __LINE__, 'schemaSlug' => $schemaSlug]
			);
			$schema = $this->reimportSchemaComponent(run: $run, component: $component);
			// Update in map with resolved version.
			$this->schemasMap[$schema->getSlug()] = $schema;
			$this->logger->debug(
				message: '[ImportHandler] Cross-references resolved for schema (Pass 2)',
				context: ['file' => __FILE__, 'line' => __LINE__, 'schemaSlug' => $schemaSlug, 'schemaId' => $schema->getId()]
			);
		} catch (Exception $e) {
			$this->logger->error(
				message: '[ImportHandler] Failed to resolve cross-references for schema (Pass 2)',
				context: ['file' => __FILE__, 'line' => __LINE__, 'schemaKey' => $component->key, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]
			);
		}
	}//end resolveSchemaComponentReferences()

	/**
	 * Re-import one schema forced, resolving back to the EXACT schema pass 1 created.
	 *
	 * The register-scoped ids were computed from PRE-IMPORT register state, so
	 * a schema freshly CREATED in pass 1 is not in them yet; its own id is
	 * added so findBySlugInIds() still finds it rather than forking a second one.
	 *
	 * @param ImportRun       $run       The import.
	 * @param ImportComponent $component The schema component.
	 *
	 * @return Schema The schema with its cross-references resolved.
	 *
	 * @throws Exception When the schema is rejected.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function reimportSchemaComponent(ImportRun $run, ImportComponent $component): Schema {
		$schemaSlug = $component->data['slug'] ?? $component->key;
		$schemaIds = $run->slugToSchemaIds[strtolower((string)$schemaSlug)] ?? null;
		$resolvedSchemaId = $this->schemasMap[$schemaSlug]->getId();
		if ($resolvedSchemaId !== null) {
			$schemaIds = ($schemaIds ?? []);
			$schemaIds[] = (int)$resolvedSchemaId;
		}

		return $this->importSchema(
			data: $component->data,
			slugsAndIdsMap: $run->slugsAndIdsMap,
			owner: $run->owner,
			appId: $run->appId,
			version: $run->version,
			force: true,
			registerSchemaIds: $schemaIds
		);
	}//end reimportSchemaComponent()

	/**
	 * Import the registers of a configuration, linking the schemas this import created.
	 *
	 * @param ImportRun $run The import.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function importRegisterComponents(ImportRun $run): void {
		if (($run->data['components']['registers'] ?? null) === null || is_array($run->data['components']['registers']) === false) {
			return;
		}

		foreach ($run->data['components']['registers'] as $slug => $registerData) {
			$slug = strtolower($slug);
			$component = new ImportComponent(key: $slug, data: $registerData);
			$this->linkCreatedSchemas(run: $run, component: $component);
			$this->inheritConfigurationType(run: $run, component: $component);

			$register = $this->importRegisterComponent(run: $run, component: $component);
			if ($register !== null) {
				$this->registersMap[$slug] = $register;
			}
		}
	}//end importRegisterComponents()

	/**
	 * Replace a register's listed schema slugs with the ids of the schemas this import created.
	 *
	 * @param ImportRun       $run       The import.
	 * @param ImportComponent $component The register component.
	 *
	 * @return void
	 */
	private function linkCreatedSchemas(ImportRun $run, ImportComponent $component): void {
		if (($component->data['schemas'] ?? null) !== null && is_array($component->data['schemas']) === true) {
			$component->data['schemas'] = $this->registerSchemaIds(run: $run, schemaSlugs: $component->data['schemas']);
		}
	}//end linkCreatedSchemas()

	/**
	 * Give a register without its own `type` the configuration's `x-openregister.type`.
	 *
	 * Consuming apps filter mock/demo data via `GET /api/registers?filters[type]=mock`.
	 * A per-register `type` takes precedence, so one configuration file can mix register types.
	 *
	 * @param ImportRun       $run       The import.
	 * @param ImportComponent $component The register component.
	 *
	 * @return void
	 */
	private function inheritConfigurationType(ImportRun $run, ImportComponent $component): void {
		$parentType = ($run->data['x-openregister']['type'] ?? null);
		if (isset($component->data['type']) === false && $parentType !== null && $parentType !== '') {
			$component->data['type'] = (string)$parentType;
		}
	}//end inheritConfigurationType()

	/**
	 * The ids of the schemas a register lists, taken from the schemas this import created.
	 *
	 * There is no database fallback: it may fail on organisation or
	 * multi-tenancy filters during cross-instance imports.
	 *
	 * @param ImportRun $run         The import.
	 * @param array     $schemaSlugs The schema slugs the register lists.
	 *
	 * @return array The ids of the schemas found in the schema map.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function registerSchemaIds(ImportRun $run, array $schemaSlugs): array {
		$schemaIds = [];
		foreach ($schemaSlugs as $schemaSlug) {
			if (($this->schemasMap[$schemaSlug] ?? null) === null) {
				$this->warnMissingRegisterSchema(run: $run, schemaSlug: $schemaSlug);
				continue;
			}

			$schemaId = $this->schemasMap[$schemaSlug]->getId();
			$schemaIds[] = $schemaId;
			$this->logger->debug(
				message: "[ImportHandler] Schema '{$schemaSlug}' found in schemasMap",
				context: ['file' => __FILE__, 'line' => __LINE__, 'schemaId' => $schemaId]
			);
		}

		return $schemaIds;
	}//end registerSchemaIds()

	/**
	 * Warn that a register lists a schema this import did not create, naming the rejection when there was one.
	 *
	 * Since pass 1 restores the schema map on every path, a missing schema has
	 * exactly ONE cause: the schema pass rejected it.
	 *
	 * @param ImportRun $run        The import.
	 * @param mixed     $schemaSlug The schema slug the register lists.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function warnMissingRegisterSchema(ImportRun $run, mixed $schemaSlug): void {
		$reason = 'This schema should have been created in the TWO-PASS schema import phase. ';
		foreach ($run->result['failed']['schemas'] as $failedSchema) {
			if ($failedSchema['slug'] === $schemaSlug || $failedSchema['key'] === $schemaSlug) {
				$reason = 'It was REJECTED by the schema import phase (' . $failedSchema['error'] . '). ';
				break;
			}
		}

		$msg = 'Schema with slug %s not found in schemasMap during register import. ';
		$msg .= $reason;
		$msg .= 'This register will be created without this schema reference.';
		$this->logger->warning(
			message: '[ImportHandler] ' . sprintf($msg, $schemaSlug),
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
	}//end warnMissingRegisterSchema()

	/**
	 * Import one register, skipping it with a warning when it fails.
	 *
	 * PER-ENTITY RESILIENCE: one failing register MUST NOT abort the rest of
	 * the import. \Throwable is caught so opis/json-schema validation failures
	 * and folder-access denials are contained too.
	 *
	 * @param ImportRun       $run       The import.
	 * @param ImportComponent $component The register, keyed by its lower-cased slug.
	 *
	 * @return Register|null The register, or null when it was skipped.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function importRegisterComponent(ImportRun $run, ImportComponent $component): ?Register {
		try {
			$options = new SchemaImportOptions(owner: $run->owner, appId: $run->appId, version: $run->version, force: $run->force);
			$register = $this->importRegister(data: $component->data, options: $options);
			$run->result['registers'][] = $register;
			return $register;
		} catch (\Throwable $e) {
			$run->result['skipped']['registers']++;
			$this->logger->warning(
				message: "[ImportHandler] Skipping register '{$component->key}' - import failed: " . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'appId' => $run->appId, 'registerSlug' => $component->key, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end importRegisterComponent()

	/**
	 * Import the mappings of a configuration.
	 *
	 * @param ImportRun $run The import.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function importMappingComponents(ImportRun $run): void {
		if (($run->data['components']['mappings'] ?? null) === null || is_array($run->data['components']['mappings']) === false) {
			return;
		}

		$run->slugsAndIdsMap = $this->mappingMapper->getSlugToIdMap(includeNullOrg: true);
		$this->logger->debug(
			message: '[ImportHandler] Starting mapping import',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'totalMappings' => count($run->data['components']['mappings']),
				'mappingKeys' => array_keys($run->data['components']['mappings']),
			]
		);

		foreach ($run->data['components']['mappings'] as $key => $mappingData) {
			if (isset($mappingData['name']) === false && is_string($key) === true) {
				$mappingData['name'] = $key;
			}

			$this->importMappingComponent(run: $run, component: new ImportComponent(key: $key, data: $mappingData));
		}

		$this->logger->debug(
			message: '[ImportHandler] Mapping import completed',
			context: ['file' => __FILE__, 'line' => __LINE__, 'importedCount' => count($run->result['mappings'])]
		);
	}//end importMappingComponents()

	/**
	 * Import one mapping, logging and skipping it when it fails.
	 *
	 * @param ImportRun       $run       The import.
	 * @param ImportComponent $component The mapping component.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function importMappingComponent(ImportRun $run, ImportComponent $component): void {
		try {
			$mapping = $this->importMapping(run: $run, data: $component->data);
			$mappingId = null;
			if ($mapping !== null) {
				$run->result['mappings'][] = $mapping;
				$mappingId = $mapping->getId();
			}

			$this->logger->debug(
				message: '[ImportHandler] Mapping imported successfully',
				context: ['file' => __FILE__, 'line' => __LINE__, 'mappingSlug' => $component->data['slug'] ?? $component->key, 'mappingId' => $mappingId]
			);
		} catch (Exception $e) {
			$this->logger->error(
				message: '[ImportHandler] Failed to import mapping',
				context: ['file' => __FILE__, 'line' => __LINE__, 'mappingKey' => $component->key, 'error' => $e->getMessage()]
			);
		}
	}//end importMappingComponent()

	/**
	 * Write the selectielijst categories the app ships (`components.selectionLists`).
	 *
	 * They become selectielijst register rows, so the app's schemas'
	 * `archive.classification` resolves on a fresh install. A refused entry is
	 * named in the result; it never stops the rest of the import.
	 *
	 * @param ImportRun $run The import.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function seedSelectionLists(ImportRun $run): void {
		if (is_array($run->data['components']['selectionLists'] ?? null) === true
			&& $this->selectionListSeeder !== null
		) {
			$run->result['selectionLists'] = $this->selectionListSeeder->seed(
				entries: $run->data['components']['selectionLists'],
				appId: $run->appId
			);
		}
	}//end seedSelectionLists()

	/**
	 * Import the objects a configuration lists, retrying each failure once after the rest.
	 *
	 * An object may name another that comes later in the list, and a listener
	 * may judge it by a row a sibling writes, so a failure is retried once
	 * after every other object has been written. Only a second failure is
	 * recorded, in `failed.objects`, with the reason. Objects without a
	 * `@self.slug` are not imported here.
	 *
	 * The acting user is resolved once: on the occ, installer and cron paths
	 * there is no session, and saveObject()'s folder checks would default-deny.
	 *
	 * @param ImportRun $run The import.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function importListedObjects(ImportRun $run): void {
		$run->actingUser = $this->resolveActingUser();
		$retry = [];
		foreach ($this->listedSeedObjects(data: $run->data) as $objectData) {
			if (empty($objectData['@self']['slug'] ?? null) === true) {
				continue;
			}

			try {
				$this->importListedObject(run: $run, objectData: $objectData);
			} catch (\Throwable $e) {
				$this->logger->debug(
					message: '[ImportHandler] Object failed on the first pass, retried after the rest',
					context: ['file' => __FILE__, 'line' => __LINE__, 'objectSlug' => $objectData['@self']['slug'], 'error' => $e->getMessage()]
				);
				$retry[] = $objectData;
			}
		}

		$this->retryListedObjects(run: $run, retry: $retry);
	}//end importListedObjects()

	/**
	 * Import the listed objects that failed on the first pass, recording a second failure.
	 *
	 * A refusal by a listener (an app's own validation on the save event)
	 * depends on what the instance holds, not on the file, so it marks the run
	 * and the content hash is not stored.
	 *
	 * @param ImportRun   $run   The import.
	 * @param list<array> $retry The listed objects to retry.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function retryListedObjects(ImportRun $run, array $retry): void {
		foreach ($retry as $objectData) {
			try {
				$this->importListedObject(run: $run, objectData: $objectData);
			} catch (\Throwable $e) {
				if ($e instanceof HookStoppedException === true) {
					$run->refusedByListener = true;
				}

				$this->recordFailedObject(run: $run, failure: $this->failedObjectEntry(objectData: $objectData, error: $e));
			}
		}
	}//end retryListedObjects()

	/**
	 * The seed objects a configuration lists, from `components.objects` and the top-level `objects`.
	 *
	 * Both authoring locations seed; the lists are merged and de-duplicated by
	 * `@self` identity (uuid, else register/schema/slug), so a re-import never
	 * duplicates.
	 *
	 * @param array $data The configuration data.
	 *
	 * @return list<array> The seed objects, first occurrence kept.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function listedSeedObjects(array $data): array {
		$candidates = array_merge(
			$this->seedCandidates(seedBucket: ($data['components']['objects'] ?? null)),
			$this->seedCandidates(seedBucket: ($data['objects'] ?? null))
		);

		return $this->firstOccurrences(candidates: $candidates);
	}//end listedSeedObjects()

	/**
	 * The seed candidates without later duplicates of the same `@self` identity.
	 *
	 * A candidate without an identity is always kept.
	 *
	 * @param list<array> $candidates The seed candidates, in order.
	 *
	 * @return list<array> The seed objects, first occurrence kept.
	 */
	private function firstOccurrences(array $candidates): array {
		$seedObjects = [];
		$seedSeen = [];
		foreach ($candidates as $seedCandidate) {
			$seedKey = $this->seedIdentity(seedSelf: $seedCandidate['@self'] ?? []);
			if ($seedKey !== '' && isset($seedSeen[$seedKey]) === true) {
				continue;
			}

			if ($seedKey !== '') {
				$seedSeen[$seedKey] = true;
			}

			$seedObjects[] = $seedCandidate;
		}

		return $seedObjects;
	}//end firstOccurrences()

	/**
	 * The seed objects of one authoring location: its array entries, when it is a list.
	 *
	 * @param mixed $seedBucket `components.objects` or the top-level `objects`.
	 *
	 * @return list<array> The seed candidates, in order.
	 */
	private function seedCandidates(mixed $seedBucket): array {
		if (is_array($seedBucket) === false) {
			return [];
		}

		$candidates = [];
		foreach ($seedBucket as $seedCandidate) {
			if (is_array($seedCandidate) === true) {
				$candidates[] = $seedCandidate;
			}
		}

		return $candidates;
	}//end seedCandidates()

	/**
	 * The identity a seed object is de-duplicated by: its uuid, else register/schema/slug.
	 *
	 * @param array $seedSelf The object's `@self`.
	 *
	 * @return string The identity, '' when it has none.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function seedIdentity(array $seedSelf): string {
		$seedKey = (string)($seedSelf['uuid'] ?? '');
		if ($seedKey === '') {
			$seedKey = trim(($seedSelf['register'] ?? '') . '/' . ($seedSelf['schema'] ?? '') . '/' . ($seedSelf['slug'] ?? ''), '/');
		}

		return $seedKey;
	}//end seedIdentity()

	/**
	 * Flush the seed ledgers, merge OpenConnector's import, store the version and import seed data.
	 *
	 * @param ImportRun $run The import.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function finishImport(ImportRun $run): void {
		// The shipped versions the seeds recorded are written once, not once per seed.
		$this->seedVersions()->flush();
		$this->seedHashes()->flush();
		$this->importConnectorConfiguration(run: $run);
		$this->storeImportedVersion(run: $run);
		$this->importSeedDataContained(run: $run);
	}//end finishImport()

	/**
	 * Hand the configuration to OpenConnector, when installed, and merge its result.
	 *
	 * @param ImportRun $run The import.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function importConnectorConfiguration(ImportRun $run): void {
		if ($this->connectorConfigSvc === null) {
			return;
		}

		try {
			$openConnectorResult = $this->connectorConfigSvc->importConfiguration($run->data);
			$run->result = array_replace_recursive($openConnectorResult, $run->result);
		} catch (Exception $e) {
			$this->logger->warning(
				message: '[ImportHandler] OpenConnector integration failed: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}
	}//end importConnectorConfiguration()

	/**
	 * Store the imported version and content hash of the app's configuration.
	 *
	 * The hash records "this exact config content has been seen", so the next
	 * run fast-skips only when the content is unchanged (#426). It is withheld
	 * when a listener refused a listed object: that refusal depends on what
	 * the instance holds, so the next run must import again. A failure the
	 * file itself causes (a schema validation error) does not withhold it.
	 *
	 * @param ImportRun $run The import.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function storeImportedVersion(ImportRun $run): void {
		if ($run->appId === null || $run->version === null) {
			return;
		}

		$this->appConfig->setValueString('openregister', "imported_config_{$run->appId}_version", $run->version);
		$storedHash = $run->definitionHash;
		if ($run->refusedByListener === true) {
			$storedHash = '';
			$this->logger->warning(
				message: "[ImportHandler] {$run->appId}: a listener refused a listed object; "
					. 'this import is not remembered as done, so the next run imports again',
				context: ['file' => __FILE__, 'line' => __LINE__, 'failedObjects' => $run->result['failed']['objects']]
			);
		}

		$this->appConfig->setValueString('openregister', "imported_config_{$run->appId}_hash", $storedHash);
		$this->logger->info(
			message: "[ImportHandler] Stored version {$run->version} for app {$run->appId} after successful import",
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
	}//end storeImportedVersion()

	/**
	 * Import the configuration's seed data, keeping everything already imported when it fails.
	 *
	 * Seed data is imported AFTER registers, schemas and objects are
	 * persisted; a throw inside it must never unwind that completed work.
	 *
	 * @param ImportRun $run The import.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function importSeedDataContained(ImportRun $run): void {
		try {
			$seed = new SeedImport(configData: $run->data, result: $run->result);
			$seed->appId = $run->appId;
			$this->importSeedData(seed: $seed, configuration: $run->configuration);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[ImportHandler] Seed-data import failed - registers/schemas/objects kept: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'appId' => $run->appId, 'error' => $e->getMessage()]
			);
		}
	}//end importSeedDataContained()

	/**
	 * Import one object listed in a configuration (`components.objects`).
	 *
	 * A listed object with a stable `@self.slug` is a seed: shipped with the
	 * app's register configuration and found again on every import by register,
	 * schema and slug. A stored seed is updated when the shipped `@self.version`
	 * is higher than the version the import last shipped it at, which
	 * {@see SeedVersionLedger} remembers apart from the object (the stored
	 * object's own version is bumped by every later edit, so it cannot be the
	 * one compared); at the same or a lower version it is left alone and counted
	 * as unchanged. An object the configuration does not list is never looked
	 * at, so instance data is untouched.
	 *
	 * Throws on a failed save; the caller decides whether to retry or record it.
	 *
	 * @param ImportRun            $run        The import: its app, the user the save acts as, and the result it updates.
	 * @param array<string, mixed> $objectData The listed object, with `@self`.
	 *
	 * @return void
	 *
	 * @throws \Throwable When the object cannot be saved.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function importListedObject(ImportRun $run, array $objectData): void {
		$seed = $this->listedSeed(objectData: $objectData, actor: ['appId' => $run->appId, 'user' => $run->actingUser]);
		if ($seed === null) {
			return;
		}

		// A seed whose schema slug resolved to ANOTHER app's schema,
		// one its register does not list, is a stale seed, not a
		// write target ({@see ForeignSeedSchema}).
		if ($this->seedSchemaCheck(appId: $run->appId)->isForeign(register: $seed->register, schema: $seed->schema) === true) {
			$run->result['skipped']['objects']++;
			return;
		}

		$existingObject = $this->existingListedObject(seed: $seed);

		// Integer ids, not slugs, in @self, so no internal lookup uses a slug.
		$objectData['@self']['register'] = (int)$seed->register->getId();
		$objectData['@self']['schema'] = (int)$seed->schema->getId();

		// Identity, not data (live pass O6): a listed object may carry
		// its uuid and slug at the top level, as seed data does. The
		// uuid becomes the new object's uuid (an @self uuid wins), and
		// both keys leave the data unless the schema declares them, so
		// MagicMapper has nothing undeclared to discard.
		$listedUuid = $this->listedObjectUuid(objectData: $objectData);
		$objectData = $this->withoutSeedMetadataKeys(objectData: $objectData, schema: $seed->schema);

		if ($existingObject === null) {
			$run->result['objects'][] = $this->createListedObject(seed: $seed, listed: ['data' => $objectData, 'uuid' => $listedUuid]);
			return;
		}

		// A search returns arrays or ObjectEntity instances. The array check
		// comes first: calling jsonSerialize() on an array result threw, and
		// the per-object catch reported it as a failed seed.
		if (is_array($existingObject) === false) {
			$existingObject = $existingObject->jsonSerialize();
		}

		$updated = $this->updateListedObject(seed: $seed, listed: ['data' => $objectData, 'existing' => $existingObject]);
		if ($updated === null) {
			// COUNTED, not just logged. Skipping an unchanged object is the
			// DESIGNED behaviour of a re-import, but a debug line is invisible
			// to the caller, and the caller is the one that has to tell an
			// operator whether the import worked.
			$run->result['unchanged']['objects']++;
			return;
		}

		$run->result['objects'][] = $updated;
	}//end importListedObject()

	/**
	 * The register, schema and context a listed object is imported into, or null when either is missing.
	 *
	 * The register and schema come from this import's maps first and, for
	 * object-only bundles that reference pre-existing ones (e.g. the
	 * rapportage templates that ship a dashboard against the already-imported
	 * reports/dashboard), from the database. Passing the entities on, not
	 * their ids, avoids the organisation filter in find().
	 *
	 * @param array<string, mixed>                       $objectData The listed object, with `@self`.
	 * @param array{appId: ?string, user: IUser|null} $actor      The importing app and the acting user.
	 *
	 * @return ListedSeed|null
	 *
	 * @spec openspec/changes/seed-objects-follow-the-shipped-version/specs/data-import-export/spec.md
	 */
	private function listedSeed(array $objectData, array $actor): ?ListedSeed {
		$rawRegister = $objectData['@self']['register'] ?? null;
		$rawSchema = $objectData['@self']['schema'] ?? null;
		$slug = $objectData['@self']['slug'] ?? null;

		$registerObject = $this->listedRegister(rawRegister: $rawRegister);
		$schemaObject = $this->listedSchema(rawSchema: $rawSchema);
		if ($registerObject === null || $schemaObject === null) {
			$this->logger->warning(
				message: '[ImportHandler] Skipping object import - register or schema not found in maps or DB',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'objectSlug' => $slug,
					'registerSlug' => $rawRegister,
					'schemaSlug' => $rawSchema,
					'registerFound' => $registerObject !== null,
					'schemaFound' => $schemaObject !== null,
				]
			);
			return null;
		}

		return new ListedSeed(register: $registerObject, schema: $schemaObject, context: $actor + ['slug' => $slug]);
	}//end listedSeed()

	/**
	 * A listed object's register, from this import's map or the database.
	 *
	 * @param mixed $rawRegister The `@self` register slug.
	 *
	 * @return Register|null
	 *
	 * @spec openspec/changes/seed-objects-follow-the-shipped-version/specs/data-import-export/spec.md
	 */
	private function listedRegister(mixed $rawRegister): ?Register {
		$register = $this->registersMap[$rawRegister] ?? null;
		if ($register !== null || is_string($rawRegister) === false || $rawRegister === '') {
			return $register;
		}

		try {
			$register = $this->registerMapper->find($rawRegister, _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			return null;
		}

		$this->registersMap[$rawRegister] = $register;
		return $register;
	}//end listedRegister()

	/**
	 * A listed object's schema, from this import's map or the database.
	 *
	 * @param mixed $rawSchema The `@self` schema slug.
	 *
	 * @return Schema|null
	 *
	 * @spec openspec/changes/seed-objects-follow-the-shipped-version/specs/data-import-export/spec.md
	 */
	private function listedSchema(mixed $rawSchema): ?Schema {
		$schema = $this->schemasMap[$rawSchema] ?? null;
		if ($schema !== null || is_string($rawSchema) === false || $rawSchema === '') {
			return $schema;
		}

		try {
			$schema = $this->schemaMapper->find($rawSchema, _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			return null;
		}

		$this->schemasMap[$rawSchema] = $schema;
		return $schema;
	}//end listedSchema()

	/**
	 * The stored object with a listed object's register, schema and slug, or null.
	 *
	 * The search bypasses RBAC and multitenancy so an object is found whatever
	 * the organisation context, which keeps an import from creating a
	 * duplicate with the same UUID.
	 *
	 * @param ListedSeed $seed The listed object's target.
	 *
	 * @return mixed The stored object (an array or ObjectEntity), or null.
	 *
	 * @spec openspec/changes/seed-objects-follow-the-shipped-version/specs/data-import-export/spec.md
	 */
	private function existingListedObject(ListedSeed $seed): mixed {
		$registerId = $seed->register->getId();
		$schemaId = $seed->schema->getId();
		$slug = $seed->slug;
		$search = [
			'@self' => [
				'register' => (int)$registerId,
				'schema' => (int)$schemaId,
				'slug' => $slug,
			],
			'_limit' => 1,
		];
		$this->logger->debug(
			message: '[ImportHandler] Import object search filter',
			context: ['file' => __FILE__, 'line' => __LINE__, 'filter' => $search]
		);
		$this->logger->debug(
			message: "[ImportHandler] Searching: register=$registerId, schema=$schemaId, slug=$slug",
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		$results = $this->objectService->searchObjects(query: $search, _rbac: false, _multitenancy: false);
		$resultCount = 0;
		if (is_array($results) === true) {
			$resultCount = count($results);
		}

		$this->logger->debug(
			message: "[ImportHandler] Found $resultCount results",
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
		if ($resultCount > 0) {
			return $results[0];
		}

		// Debug: fires once PER OBJECT during an import, and says only that
		// the normal path was taken. The import's per-phase summaries are the
		// info-level story.
		$this->logger->debug(
			message: '[ImportHandler] No existing object found - will create new object',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'registerId' => $registerId,
				'schemaId' => $schemaId,
				'slug' => $slug,
			]
		);

		return null;
	}//end existingListedObject()

	/**
	 * Save a listed object into its register and schema.
	 *
	 * Installer-time seeding runs in the repair/CLI context with no user
	 * session ('Anonymous'), so RBAC and multitenancy are bypassed here as
	 * everywhere else in this trusted import path; otherwise seed objects on
	 * RBAC-guarded schemas (e.g. Retainer Drawdown) abort the whole import.
	 *
	 * @param ListedSeed           $seed The listed object's target.
	 * @param array<string, mixed> $save The object `data` and the `uuid` to save it under.
	 *
	 * @return ObjectEntity
	 *
	 * @spec openspec/changes/seed-objects-follow-the-shipped-version/specs/data-import-export/spec.md
	 */
	private function saveListedObject(ListedSeed $seed, array $save): ObjectEntity {
		return $this->objectService->saveObject(
			object: $save['data'],
			register: $seed->register,
			schema: $seed->schema,
			uuid: $save['uuid'],
			_rbac: false,
			_multitenancy: false,
			currentUser: $seed->actingUser
		);
	}//end saveListedObject()

	/**
	 * Create a listed object that is not stored yet, and remember what was shipped.
	 *
	 * @param ListedSeed           $seed   The listed object's target.
	 * @param array<string, mixed> $listed The object `data` and its listed `uuid`.
	 *
	 * @return ObjectEntity
	 *
	 * @spec openspec/changes/seed-objects-follow-the-shipped-version/specs/data-import-export/spec.md
	 */
	private function createListedObject(ListedSeed $seed, array $listed): ObjectEntity {
		$objectData = $listed['data'];
		$object = $this->saveListedObject(seed: $seed, save: $listed);

		$this->rememberShippedContent(seed: $seed, shipped: $objectData);
		$this->rememberShippedVersion(
			seed: $seed,
			version: (string)($objectData['@self']['version'] ?? $objectData['version'] ?? '1.0.0')
		);

		return $object;
	}//end createListedObject()

	/**
	 * Update a stored listed object when its shipped version moved on.
	 *
	 * The shipped version is compared with the version this seed was last
	 * SHIPPED at when the import remembers one, not with the object's own
	 * version: every later save of the object bumps its patch, and a stored
	 * `1.4.8` (shipped 1.4.7, edited once) would otherwise make a shipped
	 * `1.4.8` look current and keep it from ever arriving.
	 *
	 * @param ListedSeed           $seed   The listed object's target.
	 * @param array<string, mixed> $listed The shipped `data` and the `existing` stored object as an array.
	 *
	 * @return ObjectEntity|null The saved object, or null when it stays as it is.
	 *
	 * @spec openspec/changes/seed-objects-follow-the-shipped-version/specs/data-import-export/spec.md
	 */
	private function updateListedObject(ListedSeed $seed, array $listed): ?ObjectEntity {
		$objectData = $listed['data'];
		$existing = $listed['existing'];
		$listed['importedVersion'] = $objectData['@self']['version'] ?? $objectData['version'] ?? '1.0.0';
		$listed['existingVersion'] = $existing['@self']['version'] ?? $existing['version'] ?? '1.0.0';
		$listed['uuid'] = $existing['@self']['id'] ?? $existing['id'] ?? null;

		$seedKey = $seed->seedKey();
		$listed['shipped'] = null;
		if ($seedKey !== null) {
			$listed['shipped'] = $this->seedVersions()->shipped(appId: (string)$seed->appId, seed: $seedKey);
		}

		if (version_compare($listed['importedVersion'], ($listed['shipped'] ?? $listed['existingVersion']), '>') === false) {
			return $this->followShippedContent(seed: $seed, listed: $listed);
		}

		$object = $this->saveListedObject(seed: $seed, save: $listed);
		$this->rememberShippedVersion(seed: $seed, version: (string)$listed['importedVersion']);
		$this->rememberShippedContent(seed: $seed, shipped: $objectData);
		return $object;
	}//end updateListedObject()

	/**
	 * Follow shipped content whose version did not move ({@see ShippedSeedContent}).
	 *
	 * The content is remembered only once written, so a refused write is tried
	 * again on the next import. A seed stored before the import remembered its
	 * shipped version gets the shipped version remembered now: what the file
	 * ships is what the instance holds, as the object cannot be told apart
	 * from an edited one otherwise.
	 *
	 * @param ListedSeed           $seed   The listed object's target.
	 * @param array<string, mixed> $listed The shipped `data`, the `existing` object, its `uuid` and the three versions.
	 *
	 * @return ObjectEntity|null The saved object, or null when it stays as it is.
	 *
	 * @spec openspec/changes/seed-objects-follow-the-shipped-version/specs/data-import-export/spec.md
	 */
	private function followShippedContent(ListedSeed $seed, array $listed): ?ObjectEntity {
		$seedKey = $seed->seedKey();
		$remembered = null;
		if ($seedKey !== null) {
			$remembered = $this->seedHashes()->shipped(appId: (string)$seed->appId, seed: $seedKey);
		}

		$shipped = new ShippedSeed(content: $listed['data'], remembered: $remembered);
		$follow  = $this->shippedContent->updateFrom(stored: $listed['existing'], shipped: $shipped);
		if ($follow !== null) {
			$object = $this->saveListedObject(seed: $seed, save: ['data' => $follow, 'uuid' => $listed['uuid']]);
			$this->rememberShippedContent(seed: $seed, shipped: $listed['data']);
			return $object;
		}

		$this->rememberShippedContent(seed: $seed, shipped: $listed['data']);
		if ($listed['shipped'] === null) {
			$this->rememberShippedVersion(seed: $seed, version: (string)$listed['importedVersion']);
		}

		$this->logger->debug(
			message: '[ImportHandler] Skipped object update: imported version not higher',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'slug' => $seed->slug,
				'register' => $seed->register->getId(),
				'schema' => $seed->schema->getId(),
				'importedVersion' => $listed['importedVersion'],
				'existingVersion' => $listed['existingVersion'],
				'shippedVersion' => $listed['shipped'],
			]
		);
		return null;
	}//end followShippedContent()

	/**
	 * The ledger of the versions the seed objects were last shipped at.
	 *
	 * @return SeedVersionLedger
	 */
	private function seedVersions(): SeedVersionLedger {
		if ($this->seedVersions === null) {
			$this->seedVersions = new SeedVersionLedger(appConfig: $this->appConfig);
		}

		return $this->seedVersions;
	}//end seedVersions()

	/**
	 * The ledger of the hashes of the content the seed objects were last shipped with.
	 *
	 * @return SeedVersionLedger
	 */
	private function seedHashes(): SeedVersionLedger {
		if ($this->seedHashes === null) {
			$this->seedHashes = new SeedVersionLedger(appConfig: $this->appConfig, prefix: 'imported_seed_hashes_');
		}

		return $this->seedHashes;
	}//end seedHashes()

	/**
	 * Remember the hash of the content a seed object was shipped with, when the import knows its app.
	 *
	 * @param ListedSeed           $seed    The listed object's target; it has no key without an app.
	 * @param array<string, mixed> $shipped The seed as listed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function rememberShippedContent(ListedSeed $seed, array $shipped): void {
		$seedKey = $seed->seedKey();
		if ($seedKey === null) {
			return;
		}

		$this->seedHashes()->recordVersions(appId: (string)$seed->appId, versions: [$seedKey => $this->shippedContent->hash(shipped: $shipped)]);
	}//end rememberShippedContent()

	/**
	 * Remember the version a seed object was shipped at, when the import knows its app.
	 *
	 * @param ListedSeed $seed    The listed object's target; it has no key without an app.
	 * @param string     $version The shipped version.
	 *
	 * @return void
	 */
	private function rememberShippedVersion(ListedSeed $seed, string $version): void {
		$seedKey = $seed->seedKey();
		if ($seedKey === null) {
			return;
		}

		$this->seedVersions()->recordVersions(appId: (string)$seed->appId, versions: [$seedKey => $version]);
	}//end rememberShippedVersion()

	/**
	 * Record a listed object that failed twice: counted, named and logged.
	 *
	 * @param ImportRun            $run     The import; its result is updated in place.
	 * @param array<string, mixed> $failure The entry {@see failedObjectEntry()} built.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function recordFailedObject(ImportRun $run, array $failure): void {
		$run->result['skipped']['objects']++;
		$run->result['failed']['objects'][] = $failure;
		$this->logger->warning(
			message: "[ImportHandler] Skipping object '{$failure['slug']}' - import failed: " . $failure['error'],
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'appId' => $run->appId,
				'objectSlug' => $failure['slug'],
				'registerSlug' => $failure['register'],
				'schemaSlug' => $failure['schema'],
				'error' => $failure['error'],
			]
		);
	}//end recordFailedObject()

	/**
	 * The `failed.objects` entry of a listed object whose save failed.
	 *
	 * @param array<string, mixed> $objectData The listed object, with `@self`.
	 * @param \Throwable           $error      Why the save failed.
	 *
	 * @return array{slug: string, register: mixed, schema: mixed, error: string, refusedByListener: bool}
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function failedObjectEntry(array $objectData, \Throwable $error): array {
		return [
			'slug' => (string)($objectData['@self']['slug'] ?? ''),
			'register' => $objectData['@self']['register'] ?? null,
			'schema' => $objectData['@self']['schema'] ?? null,
			'error' => $error->getMessage(),
			'refusedByListener' => ($error instanceof HookStoppedException),
		];
	}//end failedObjectEntry()

	/**
	 * Resolve `@ref:<slug>` seed-reference tokens to target object UUIDs.
	 *
	 * Seed objects declare relationships to sibling seed objects by slug using
	 * the `@ref:<slug>` token (or the disambiguated `@ref:<schema-slug>:<slug>`
	 * form) in any property value. The referenced schema properties are normally
	 * constrained to `format: uuid`, so a literal slug token would fail
	 * validation inside {@see SaveObject}. This method runs before the
	 * object-import loop and:
	 *
	 *  1. Assigns every referenced target object a stable UUID — reusing the
	 *     UUID of an already-imported object with the same register+schema+slug
	 *     (so re-imports stay idempotent), otherwise minting a fresh v4 UUID —
	 *     and writes it into `@self.id` so saveObject persists that exact id.
	 *  2. Replaces every `@ref:` token in object property values with the
	 *     resolved target UUID.
	 *
	 * Unresolvable or ambiguous tokens are left untouched and logged as a
	 * warning, so the subsequent schema validation surfaces them rather than
	 * silently dropping the relationship. Objects that are never referenced are
	 * left exactly as-is (the import loop assigns their identity as before).
	 *
	 * @param array $data The configuration data.
	 * @param string|null $appId The importing app, for the foreign-schema guard.
	 *
	 * @return array The data with target `@self.id` populated and `@ref:` tokens resolved.
	 */
	private function resolveSeedReferenceTokens(array $data, ?string $appId = null): array {
		$objects = ($data['components']['objects'] ?? null);
		if (is_array($objects) === false) {
			return $data;
		}

		// Only genuine reference targets get a pre-resolved identity, and the
		// work is skipped entirely when no object references another.
		$index = new SeedReferenceIndex(logger: $this->logger);
		$index->collectFromObjects(objects: $objects);
		if ($index->isEmpty() === true) {
			return $data;
		}

		foreach ($this->referencedSeeds(objects: $objects, index: $index) as $position => $targetData) {
			$uuid = $this->seedReferenceTargetUuid(targetData: $targetData, appId: $appId);
			if ($uuid !== null) {
				$objects[$position]['@self']['id'] = $uuid;
				$index->record(self: $objects[$position]['@self']);
			}
		}

		$data['components']['objects'] = $index->replaceInObjects(objects: $objects);
		return $data;
	}//end resolveSeedReferenceTokens()

	/**
	 * The seed objects another seed refers to, under their list positions.
	 *
	 * @param array              $objects The seed objects.
	 * @param SeedReferenceIndex $index   The references collected from them.
	 *
	 * @return array The referenced objects.
	 */
	private function referencedSeeds(array $objects, SeedReferenceIndex $index): array {
		return array_filter(
			$objects,
			static fn (mixed $seed): bool => is_array($seed) === true && $index->isReferenced(self: $seed['@self'] ?? null) === true
		);
	}//end referencedSeeds()

	/**
	 * The check that keeps a seed out of another app's schema.
	 *
	 * @param string|null $appId The importing app.
	 *
	 * @return ForeignSeedSchema The check.
	 */
	private function seedSchemaCheck(?string $appId): ForeignSeedSchema {
		return new ForeignSeedSchema(logger: $this->logger, appId: $appId);
	}//end seedSchemaCheck()

	/**
	 * The UUID a referenced seed target is stored under.
	 *
	 * Prefers the UUID already persisted for its register+schema+slug, so
	 * re-imports stay idempotent and references match the id the import loop
	 * keeps on update; then an explicit seed id; then a freshly minted one.
	 *
	 * A target whose register or schema cannot be resolved is skipped by the
	 * import loop and never stored, so it gets no UUID: a referrer would
	 * otherwise point at an id that never exists, and the token is logged as
	 * unresolved instead.
	 *
	 * @param array       $targetData The referenced seed object.
	 * @param string|null $appId      The importing app, for the foreign-schema guard.
	 *
	 * @return mixed The UUID, or null when the target will not be stored.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function seedReferenceTargetUuid(array $targetData, ?string $appId): mixed {
		[$registerObject, $schemaObject] = $this->resolveImportRegisterSchema(objectData: $targetData, appId: $appId);
		if ($registerObject === null || $schemaObject === null) {
			return null;
		}

		$table = new RegisterSchema(register: $registerObject, schema: $schemaObject);
		$uuid = $this->findExistingSeedUuid(table: $table, slug: $targetData['@self']['slug']);
		if (empty($uuid) === false) {
			return $uuid;
		}

		return $this->declaredOrFreshSeedUuid(targetData: $targetData);
	}//end seedReferenceTargetUuid()

	/**
	 * The id a seed declares, or a fresh one.
	 *
	 * @param array $targetData The seed object.
	 *
	 * @return mixed The UUID.
	 */
	private function declaredOrFreshSeedUuid(array $targetData): mixed {
		$uuid = $targetData['@self']['id'] ?? null;
		if (empty($uuid) === false) {
			return $uuid;
		}

		return \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
	}//end declaredOrFreshSeedUuid()

	/**
	 * Resolve a seed object's `@self` register + schema slugs to their entities,
	 * using the in-flight import maps first and falling back to a direct mapper
	 * lookup (RBAC/multitenancy bypassed, as everywhere else in this trusted
	 * import path). Returns nulls when either cannot be resolved.
	 *
	 * A schema that resolves to another app's schema its register does not list
	 * comes back as null, the same answer the import loop acts on, so a stale
	 * seed is never handed a pre-assigned identity it will not be stored under.
	 *
	 * @param array $objectData The seed object (with @self register/schema).
	 * @param string|null $appId The importing app, for the foreign-schema guard.
	 *
	 * @return array{0: ?Register, 1: ?Schema} The resolved register and schema.
	 */
	private function resolveImportRegisterSchema(array $objectData, ?string $appId = null): array {
		$rawRegister = $objectData['@self']['register'] ?? null;
		$rawSchema = $objectData['@self']['schema'] ?? null;

		$registerObject = $this->registersMap[$rawRegister] ?? null;
		if ($registerObject instanceof Register === false
			&& is_string($rawRegister) === true
			&& $rawRegister !== ''
		) {
			try {
				$registerObject = $this->registerMapper->find($rawRegister, _rbac: false, _multitenancy: false);
				$this->registersMap[$rawRegister] = $registerObject;
			} catch (\Throwable $e) {
				$registerObject = null;
			}
		}

		$schemaObject = $this->schemasMap[$rawSchema] ?? null;
		if ($schemaObject instanceof Schema === false
			&& is_string($rawSchema) === true
			&& $rawSchema !== ''
		) {
			try {
				$schemaObject = $this->schemaMapper->find($rawSchema, _rbac: false, _multitenancy: false);
				$this->schemasMap[$rawSchema] = $schemaObject;
			} catch (\Throwable $e) {
				$schemaObject = null;
			}
		}

		if ($registerObject instanceof Register === false) {
			$registerObject = null;
		}

		if ($schemaObject instanceof Schema === false) {
			$schemaObject = null;
		}

		if ($registerObject !== null
			&& $schemaObject !== null
			&& $this->seedSchemaCheck(appId: $appId)->isForeign(register: $registerObject, schema: $schemaObject) === true
		) {
			$schemaObject = null;
		}

		return [$registerObject, $schemaObject];
	}//end resolveImportRegisterSchema()

	/**
	 * Look up the UUID of an already-imported object with the given
	 * register + schema + slug, so re-imports reuse the same identity.
	 *
	 * @param RegisterSchema $table The resolved register and schema.
	 * @param string         $slug  The seed object slug.
	 *
	 * @return string|null The existing object UUID, or null when none exists.
	 */
	private function findExistingSeedUuid(RegisterSchema $table, string $slug): ?string {
		$search = [
			'@self' => [
				'register' => (int)$table->register->getId(),
				'schema' => (int)$table->schema->getId(),
				'slug' => $slug,
			],
			'_limit' => 1,
		];

		try {
			$results = $this->objectService->searchObjects(query: $search, _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			return null;
		}

		if (is_array($results) === false || count($results) === 0) {
			return null;
		}

		$existing = $results[0];
		if ($existing instanceof ObjectEntity) {
			$existing = $existing->jsonSerialize();
		}

		if (is_array($existing) === false) {
			return null;
		}

		$uuid = $existing['@self']['id'] ?? $existing['id'] ?? null;
		if (is_string($uuid) === true && $uuid !== '') {
			return $uuid;
		}

		return null;
	}//end findExistingSeedUuid()



	/**
	 * The schema parts the shipped-baseline guard kept local since the last call, and a fresh list.
	 *
	 * Called once before an import whose result reports them, to drop what an
	 * earlier import left, and once after it for the report.
	 *
	 * @return array<int, array{slug: string, paths: array<int, string>}> One entry per schema.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function takeKeptLocalReport(): array {
		return $this->shippedBaseline->takeKeptLocal();
	}//end takeKeptLocalReport()

	/**
	 * Import configuration from an app's JSON data.
	 *
	 * This is a convenience wrapper method for apps that want to import their
	 * configuration. It creates or finds a Configuration entity, performs the
	 * import via importFromJson, and updates the Configuration tracking.
	 *
	 * @param string $appId The application ID.
	 * @param array $data The configuration data.
	 * @param string $version The configuration version.
	 * @param bool $force Force import regardless of version.
	 *
	 * @return array The import results.
	 *
	 * @throws Exception If import fails.
	 *
	 * @phpstan-return array{
	 *     registers: array<Register>,
	 *     schemas: array<Schema>,
	 *     objects: array<ObjectEntity>,
	 *     endpoints: array,
	 *     sources: array,
	 *     mappings: array<Mapping>,
	 *     jobs: array,
	 *     synchronizations: array,
	 *     rules: array
	 * }
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Force flag to override version checks
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function importFromApp(string $appId, array $data, string $version, bool $force = false): array {
		try {
			$import = new AppImport(
				appId: $appId,
				data: (new ArrayStructure())->toArrays(data: $data),
				version: $version,
				force: $force
			);

			$configuration = ($this->findAppConfiguration(import: $import) ?? $this->appConfigurations->create(import: $import));

			// Run under its own import job id so the objects it creates can be
			// removed by job later (a setup wizard's "remove this example set").
			$this->takeKeptLocalReport();
			$result = $this->importFromJsonAsJob(import: $import, configuration: $configuration);
			$result['keptLocal'] = ['schemas' => $this->takeKeptLocalReport()];

			$update = new ImportedConfigurationUpdate(configuration: $configuration, result: $result);
			if (count($result['registers']) > 0 || count($result['schemas']) > 0 || count($result['objects']) > 0) {
				$this->appConfigurations->track(import: $import, update: $update);
			}

			$this->finishAppImport(import: $import, update: $update);

			return $update->result;
		} catch (Exception $e) {
			$this->logger->error(
				message: "[ImportHandler] Failed to import configuration for app {$appId}: " . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			throw new Exception("Failed to import configuration for app {$appId}: " . $e->getMessage());
		}//end try
	}//end importFromApp()

	/**
	 * Find the Configuration an app import updates: by its `sourceUrl`
	 * first, which is unique, then by the app id.
	 *
	 * @param AppImport $import The app import.
	 *
	 * @return Configuration|null
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function findAppConfiguration(AppImport $import): ?Configuration {
		$sourceUrl = ($import->extension()['sourceUrl'] ?? null);
		if ($sourceUrl !== null) {
			try {
				$configuration = $this->configurationMapper->findBySourceUrl($sourceUrl, systemLookup: true);
				if ($configuration !== null) {
					$this->logger->debug(
						message: '[ImportHandler] Found existing configuration by sourceUrl',
						context: [
							'file' => __FILE__,
							'line' => __LINE__,
							'sourceUrl' => $sourceUrl,
							'configurationId' => $configuration->getId(),
							'currentVersion' => $configuration->getVersion(),
						]
					);
					return $configuration;
				}
			} catch (Exception $e) {
				// No configuration found by sourceUrl.
				$this->logger->debug(
					message: '[ImportHandler] No configuration found by source URL',
					context: [
						'sourceUrl' => $sourceUrl,
						'exception' => $e->getMessage(),
					]
				);
			}
		}//end if

		return $this->findAppConfigurationByApp(import: $import);
	}//end findAppConfiguration()

	/**
	 * Find the most recent Configuration of the importing app.
	 *
	 * An up-to-date version is only logged: the import still runs, because
	 * importFromJson() skips unchanged schemas and registers but may still
	 * import seed data.
	 *
	 * @param AppImport $import The app import.
	 *
	 * @return Configuration|null
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function findAppConfigurationByApp(AppImport $import): ?Configuration {
		$appId = $import->appId;
		try {
			$configurations = $this->configurationMapper->findByApp($appId, systemLookup: true);
		} catch (Exception $e) {
			$this->logger->debug(
				message: "[ImportHandler] No existing configuration found for app {$appId}, will create new one",
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}

		if (count($configurations) === 0) {
			return null;
		}

		$configuration = $configurations[0];
		$this->logger->debug(
			message: "[ImportHandler] Found existing configuration for app {$appId}",
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'configurationId' => $configuration->getId(),
				'currentVersion' => $configuration->getVersion(),
			]
		);

		$existingVersion = $configuration->getVersion() ?? '0.0.0';
		if ($import->force === false && version_compare($import->version, $existingVersion, '<=') === true) {
			$this->logger->debug(
				message: "[ImportHandler] Config version ({$existingVersion}) up-to-date, checking seedData",
				context: ['file' => __FILE__, 'line' => __LINE__, 'app' => $appId, 'force' => $import->force]
			);
		}

		return $configuration;
	}//end findAppConfigurationByApp()

	/**
	 * The steps every app import ends with: an application register when the
	 * data declares one, a Files folder per register, and a magic-table
	 * sync per imported schema.
	 *
	 * The application register closes the gap a runtime caller leaves: it
	 * skips the installer's repair step, so without it the schemas exist
	 * but no register wraps them. A folder failure is logged, never thrown;
	 * the first upload still makes the folder. The magic-table sync reaches
	 * an existing schema in an existing register that ships no seed data,
	 * which no other step reconciles (#2082).
	 *
	 * @param AppImport                   $import The app import.
	 * @param ImportedConfigurationUpdate $update The configuration and the import result; an
	 *                                            auto-created register is added to the result.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function finishAppImport(AppImport $import, ImportedConfigurationUpdate $update): void {
		$this->autoCreateRegisterIfApplication(import: $import, update: $update);

		try {
			$this->folderProvisioner?->ensureFolders(registers: ($update->result['registers'] ?? []));
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: "[ImportHandler] Register folder provisioning failed for app {$import->appId}: " . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}

		$this->ensureMagicTablesForImportedSchemas(schemas: $update->result['schemas'] ?? []);
	}//end finishAppImport()

	/**
	 * Run importFromJson() for an app under its own import job id.
	 *
	 * Every audit row the import writes carries the id; the stamp is ended in
	 * `finally`, so a throwing import never leaks it. A job that created
	 * objects is recorded per app id, and its id is returned as
	 * `importJobId` (null when nothing traceable was created).
	 *
	 * @param AppImport     $import        The app import: its data, app id, version and force flag.
	 * @param Configuration $configuration The configuration entity.
	 *
	 * @return array The importFromJson() result plus `importJobId`.
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-an-app-configuration-import-must-run-under-its-own-import-job-id
	 */
	private function importFromJsonAsJob(AppImport $import, Configuration $configuration): array {
		if ($this->importJobRecorder === null) {
			$result = $this->importAppJson(import: $import, configuration: $configuration);
			$result['importJobId'] = null;
			return $result;
		}

		$importJobId = $this->importJobRecorder->begin();
		try {
			$result = $this->importAppJson(import: $import, configuration: $configuration);
		} finally {
			$this->importJobRecorder->end();
		}

		$recorded = $this->importJobRecorder->recordFinished(
			job: new FinishedImportJob(
				appId: $import->appId,
				importJobId: $importJobId,
				version: $import->version,
				objectsWritten: count((array)($result['objects'] ?? []))
			)
		);
		$result['importJobId'] = null;
		if ($recorded === true) {
			$result['importJobId'] = $importJobId;
		}

		return $result;
	}//end importFromJsonAsJob()

	/**
	 * Run importFromJson() for an app import, owned by the app.
	 *
	 * @param AppImport     $import        The app import.
	 * @param Configuration $configuration The configuration entity.
	 *
	 * @return array The importFromJson() result.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function importAppJson(AppImport $import, Configuration $configuration): array {
		return $this->importFromJson(
			data: $import->data,
			configuration: $configuration,
			owner: $import->appId,
			appId: $import->appId,
			version: $import->version,
			force: $import->force
		);
	}//end importAppJson()

	/**
	 * Reconcile the magic table of every imported schema, in every register that holds it.
	 *
	 * `ensureTableForRegisterSchema()` creates the table when absent and, when it
	 * already exists, syncs missing/retyped columns via `handleExistingTable()`.
	 * Calling it here closes the gap where an existing schema in an existing
	 * register — with no seed data — never had its physical table reconciled
	 * after a property was added, leaving reads that filter on the new column
	 * throwing a raw SQL error (see #2082).
	 *
	 * A schema may belong to several registers, each with its own physical
	 * table, so every owning register is reconciled.
	 *
	 * Failures are logged and never abort the import: a table that cannot be
	 * reconciled must not lose the rest of an otherwise-successful import.
	 *
	 * @param array $schemas The imported Schema entities.
	 *
	 * @return void
	 */
	private function ensureMagicTablesForImportedSchemas(array $schemas): void {
		if ($this->magicMapper === null || empty($schemas) === true) {
			return;
		}

		foreach ($this->persistedSchemas(schemas: $schemas) as $schema) {
			$this->reconcileMagicTablesOf(schema: $schema);
		}
	}//end ensureMagicTablesForImportedSchemas()

	/**
	 * The entries of an import result that are schemas with an id.
	 *
	 * @param array $schemas The imported entries.
	 *
	 * @return Schema[] The persisted schemas, in order.
	 */
	private function persistedSchemas(array $schemas): array {
		$persisted = [];
		foreach ($schemas as $schema) {
			if ($schema instanceof Schema && $schema->getId() !== null) {
				$persisted[] = $schema;
			}
		}

		return $persisted;
	}//end persistedSchemas()

	/**
	 * Reconcile the magic table of a schema in every register that owns it.
	 *
	 * @param Schema $schema The imported schema.
	 *
	 * @return void
	 */
	private function reconcileMagicTablesOf(Schema $schema): void {
		$registerIds = $this->registerIdsOwningSchema(schemaId: (int)$schema->getId());
		if ($registerIds === null) {
			return;
		}

		foreach ($registerIds as $registerId) {
			$this->reconcileMagicTable(schema: $schema, registerId: $registerId);
		}
	}//end reconcileMagicTablesOf()

	/**
	 * The ids of the registers that list a schema; a failing lookup is logged and gives none.
	 *
	 * @param int $schemaId The schema id.
	 *
	 * @return array|null The register ids, or null when they cannot be resolved.
	 */
	private function registerIdsOwningSchema(int $schemaId): ?array {
		try {
			return $this->registerMapper->getAllRegisterIdsWithSchema(schemaId: $schemaId);
		} catch (\Exception $e) {
			$this->warnOwningRegistersUnresolved(schemaId: $schemaId, error: $e);
			return null;
		}
	}//end registerIdsOwningSchema()

	/**
	 * Warn that a schema's owning registers could not be resolved.
	 *
	 * @param int        $schemaId The schema id.
	 * @param \Exception $error    The lookup failure.
	 *
	 * @return void
	 */
	private function warnOwningRegistersUnresolved(int $schemaId, \Exception $error): void {
		$this->logger->warning(
			message: '[ImportHandler] Could not resolve registers for imported schema; magic table not reconciled',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'schema_id' => $schemaId,
				'error' => $error->getMessage(),
			]
		);
	}//end warnOwningRegistersUnresolved()

	/**
	 * Reconcile a schema's magic table in one register; a failure is logged, never thrown.
	 *
	 * @param Schema $schema     The imported schema.
	 * @param mixed  $registerId The owning register's id.
	 *
	 * @return void
	 */
	private function reconcileMagicTable(Schema $schema, mixed $registerId): void {
		try {
			$this->ensureMagicTableIn(schema: $schema, registerId: $registerId);
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[ImportHandler] Failed to reconcile magic table for imported schema',
				context: $this->magicTableContext(schema: $schema, registerId: $registerId) + ['error' => $e->getMessage()]
			);
		}
	}//end reconcileMagicTable()

	/**
	 * Create or sync the magic table of a schema in one register (RBAC/multitenancy bypassed).
	 *
	 * @param Schema $schema     The imported schema.
	 * @param mixed  $registerId The owning register's id.
	 *
	 * @return void
	 */
	private function ensureMagicTableIn(Schema $schema, mixed $registerId): void {
		$register = $this->registerMapper->find(
			id: (int)$registerId,
			_rbac: false,
			_multitenancy: false
		);

		$this->magicMapper->ensureTableForRegisterSchema(
			register: $register,
			schema: $schema
		);
	}//end ensureMagicTableIn()

	/**
	 * The log context naming a schema and register whose magic table failed to reconcile.
	 *
	 * @param Schema $schema     The imported schema.
	 * @param mixed  $registerId The owning register's id.
	 *
	 * @return array<string, mixed> The context, without the error.
	 */
	private function magicTableContext(Schema $schema, mixed $registerId): array {
		return [
			'file' => __FILE__,
			'line' => __LINE__,
			'schema_id' => (int)$schema->getId(),
			'schema_slug' => $schema->getSlug(),
			'register_id' => $registerId,
		];
	}//end magicTableContext()

	/**
	 * Auto-create or reconcile a Register entity for application-type imports
	 *
	 * Implements the runtime-schema-api spec contract: when an imported OAS
	 * document carries `x-openregister.type=application`, derive a Register
	 * from `x-openregister.app` (slug), `info.title` (title), and
	 * `info.description` (description), then attach every imported schema's
	 * numeric ID to the resulting Register's `schemas[]` field.
	 *
	 * Lookup is idempotent on `(slug, organisationId)` so a re-import of the
	 * same OAS updates the existing row instead of inserting a duplicate. The
	 * organisation tuple preserves the multi-tenant boundary that OR relies
	 * on everywhere else — two organisations on the same Nextcloud must be
	 * able to install the same app independently.
	 *
	 * Skipped silently when `x-openregister.type` is absent or set to
	 * anything other than `application` (e.g. `library`, the default).
	 * The Configuration row is also updated to reference the resulting
	 * Register ID so the (Configuration, Schemas, Register) triple stays
	 * consistent.
	 *
	 * @param AppImport                   $import The app import: the full OAS document and the app identifier.
	 * @param ImportedConfigurationUpdate $update The Configuration row already persisted and the import result,
	 *                                            whose `schemas` are the imported schemas (Schema entities or
	 *                                            stdClass with getId()); the resulting Register entity is
	 *                                            appended into its `registers`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function autoCreateRegisterIfApplication(AppImport $import, ImportedConfigurationUpdate $update): void {
		$details = $this->applicationRegisterDetails(data: $import->data, appId: $import->appId);
		if ($details === null) {
			return;
		}

		$schemas = $update->result['schemas'];
		$configuration = $update->configuration;

		$details['schemas'] = $schemas;
		$details['schemaIds'] = self::savedSchemaIds(schemas: $schemas);

		// Idempotent lookup on (slug, organisationId). The find cache on
		// RegisterMapper already keys by slug, so this is cheap.
		// Use findAll with filters so we can scope to organisation explicitly
		// without relying on session-derived multi-tenancy (the import path
		// may run under a system context where the active org is null).
		$existingRegisters = $this->registerMapper->findAll(
			limit: 1,
			offset: 0,
			filters: ['slug' => $details['slug']],
			_rbac: false,
			_multitenancy: true
		);

		$register = null;
		if (count($existingRegisters) > 0) {
			$register = $this->reconcileApplicationRegister(register: $existingRegisters[0], details: $details);
		}

		if ($register === null) {
			$register = $this->createApplicationRegister(details: $details);
		}

		// Surface the auto-created register in the import result so callers
		// see a complete (Configuration, Schemas, Register) triple.
		$update->result['registers'] = self::withRegister(registers: ($update->result['registers'] ?? []), register: $register);

		// Keep the Configuration entity's registers[] field in sync so the
		// triple stays consistent and a follow-up `_extend=registers` GET
		// serializes the new ID without an extra round-trip.
		$configRegisterIds = $configuration->getRegisters() ?? [];
		if (in_array($register->getId(), $configRegisterIds, true) === false) {
			$configRegisterIds[] = $register->getId();
			$configuration->setRegisters($configRegisterIds);
			$this->configurationMapper->update($configuration);
		}

		if ($this->magicMapper !== null) {
			$this->ensureApplicationMagicTables(register: $register, schemas: $schemas);
		}
	}//end autoCreateRegisterIfApplication()

	/**
	 * The slug, title and description of the register an `application` configuration declares.
	 *
	 * Null when the configuration is not of type `application`, or when it
	 * names no usable slug (which is logged).
	 *
	 * @param array  $data  The full OAS document.
	 * @param string $appId The app identifier, the fallback slug and title.
	 *
	 * @return array{slug: string, title: mixed, description: mixed}|null
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function applicationRegisterDetails(array $data, string $appId): ?array {
		$xOpenregister = $data['x-openregister'] ?? [];
		if (($xOpenregister['type'] ?? null) !== 'application') {
			return null;
		}

		$slug = $xOpenregister['app'] ?? $appId;
		if (is_string($slug) === false || $slug === '') {
			$this->logger->warning(
				message: '[ImportHandler] Skipping auto-Register: x-openregister.app missing or empty',
				context: ['file' => __FILE__, 'line' => __LINE__, 'appId' => $appId]
			);
			return null;
		}

		$info = $data['info'] ?? [];
		return [
			'slug' => $slug,
			'title' => $info['title'] ?? $xOpenregister['title'] ?? $appId,
			'description' => $info['description'] ?? $xOpenregister['description'] ?? null,
		];
	}//end applicationRegisterDetails()

	/**
	 * The ids of the imported schemas that have been saved.
	 *
	 * @param array $schemas The imported schemas.
	 *
	 * @return array<int, int>
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private static function savedSchemaIds(array $schemas): array {
		$ids = [];
		foreach ($schemas as $schema) {
			if ($schema instanceof Schema && $schema->getId() !== null) {
				$ids[] = (int)$schema->getId();
			}
		}

		return $ids;
	}//end savedSchemaIds()

	/**
	 * Create the register an `application` configuration declares.
	 *
	 * @param array<string, mixed> $details The slug, title, description and saved schema ids.
	 *
	 * @return Register
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function createApplicationRegister(array $details): Register {
		$register = $this->registerMapper->createFromArray(
			object: [
				'title' => $details['title'],
				'description' => $details['description'] ?? '',
				'slug' => $details['slug'],
				'schemas' => $details['schemaIds'],
				'source' => 'import',
			]
		);

		// Info: a register was CREATED. Structural and rare.
		$this->logger->info(
			message: '[ImportHandler] Auto-Register created from x-openregister.type=application',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'registerId' => $register->getId(),
				'slug' => $details['slug'],
				'schemaIds' => $details['schemaIds'],
			]
		);

		return $register;
	}//end createApplicationRegister()

	/**
	 * Refresh an existing application register on re-import.
	 *
	 * The title and description are refreshed, and the freshly imported
	 * (app-owned) schema ids are unioned in after pruning any listed id that a
	 * new schema with the same slug now shadows. That self-heals a register
	 * that, before the cross-app slug-collision fix, had a FOREIGN app's
	 * same-slug schema bound into it (e.g. pipelinq's 'conversation' #701
	 * living in hermiq's register): once the app imports its OWN
	 * 'conversation', the foreign shadow is dropped so a slug resolves
	 * unambiguously to the app's own schema.
	 *
	 * @param Register             $register The existing register.
	 * @param array<string, mixed> $details  The slug, title, description, schemas and saved schema ids.
	 *
	 * @return Register The updated register.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function reconcileApplicationRegister(Register $register, array $details): Register {
		$register->setTitle($details['title']);
		if ($details['description'] !== null) {
			$register->setDescription($details['description']);
		}

		$unionSchemaIds = $this->unshadowedSchemaIds(register: $register, details: $details);
		foreach ($details['schemaIds'] as $newId) {
			if (in_array($newId, $unionSchemaIds, true) === false) {
				$unionSchemaIds[] = $newId;
			}
		}

		$register->setSchemas($unionSchemaIds);
		$register = $this->registerMapper->update($register);

		$this->logger->debug(
			message: '[ImportHandler] Auto-Register reconciled (idempotent re-import)',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'registerId' => $register->getId(),
				'slug' => $details['slug'],
				'unionSchemaIds' => $unionSchemaIds,
			]
		);

		return $register;
	}//end reconcileApplicationRegister()

	/**
	 * A register's listed schema ids, without those a freshly imported schema shadows.
	 *
	 * A listed id is shadowed when its schema's slug matches a newly imported
	 * schema (case-insensitively) but its id differs. An id whose schema
	 * cannot be loaded is kept: there is nothing to compare it against.
	 *
	 * @param Register             $register The existing register.
	 * @param array<string, mixed> $details  The register slug and the imported schemas.
	 *
	 * @return array<int, int>
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function unshadowedSchemaIds(Register $register, array $details): array {
		$newSlugToId = [];
		foreach ($details['schemas'] as $schema) {
			if ($schema instanceof Schema && $schema->getId() !== null && $schema->getSlug() !== null) {
				$newSlugToId[strtolower($schema->getSlug())] = (int)$schema->getId();
			}
		}

		$kept = [];
		foreach ($register->getSchemas() ?? [] as $currentId) {
			$currentId = (int)$currentId;
			try {
				$existingSlug = $this->schemaMapper->find($currentId, _multitenancy: false)->getSlug();
			} catch (\Throwable $ignore) {
				$existingSlug = null;
			}

			$shadowedBy = $newSlugToId[strtolower((string)$existingSlug)] ?? null;
			if ($existingSlug === null || $shadowedBy === null || $shadowedBy === $currentId) {
				$kept[] = $currentId;
				continue;
			}

			$this->logger->debug(
				message: sprintf(
					"[ImportHandler] Auto-Register '%s': pruning shadowed schema id %d (slug '%s') in favour of app-owned id %d",
					$details['slug'],
					$currentId,
					$existingSlug,
					$shadowedBy
				),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}//end foreach

		return $kept;
	}//end unshadowedSchemaIds()

	/**
	 * A result's registers with the given one added, unless a register with its id is already there.
	 *
	 * @param array    $registers The result's registers.
	 * @param Register $register  The register to add.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private static function withRegister(array $registers, Register $register): array {
		foreach ($registers as $existing) {
			if ($existing instanceof Register && $existing->getId() === $register->getId()) {
				return $registers;
			}
		}

		$registers[] = $register;
		return $registers;
	}//end withRegister()

	/**
	 * Provision the magic table of every imported schema in an application register.
	 *
	 * EAGER MAGIC-TABLE CREATION (fixes #1615): every imported schema, not
	 * just those that ship seed data. Without this, log-style schemas
	 * (call_log, job_log, synchronization_log, …), which typically have no
	 * seed data, never get their `oc_openregister_table_{registerId}_{schemaId}`
	 * table on `occ app:enable`, and the first runtime write from a service
	 * like CallService throws "relation does not exist". Idempotent on
	 * re-import: ensureTableForRegisterSchema short-circuits when the table
	 * already exists. A failure is logged so the rest of the import completes.
	 *
	 * @param Register $register The application register.
	 * @param array    $schemas  The imported schemas.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function ensureApplicationMagicTables(Register $register, array $schemas): void {
		foreach ($schemas as $schema) {
			if ($schema instanceof Schema === false) {
				continue;
			}

			try {
				$this->magicMapper->ensureTableForRegisterSchema(register: $register, schema: $schema);
			} catch (\Exception $e) {
				$this->logger->warning(
					message: '[ImportHandler] Failed to pre-create magic mapper table for schema during register auto-create',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'schema_id' => $schema->getId(),
						'schema_slug' => $schema->getSlug(),
						'register_id' => $register->getId(),
						'error' => $e->getMessage(),
					]
				);
			}
		}//end foreach
	}//end ensureApplicationMagicTables()

	/**
	 * Import configuration from a file path; see {@see importFile()}.
	 *
	 * @param string $appId The application ID.
	 * @param string $filePath The path to the configuration file (relative to Nextcloud root).
	 * @param string $version The configuration version.
	 * @param bool $force Force import regardless of version.
	 *
	 * @return array The import results.
	 *
	 * @throws Exception If file reading or import fails.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)  Force flag to override version checks
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function importFromFilePath(string $appId, string $filePath, string $version, bool $force = false): array {
		return $this->importFile(import: new ConfigurationFileImport(appId: $appId, filePath: $filePath, version: $version, force: $force));
	}//end importFromFilePath()

	/**
	 * Import configuration from a file path.
	 *
	 * This method reads a JSON configuration file from the filesystem,
	 * resolves the path relative to Nextcloud root, and imports it.
	 *
	 * @param ConfigurationFileImport $import The app, the file path, the version and the force flag.
	 *
	 * @return array The import results.
	 *
	 * @throws Exception If file reading or import fails.
	 *
	 * @phpstan-return array{
	 *     registers: array<Register>,
	 *     schemas: array<Schema>,
	 *     objects: array<ObjectEntity>,
	 *     endpoints: array,
	 *     sources: array,
	 *     mappings: array<Mapping>,
	 *     jobs: array,
	 *     synchronizations: array,
	 *     rules: array
	 * }
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) File path resolution has multiple fallback conditions
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Path resolution and JSON parsing have multiple outcomes
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function importFile(ConfigurationFileImport $import): array {
		try {
			$data = (new LocalConfigurationFile(appDataPath: $this->appDataPath))->read(filePath: $import->filePath);

			// Call importFromApp with the parsed data.
			return $this->importFromApp(
				appId: $import->appId,
				data: $data,
				version: $import->version,
				force: $import->force
			);
		} catch (Exception $e) {
			$this->logger->error(
				message: '[ImportHandler] Failed to import configuration from file: ' . $e->getMessage(),
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'appId' => $import->appId,
					'filePath' => $import->filePath,
				]
			);
			throw new Exception('Failed to import configuration from file: ' . $e->getMessage());
		}//end try
	}//end importFile()

	/**
	 * Import seed data objects from configuration.
	 *
	 * Processes the x-openregister.seedData section and creates initial objects
	 * using the ObjectService for proper validation and handling.
	 *
	 * @param SeedImport    $seed          The seed pass: the configuration data with seedData, the
	 *                                     application ID and the result the objects are appended to.
	 * @param Configuration $configuration The configuration entity.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function importSeedData(SeedImport $seed, Configuration $configuration): void {
		$seedData = $seed->configData['x-openregister']['seedData'] ?? null;

		if ($seedData === null || empty($seedData['objects']) === true) {
			$this->logger->debug(
				message: '[ImportHandler] No seed data found for configuration',
				context: ['file' => __FILE__, 'line' => __LINE__, 'appId' => $seed->appId]
			);
			return;
		}

		// Seeding runs as a SYSTEM operation, which withholds object lifecycle
		// events (see MagicMapper::suppressLifecycleEvents()).
		//
		// Eight apps subscribe to those events, so without this every seeded
		// object woke all of them: measured mid-repair on this instance, 155
		// "DocuDesk: Processing event", 116 compliance-subscriber calls and 116
		// queued text-extraction jobs — document extraction and compliance
		// scoring over content that shipped WITH the app, before anyone had
		// configured anything. Seeding is not a user action; there is no intent
		// for a listener to react to.
		//
		// Scoped to seeding alone. Schemas, registers and mappings are imported
		// outside this call and are unaffected, as is every ordinary save.
		// NOSEMGREP: php.lang.security.exec-use.exec-use -- SystemOperationContext is a scope flag, no process is started.
		SystemOperationContext::run(
			function () use ($seed, $configuration): void {
				$this->importSeedDataObjects(seed: $seed, configuration: $configuration);
			}
		);
	}//end importSeedData()

	/**
	 * The object a seed already exists as: the one found by uuid or slug, else a suffixed copy.
	 *
	 * @param ObjectEntity|null $found  What the uuid or slug lookup found.
	 * @param SeedDataObject    $object The seed object, with the schema and register it is imported into.
	 *
	 * @return ObjectEntity|null The existing object, or null when the seed is new.
	 */
	private function orSuffixedSeed(?ObjectEntity $found, SeedDataObject $object): ?ObjectEntity {
		if ($found !== null || isset($object->data['uuid']) === true || $object->register === null) {
			return $found;
		}

		return $this->findSuffixedSeed(object: $object, register: $object->register);
	}//end orSuffixedSeed()

	/**
	 * Set the slug of a new seed object.
	 *
	 * A schema with managed slugs decides it through the one slug handler, so a seed
	 * cannot take a slug another live object holds; without the handler the seed slug
	 * is set as before.
	 *
	 * @param ObjectEntity   $entity The object being seeded.
	 * @param SeedDataObject $object The seed object: its data, schema and the slug it carries.
	 *
	 * @return void
	 */
	private function applySeedSlug(ObjectEntity $entity, SeedDataObject $object): void {
		if ($this->slugHandler === null) {
			$entity->setSlug($object->slug);
			return;
		}

		$clientSlug = $this->seedClientSlug(objectData: $object->data);
		$this->slugHandler->applyOnSeed(request: new SlugRequest(entity: $entity, schema: $object->schema, clientSlug: $clientSlug));
	}//end applySeedSlug()

	/**
	 * A uuid-less seed object stored under a suffixed slug (`<slug>-<14 digits>`), if there is one.
	 *
	 * On a schema with `objectSlugSuffix`, a first import whose slug was held by another object
	 * stored the seed under the suffixed slug; the plain slug lookup does not find it, and
	 * the next import would copy it again.
	 *
	 * @param SeedDataObject $object   The seed object, with the schema it is imported into.
	 * @param Register       $register The register it is imported into.
	 *
	 * @return ObjectEntity|null The object, or null when none is stored under a suffixed variant.
	 */
	private function findSuffixedSeed(SeedDataObject $object, Register $register): ?ObjectEntity {
		$objectData = $object->data;
		$schema = $object->schema;
		$suffixOn = ((($schema->getConfiguration() ?? [])['objectSlugSuffix'] ?? false) === true);
		$managed = ($this->slugHandler !== null && $this->slugHandler->isManaged(schema: $schema) === true);
		if ($managed === false || $this->routingMapper === null || $suffixOn === false) {
			return null;
		}

		$candidate = $this->slugHandler->candidate(
			objectData: $this->withoutSeedMetadataKeys(objectData: $objectData, schema: $schema),
			schema: $schema,
			clientSlug: $this->seedClientSlug(objectData: $objectData)
		);
		if ($candidate === null) {
			return null;
		}

		// `<base>-` of a suffixed variant, the base being cut to fit the column.
		$prefix = substr($this->slugHandler->withSuffix(slug: $candidate, stamp: '00000000000000'), 0, -14);
		foreach ($this->routingMapper->findSlugsByPrefix(register: $register, schema: $schema, prefix: $prefix) as $match) {
			if ($this->slugHandler->isSuffixOf(slug: $match['slug'], candidate: $candidate) === false) {
				continue;
			}

			try {
				return $this->routingMapper->find(
					identifier: $match['uuid'],
					register: $register,
					schema: $schema,
					includeDeleted: false,
					_rbac: false,
					_multitenancy: false
				);
			} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
				continue;
			}
		}

		return null;
	}//end findSuffixedSeed()

	/**
	 * The slug to look a uuid-less seed object up by.
	 *
	 * A schema with managed slugs stores the normalised slug, so the raw seed
	 * slug or title (`School De Linde`) would never find the object it created
	 * on the previous import; the lookup uses the same candidate the save does:
	 * an explicit seed `slug`, else the schema's configured slug source.
	 *
	 * @param SeedDataObject $object The seed object: its data, its slug (else its title) and its schema.
	 *
	 * @return string The slug to look up.
	 */
	private function seedLookupSlug(SeedDataObject $object): string {
		$slug = (string)$object->slug;
		if ($this->slugHandler === null || $this->slugHandler->isManaged(schema: $object->schema) === false) {
			return $slug;
		}

		$candidate = $this->slugHandler->candidate(
			objectData: $this->withoutSeedMetadataKeys(objectData: $object->data, schema: $object->schema),
			schema: $object->schema,
			clientSlug: $this->seedClientSlug(objectData: $object->data)
		);

		return ($candidate ?? $slug);
	}//end seedLookupSlug()

	/**
	 * The slug a seed object asks for itself: its explicit `slug`, never its title.
	 *
	 * The title only serves the idempotency lookup of a schema without managed
	 * slugs; on a managed schema the configured source (`objectSlugField`,
	 * `@schema.name`) decides, exactly as it does for the same object saved
	 * through the API (#4037).
	 *
	 * @param array $objectData The seed object.
	 *
	 * @return string|null The explicit slug, or null when the seed has none.
	 */
	private function seedClientSlug(array $objectData): ?string {
		$slug = $objectData['slug'] ?? null;
		if (is_scalar($slug) === false || trim((string)$slug) === '') {
			return null;
		}

		return (string)$slug;
	}//end seedClientSlug()

	/**
	 * Remove the seed format's top-level `uuid` and `slug` from an object's data.
	 *
	 * A seed object carries its uuid and slug at the top level; the importer
	 * reads them for the idempotency lookup and sets them as object metadata.
	 * Left in the data, MagicMapper discards them as undeclared and logs a
	 * warning for every seeded object on every import. A schema that declares
	 * a `uuid` or `slug` property keeps it as data.
	 *
	 * @param array  $objectData The seed object's data.
	 * @param Schema $schema     The schema the object is written to.
	 *
	 * @return array The data without the undeclared metadata keys.
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-seed-metadata-keys-are-not-stored-as-data
	 */
	private function withoutSeedMetadataKeys(array $objectData, Schema $schema): array {
		$declared = $schema->getProperties();
		if (is_array($declared) === false) {
			$declared = [];
		}

		foreach (['uuid', 'slug'] as $metaKey) {
			if (array_key_exists($metaKey, $declared) === false) {
				unset($objectData[$metaKey]);
			}
		}

		return $objectData;
	}//end withoutSeedMetadataKeys()

	/**
	 * The uuid a listed (components.objects) object names for itself, if any.
	 *
	 * `@self.uuid` wins over the seed format's top-level `uuid`.
	 *
	 * @param array $objectData The listed object.
	 *
	 * @return string|null The uuid, or null when the object names none.
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-seed-metadata-keys-are-not-stored-as-data
	 */
	private function listedObjectUuid(array $objectData): ?string {
		foreach ([($objectData['@self']['uuid'] ?? null), ($objectData['uuid'] ?? null)] as $candidate) {
			if (is_string($candidate) === true && trim($candidate) !== '') {
				return trim($candidate);
			}
		}

		return null;
	}//end listedObjectUuid()

	/**
	 * Import the seed-data objects themselves.
	 *
	 * Split out of {@see importSeedData()} so the whole pass runs inside one
	 * SystemOperationContext without indenting several hundred lines.
	 *
	 * @param SeedImport    $seed          The seed pass.
	 * @param Configuration $configuration The configuration entity.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function importSeedDataObjects(SeedImport $seed, Configuration $configuration): void {
		$seedData = $seed->configData['x-openregister']['seedData'] ?? null;
		$this->startSeedImport(seed: $seed);
		$this->resolveSeedTargetRegister(seed: $seed, configuration: $configuration);

		$this->logger->debug(
			message: '[ImportHandler] Importing seed data objects',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'config_title' => $seed->configData['info']['title'] ?? 'unknown',
				'description' => $seedData['description'] ?? 'no description',
				'object_types' => array_keys($seedData['objects']),
				'target_register' => $seed->targetRegisterId,
			]
		);

		foreach ($seedData['objects'] as $schemaSlug => $objects) {
			$seed->schemaSlug = $schemaSlug;
			$seed->objects = $objects;
			$this->importSeedSchemaObjects(seed: $seed);
		}

		// Info: the summary line, carrying counts. One per import, and the
		// thing you actually want when asking what an import did.
		$this->logger->info(
			message: '[ImportHandler] Seed data import complete',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'config_title' => $seed->configData['info']['title'] ?? 'unknown',
				'imported' => count($seed->result['objects']),
				'related_files' => $seed->result['relatedFiles'] ?? 0,
				'related_notes' => $seed->result['relatedNotes'] ?? 0,
				'related_tasks' => $seed->result['relatedTasks'] ?? 0,
			]
		);
	}//end importSeedDataObjects()

	/**
	 * Note whether a user is logged in and make sure the result's seed counters exist.
	 *
	 * Tasks and notes need a logged-in actor (CalDAV calendar lookup, comment
	 * authorship); at occ install time there is no session, so those item
	 * types skip with a warning. Files are tied to the object's folder, not
	 * the actor, so they always run.
	 *
	 * @param SeedImport $seed The seed pass.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function startSeedImport(SeedImport $seed): void {
		$seed->hasUserContext = ($this->userSession !== null && $this->userSession->getUser() !== null);
		$this->logger->debug(
			message: '[ImportHandler] Seed data import context',
			context: ['file' => __FILE__, 'line' => __LINE__, 'has_user_context' => $seed->hasUserContext]
		);

		$seed->result['relatedFiles'] = ($seed->result['relatedFiles'] ?? 0);
		$seed->result['relatedNotes'] = ($seed->result['relatedNotes'] ?? 0);
		$seed->result['relatedTasks'] = ($seed->result['relatedTasks'] ?? 0);
		// The skipped-entity map may predate the result array this pass is handed.
		$seed->result['skipped'] = ($seed->result['skipped'] ?? []);
		$seed->result['skipped']['seedObjects'] = ($seed->result['skipped']['seedObjects'] ?? 0);
	}//end startSeedImport()

	/**
	 * Pick the register seed objects go to.
	 *
	 * The registers imported IN THIS RUN come first: on a FIRST import the
	 * configuration's register list is only persisted after importFromJson
	 * returns, so reading it here gave "register 0" and every seed object
	 * failed. The configuration's recorded registers are the fallback, for the
	 * re-import where the map is empty but the configuration is populated.
	 *
	 * @param SeedImport    $seed          The seed pass.
	 * @param Configuration $configuration The configuration entity.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function resolveSeedTargetRegister(SeedImport $seed, Configuration $configuration): void {
		$freshRegisters = array_values($this->registersMap);
		$seed->targetRegister = ($freshRegisters[0] ?? null);
		$registerIds = [];
		if ($seed->targetRegister === null) {
			$registerIds = $configuration->getRegisters();
		}

		if (empty($registerIds) === false) {
			$seed->targetRegister = $this->registerMapper->find($registerIds[0], _multitenancy: false, _rbac: false);
		}

		$this->logSeedTargetRegister(seed: $seed);
	}//end resolveSeedTargetRegister()

	/**
	 * Record the target register's id and log which register seed objects go to.
	 *
	 * @param SeedImport $seed The seed pass.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function logSeedTargetRegister(SeedImport $seed): void {
		$seed->targetRegisterId = 0;
		if ($seed->targetRegister === null) {
			$this->logger->warning(
				message: '[ImportHandler] No register found for seedData - using register 0',
				context: ['file' => __FILE__, 'line' => __LINE__, 'appId' => $seed->appId, 'config_title' => $seed->configData['info']['title'] ?? 'unknown']
			);
			return;
		}

		$seed->targetRegisterId = $seed->targetRegister->getId();
		$this->logger->debug(
			message: '[ImportHandler] SeedData will be imported into register',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'register_id' => $seed->targetRegisterId,
				'register_slug' => $seed->targetRegister->getSlug(),
				'register_title' => $seed->targetRegister->getTitle(),
			]
		);
	}//end logSeedTargetRegister()

	/**
	 * Import the seed objects of one schema.
	 *
	 * A slug that resolved to another app's schema its target register does
	 * not list is a stale seed (the same guard as the components.objects
	 * path), so every object under it is skipped.
	 *
	 * @param SeedImport $seed The seed pass, positioned on the schema.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function importSeedSchemaObjects(SeedImport $seed): void {
		$seed->schema = $this->resolveSeedSchema(seed: $seed);
		if ($seed->schema === null) {
			return;
		}

		if ($seed->targetRegister instanceof Register
			&& $this->seedSchemaCheck(appId: $seed->appId)->isForeign(register: $seed->targetRegister, schema: $seed->schema) === true
		) {
			$seed->result['skipped']['seedObjects'] += count((array)$seed->objects);
			return;
		}

		$this->logger->debug(
			message: "[ImportHandler] Importing seed objects for schema '{$seed->schemaSlug}'",
			context: ['file' => __FILE__, 'line' => __LINE__, 'count' => count($seed->objects)]
		);
		$this->ensureSeedObjectTable(seed: $seed);
		foreach ($seed->objects as $objectData) {
			$this->importSeedObject(seed: $seed, object: new SeedDataObject(data: $objectData, seed: $seed));
		}
	}//end importSeedSchemaObjects()

	/**
	 * The schema a seed slug names: the one this import created, else the stored one.
	 *
	 * The stored lookup ignores multitenancy, so another app's schema can be
	 * seeded. PER-ENTITY RESILIENCE: a schema that cannot be resolved skips
	 * only its own seed objects, with a warning.
	 *
	 * @param SeedImport $seed The seed pass, positioned on the schema.
	 *
	 * @return Schema|null The schema, or null when it cannot be resolved.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function resolveSeedSchema(SeedImport $seed): ?Schema {
		$schemaSlug = $seed->schemaSlug;
		if (($this->schemasMap[$schemaSlug] ?? null) !== null) {
			return $this->schemasMap[$schemaSlug];
		}

		try {
			$schema = $this->schemaMapper->find(id: $schemaSlug, _extend: [], _rbac: false, _multitenancy: false);
			$this->logger->debug(
				message: "[ImportHandler] Found schema '{$schemaSlug}' in database for seedData",
				context: ['file' => __FILE__, 'line' => __LINE__, 'schemaId' => $schema->getId(), 'schemaApp' => $schema->getApplication()]
			);
			return $schema;
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: "[ImportHandler] Skipping seed data for schema '{$schemaSlug}' - schema not resolvable: " . $e->getMessage(),
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'appId' => $seed->appId,
					'error' => $e->getMessage(),
					'availableSchemasInMap' => array_keys($this->schemasMap),
				]
			);
			return null;
		}
	}//end resolveSeedSchema()

	/**
	 * Create the magic table of the target register and the current schema before its objects are inserted.
	 *
	 * Non-fatal: when the table cannot be created, the object saves may fail on their own.
	 *
	 * @param SeedImport $seed The seed pass, positioned on the schema.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function ensureSeedObjectTable(SeedImport $seed): void {
		if ($this->magicMapper === null || $seed->targetRegister === null) {
			return;
		}

		try {
			$this->logger->debug(
				message: '[ImportHandler] Pre-creating magic mapper table for schema before importing seed objects',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'schema_id' => $seed->schema->getId(),
					'schema_slug' => $seed->schemaSlug,
					'register_id' => $seed->targetRegisterId,
				]
			);
			$this->magicMapper->ensureTableForRegisterSchema(register: $seed->targetRegister, schema: $seed->schema);
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[ImportHandler] Failed to pre-create magic mapper table',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'schema_slug' => $seed->schemaSlug,
					'register_id' => $seed->targetRegisterId,
					'error' => $e->getMessage(),
				]
			);
		}
	}//end ensureSeedObjectTable()

	/**
	 * Import one seed object, skipping it with a warning when it fails.
	 *
	 * An object without slug and title cannot be found again, so it is not imported.
	 *
	 * @param SeedImport     $seed   The seed pass, positioned on the schema.
	 * @param SeedDataObject $object The seed object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function importSeedObject(SeedImport $seed, SeedDataObject $object): void {
		$this->resolveExternalSeedTarget(seed: $seed, object: $object);
		$object->slug = $object->data['slug'] ?? $object->data['title'] ?? null;
		if ($object->slug === null) {
			$this->logger->error(
				message: "[ImportHandler] Seed for '{$seed->schemaSlug}' missing slug and title",
				context: ['file' => __FILE__, 'line' => __LINE__, 'objectKeys' => array_keys($object->data)]
			);
			return;
		}

		try {
			$this->storeSeedObject(seed: $seed, object: $object);
		} catch (\Throwable $e) {
			// PER-ENTITY RESILIENCE: skip this seed object, keep the rest.
			$seed->result['skipped']['seedObjects']++;
			$this->logger->warning(
				message: "[ImportHandler] Skipping seed object for '{$seed->schemaSlug}' - import failed: " . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'schemaSlug' => $seed->schemaSlug, 'objectSlug' => $object->slug, 'error' => $e->getMessage()]
			);
		}
	}//end importSeedObject()

	/**
	 * Point a seed object at the register and schema its `@self` names in another app's configuration.
	 *
	 * This lets seed data of one app reference schemas and registers of
	 * another app's configuration. Only an object whose `@self` names a
	 * `configuration` is redirected.
	 *
	 * @param SeedImport     $seed   The seed pass, positioned on the schema.
	 * @param SeedDataObject $object The seed object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function resolveExternalSeedTarget(SeedImport $seed, SeedDataObject $object): void {
		$selfData = $object->data['@self'] ?? null;
		if (($selfData['configuration'] ?? null) === null) {
			return;
		}

		$this->logger->debug(
			message: '[ImportHandler] SeedData object references external configuration',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'config_url' => $selfData['configuration'],
				'register_slug' => $selfData['register'] ?? null,
				'schema_slug' => $selfData['schema'] ?? null,
				'object_title' => $object->data['title'] ?? 'unknown',
			]
		);
		$this->resolveExternalSeedRegister(object: $object);
		$this->resolveExternalSeedSchema(seed: $seed, object: $object);
	}//end resolveExternalSeedTarget()

	/**
	 * Point a seed object at the register its `@self.register` slug names, when that register exists.
	 *
	 * @param SeedDataObject $object The seed object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function resolveExternalSeedRegister(SeedDataObject $object): void {
		$slug = $object->data['@self']['register'] ?? null;
		if ($slug === null) {
			return;
		}

		try {
			$slugToIdMap = $this->registerMapper->getSlugToIdMap();
			if (isset($slugToIdMap[$slug]) === false) {
				$this->logger->warning(
					message: '[ImportHandler] External register not found - using default',
					context: ['file' => __FILE__, 'line' => __LINE__, 'requested_slug' => $slug, 'available_slugs' => array_keys($slugToIdMap)]
				);
				return;
			}

			$object->register = $this->registerMapper->find(id: $slugToIdMap[$slug], _rbac: false, _multitenancy: false);
			$object->registerId = $object->register->getId();
			$this->logger->debug(
				message: '[ImportHandler] Resolved external register for seedData object',
				context: ['file' => __FILE__, 'line' => __LINE__, 'slug' => $slug, 'id' => $object->registerId, 'title' => $object->register->getTitle()]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[ImportHandler] Failed to resolve external register',
				context: ['file' => __FILE__, 'line' => __LINE__, 'slug' => $slug, 'error' => $e->getMessage()]
			);
		}
	}//end resolveExternalSeedRegister()

	/**
	 * Point a seed object at the schema its `@self.schema` slug names, when that schema exists.
	 *
	 * @param SeedImport     $seed   The seed pass, positioned on the schema.
	 * @param SeedDataObject $object The seed object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function resolveExternalSeedSchema(SeedImport $seed, SeedDataObject $object): void {
		$slug = $object->data['@self']['schema'] ?? null;
		if ($slug === null) {
			return;
		}

		try {
			$externalSchemas = $this->schemaMapper->findBySlug(slug: $slug, limit: 1, offset: 0, _multitenancy: false, _rbac: false);
			if (empty($externalSchemas) === true) {
				$this->logger->warning(
					message: '[ImportHandler] External schema not found - using current schema',
					context: ['file' => __FILE__, 'line' => __LINE__, 'requested_slug' => $slug, 'current_schema' => $seed->schemaSlug]
				);
				return;
			}

			$object->schema = $externalSchemas[0];
			$this->logger->debug(
				message: '[ImportHandler] Resolved external schema for seedData object',
				context: ['file' => __FILE__, 'line' => __LINE__, 'slug' => $slug, 'id' => $object->schema->getId(), 'title' => $object->schema->getTitle()]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[ImportHandler] Failed to resolve external schema',
				context: ['file' => __FILE__, 'line' => __LINE__, 'slug' => $slug, 'error' => $e->getMessage()]
			);
		}
	}//end resolveExternalSeedSchema()

	/**
	 * Insert a seed object unless it is already stored, then create its related items.
	 *
	 * IDEMPOTENCY: an object found by uuid or slug is counted and left alone,
	 * so running a configuration again never duplicates its seed data; one
	 * that more than one stored object answers to is skipped.
	 *
	 * @param SeedImport     $seed   The seed pass, positioned on the schema.
	 * @param SeedDataObject $object The seed object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function storeSeedObject(SeedImport $seed, SeedDataObject $object): void {
		$object->identifier = ($object->data['uuid']
			?? $this->seedLookupSlug(object: $object));
		$existingObject = $this->findStoredSeedObject(seed: $seed, object: $object);
		if ($object->ambiguous === true) {
			return;
		}

		$existingObject = $this->orSuffixedSeed(found: $existingObject, object: $object);
		if ($existingObject !== null) {
			$this->logger->debug(
				message: '[ImportHandler] Seed object already exists - skipping',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'schema' => $seed->schemaSlug,
					'identifier' => $object->identifier,
					'object_id' => $existingObject->getId(),
				]
			);
			$seed->result['objects'][] = $existingObject->getId();
			return;
		}

		$this->insertSeedObject(seed: $seed, object: $object);
	}//end storeSeedObject()

	/**
	 * The stored object a seed object's uuid or slug finds, across every app and tenant.
	 *
	 * Without a routing mapper or a register there is nothing to look in.
	 * More than one match marks the object ambiguous, with a warning.
	 *
	 * @param SeedImport     $seed   The seed pass, positioned on the schema.
	 * @param SeedDataObject $object The seed object, its lookup identifier set.
	 *
	 * @return ObjectEntity|null The stored object, or null when there is none.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function findStoredSeedObject(SeedImport $seed, SeedDataObject $object): ?ObjectEntity {
		if ($this->routingMapper === null || $object->register === null) {
			return null;
		}

		try {
			return $this->routingMapper->find(
				identifier: $object->identifier,
				register: $object->register,
				schema: $object->schema,
				includeDeleted: false,
				_rbac: false,
				_multitenancy: false
			);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return null;
		} catch (\OCP\AppFramework\Db\MultipleObjectsReturnedException $e) {
			$object->ambiguous = true;
			$this->logger->warning(
				message: "[ImportHandler] Multiple seed objects found with identifier '{$object->identifier}' - skipping to avoid duplication",
				context: ['file' => __FILE__, 'line' => __LINE__, 'schema' => $seed->schemaSlug, 'identifier' => $object->identifier]
			);
			return null;
		}
	}//end findStoredSeedObject()

	/**
	 * Insert a new seed object through the routing mapper (else the object entity mapper), then its related items.
	 *
	 * Seed objects are written by the mapper directly: they are simple and
	 * need none of ObjectService's cascading or validation.
	 *
	 * @param SeedImport     $seed   The seed pass, positioned on the schema.
	 * @param SeedDataObject $object The seed object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function insertSeedObject(SeedImport $seed, SeedDataObject $object): void {
		$createdObject = ($this->routingMapper ?? $this->objectEntityMapper)->insert($this->newSeedEntity(object: $object));
		$seed->result['objects'][] = $createdObject->getId();
		$this->logger->debug(
			message: '[ImportHandler] Seed object imported',
			context: ['file' => __FILE__, 'line' => __LINE__, 'schema' => $seed->schemaSlug, 'object_id' => $createdObject->getId(), 'slug' => $object->slug]
		);

		if (is_array($object->relatedItems) === true && count($object->relatedItems) > 0) {
			$target = $this->relatedTarget(object: $object, created: $createdObject);
			$target->hasUserContext = $seed->hasUserContext;
			$this->relatedItems->create(target: $target, result: $seed->result);
		}
	}//end insertSeedObject()

	/**
	 * Where a seed object's related items go.
	 *
	 * @param SeedDataObject $object  The seed object.
	 * @param ObjectEntity   $created The object as inserted.
	 *
	 * @return SeedRelatedTarget The target, still without its user context.
	 */
	private function relatedTarget(SeedDataObject $object, ObjectEntity $created): SeedRelatedTarget {
		$target = new SeedRelatedTarget(object: $created);
		$target->items = $object->relatedItems;
		$target->registerId = (int)$object->registerId;
		$target->schemaId = (int)$object->schema->getId();
		$target->title = (string)($object->data['title'] ?? $object->slug);
		return $target;
	}//end relatedTarget()

	/**
	 * The entity a new seed object is inserted as.
	 *
	 * The data leaves out the top-level uuid/slug the seed format carries as
	 * metadata unless the schema declares them; the slug is set for future
	 * idempotency checks (#4037).
	 *
	 * @param SeedDataObject $object The seed object.
	 *
	 * @return ObjectEntity The unsaved entity.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function newSeedEntity(SeedDataObject $object): ObjectEntity {
		$objectEntity = new ObjectEntity();
		$objectEntity->setUuid($object->data['uuid'] ?? \Symfony\Component\Uid\Uuid::v4()->toRfc4122());
		$objectEntity->setSchema($object->schema->getId());
		$objectEntity->setRegister($object->registerId);
		$objectEntity->setObject($this->withoutSeedMetadataKeys(objectData: $object->data, schema: $object->schema));
		$this->applySeedSlug(entity: $objectEntity, object: $object);
		$now = new DateTime();
		$objectEntity->setCreated($now);
		$objectEntity->setUpdated($now);
		return $objectEntity;
	}//end newSeedEntity()
}//end class
