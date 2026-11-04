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

use DateTime;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Authorization\GroupProvisioner;
use OCA\OpenRegister\Service\Authorization\RbacGroupCollector;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\File\RegisterFolderProvisioner;
use OCA\OpenRegister\Service\NoteService;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Schema\SchemaChangeSet;
use OCA\OpenRegister\Service\Archival\SelectionListSeeder;
use OCA\OpenRegister\Service\Schema\SchemaVersioningService;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCA\OpenRegister\Service\TaskService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;
use Symfony\Component\Yaml\Yaml;

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
	 * @var MagicMapper The object mapper instance.
	 */
	private readonly MagicMapper $objectEntityMapper;

	/**
	 * Magic mapper instance for handling magic table operations.
	 *
	 * @var MagicMapper|null The magic mapper instance (optional, set via setter).
	 */
	private ?MagicMapper $magicMapper = null;

	/**
	 * MagicMapper for routing objects to correct magic table.
	 *
	 * @var MagicMapper|null The object mapper instance (optional, set via setObjectMapper).
	 */
	private ?MagicMapper $routingMapper = null;

	/**
	 * Configuration mapper instance for handling configuration operations.
	 *
	 * @var ConfigurationMapper The configuration mapper instance.
	 */
	private readonly ConfigurationMapper $configurationMapper;

	/**
	 * HTTP client for fetching JSON from URLs.
	 *
	 * @var Client The Guzzle HTTP client instance.
	 */
	private readonly Client $client;

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
	 * Upload handler for processing uploaded JSON data.
	 *
	 * @var UploadHandler The upload handler instance.
	 */
	private readonly UploadHandler $uploadHandler;

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
	 * The schema parts the shipped-baseline guard kept local in the running app import.
	 *
	 * @var array<int, array{slug: string, paths: array<int, string>}>
	 */
	private array $keptLocal = [];

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
	 * Optional task service for seeding related VTODO items.
	 *
	 * @var TaskService|null
	 */
	private ?TaskService $taskService = null;

	/**
	 * Optional note service for seeding related comments.
	 *
	 * @var NoteService|null
	 */
	private ?NoteService $noteService = null;

	/**
	 * Decides the slug of a seed object so it cannot take a slug another live
	 * object holds (#4037); null sets the seed slug as sent.
	 *
	 * @var \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugHandler|null
	 */
	private ?\OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugHandler $slugHandler = null;

	/**
	 * Optional file service for seeding related attachments.
	 *
	 * @var FileService|null
	 */
	private ?FileService $fileService = null;

	/**
	 * Optional provisioner that gives every register an app import returns its Files folder.
	 *
	 * @var RegisterFolderProvisioner|null
	 */
	private ?RegisterFolderProvisioner $folderProvisioner = null;

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
	 * @param MagicMapper $objectEntityMapper The object entity mapper.
	 * @param ConfigurationMapper $configurationMapper The configuration mapper.
	 * @param MappingMapper $mappingMapper The mapping mapper.
	 * @param Client $client The HTTP client for URL fetching.
	 * @param IAppConfig $appConfig The app config.
	 * @param LoggerInterface $logger The logger interface.
	 * @param string $appDataPath The app data path.
	 * @param UploadHandler $uploadHandler The upload handler.
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
		MagicMapper $objectEntityMapper,
		ConfigurationMapper $configurationMapper,
		MappingMapper $mappingMapper,
		Client $client,
		IAppConfig $appConfig,
		LoggerInterface $logger,
		string $appDataPath,
		UploadHandler $uploadHandler,
		ObjectOperationsInterface $objectService,
		private readonly ?\OCA\OpenRegister\Service\Oas\OasRequestValidator $schemaShapeValidator = null,
		private readonly ?\OCA\OpenRegister\Service\ShippedBaseline\ShippedConfigurationGuard $shippedGuard = null,
		private readonly ?AppImportJobRecorder $importJobRecorder = null,
		private readonly ?SchemaImportInstaller $schemaInstaller = null,
	) {
		$this->schemaMapper = $schemaMapper;
		$this->registerMapper = $registerMapper;
		$this->objectEntityMapper = $objectEntityMapper;
		$this->configurationMapper = $configurationMapper;
		$this->mappingMapper = $mappingMapper;
		$this->client = $client;
		$this->appConfig = $appConfig;
		$this->logger = $logger;
		$this->appDataPath = $appDataPath;
		$this->uploadHandler = $uploadHandler;
		$this->objectService = $objectService;
		$this->shippedContent = new ShippedSeedContent();
	}//end __construct()

	/**
	 * Set the ObjectService dependency.
	 *
	 * This method allows setting the ObjectService after construction
	 * to avoid circular dependency issues.
	 *
	 * @param ObjectOperationsInterface $objectService The object service instance.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function setObjectService(ObjectOperationsInterface $objectService): void {
		$this->objectService = $objectService;
	}//end setObjectService()

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
		$this->taskService = $taskService;
	}//end setTaskService()

	/**
	 * Inject the NoteService used by seed-related-items to attach comments.
	 *
	 * @param NoteService|null $noteService Optional note service.
	 *
	 * @return void
	 */
	public function setNoteService(?NoteService $noteService): void {
		$this->noteService = $noteService;
	}//end setNoteService()

	/**
	 * Inject the slug handler that decides the slug of a seed object (#4037).
	 *
	 * @param \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugHandler|null $slugHandler Optional slug handler.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
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
		$this->fileService = $fileService;
	}//end setFileService()

	/**
	 * Inject the provisioner importFromApp() uses to give imported registers their folder.
	 *
	 * @param RegisterFolderProvisioner|null $provisioner Optional provisioner; without it the
	 *                                                    first upload makes the folder instead.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-an-app-imported-register-has-its-files-folder-when-the-import-returns-req-rfai-001
	 */
	public function setRegisterFolderProvisioner(?RegisterFolderProvisioner $provisioner): void {
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
	 * @param MagicMapper $magicMapper The magic mapper instance.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function setMagicMapper(MagicMapper $magicMapper): void {
		$this->magicMapper = $magicMapper;
	}//end setMagicMapper()

	/**
	 * Set the MagicMapper dependency for routing objects to storage.
	 *
	 * This method allows setting the MagicMapper after construction for
	 * routing seed data objects to the correct magic table.
	 *
	 * @param MagicMapper $objectMapper The object mapper instance.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function setObjectMapper(MagicMapper $objectMapper): void {
		$this->routingMapper = $objectMapper;
	}//end setObjectMapper()

	/**
	 * Decode JSON or YAML string data into PHP array.
	 *
	 * @param string $data The string data to decode.
	 * @param string|null $type The content type.
	 *
	 * @return array|null The decoded array or null if decoding fails.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Yaml::parse is standard Symfony Yaml pattern
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function decode(string $data, ?string $type): ?array {
		switch ($type) {
			case 'application/json':
				$phpArray = json_decode(json: $data, associative: true);
				break;
			case 'application/yaml':
				$phpArray = Yaml::parse(input: $data);
				break;
			default:
				$phpArray = json_decode(json: $data, associative: true);
				if ($phpArray === null || $phpArray === false) {
					try {
						$phpArray = Yaml::parse(input: $data);
					} catch (Exception $exception) {
						$phpArray = null;
					}
				}
				break;
		}

		if ($phpArray === null || $phpArray === false) {
			return null;
		}

		$phpArray = $this->ensureArrayStructure(data: $phpArray);
		return $phpArray;
	}//end decode()

	/**
	 * Recursively converts stdClass objects to arrays.
	 *
	 * @param mixed $data The data to convert.
	 *
	 * @return array The converted array data.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function ensureArrayStructure(mixed $data): array {
		if (is_object($data) === true) {
			$data = (array)$data;
		}

		if (is_array($data) === true) {
			foreach ($data as $key => $value) {
				if (is_object($value) === true || is_array($value) === true) {
					$data[$key] = $this->ensureArrayStructure(data: $value);
				}
			}
		}

		return $data;
	}//end ensureArrayStructure()

	/**
	 * Get JSON data from uploaded file.
	 *
	 * @param array $uploadedFile The uploaded file data.
	 * @param string|null $_type Unused parameter.
	 *
	 * @return JSONResponse|array The decoded array or error response.
	 *
	 * @SuppressWarnings (PHPMD.UnusedFormalParameter)
	 *
	 * @psalm-return JSONResponse<400, array{error: string, 'MIME-type'?: string}, array<never, never>>|array
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function getJSONfromFile(array $uploadedFile, ?string $_type = null): array|JSONResponse {
		if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
			return new JSONResponse(data: ['error' => 'File upload error: ' . $uploadedFile['error']], statusCode: 400);
		}

		$fileExtension = pathinfo(path: $uploadedFile['name'], flags: PATHINFO_EXTENSION);
		$fileContent = file_get_contents(filename: $uploadedFile['tmp_name']);

		$phpArray = $this->decode(data: $fileContent, type: $fileExtension);
		if ($phpArray === null) {
			return new JSONResponse(
				data: ['error' => 'Failed to decode file content as JSON or YAML', 'MIME-type' => $fileExtension],
				statusCode: 400
			);
		}

		return $phpArray;
	}//end getJSONfromFile()

	/**
	 * Fetch JSON from URL using HTTP GET.
	 *
	 * @param string $url The URL to fetch.
	 *
	 * @return JSONResponse|array
	 *
	 * @throws GuzzleException
	 *
	 * @psalm-return JSONResponse<400, array{error: string, 'Content-Type'?: string}, array<never, never>>|array
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function getJSONfromURL(string $url): array|JSONResponse {
		try {
			$response = $this->client->request('GET', $url);
		} catch (GuzzleException $e) {
			$errorMessage = 'Failed to do a GET api-call on url: ' . $url . ' ' . $e->getMessage();
			return new JSONResponse(data: ['error' => $errorMessage], statusCode: 400);
		}

		$responseBody = $response->getBody()->getContents();
		$contentType = $response->getHeaderLine('Content-Type');
		$phpArray = $this->decode(data: $responseBody, type: $contentType);

		if ($phpArray === null) {
			return new JSONResponse(
				data: ['error' => 'Failed to parse response body as JSON or YAML', 'Content-Type' => $contentType],
				statusCode: 400
			);
		}

		return $phpArray;
	}//end getJSONfromURL()

	/**
	 * Get JSON data from request body.
	 *
	 * @param array|string $phpArray The request body data.
	 *
	 * @return JSONResponse|array The processed array or error response.
	 *
	 * @psalm-return JSONResponse<400, array{error: 'Failed to decode JSON input'}, array<never, never>>|array
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function getJSONfromBody(array|string $phpArray): array|JSONResponse {
		if (is_string($phpArray) === true) {
			$phpArray = json_decode($phpArray, associative: true);
		}

		if ($phpArray === null || $phpArray === false) {
			return new JSONResponse(
				data: ['error' => 'Failed to decode JSON input'],
				statusCode: 400
			);
		}

		$phpArray = $this->ensureArrayStructure(data: $phpArray);
		return $phpArray;
	}//end getJSONfromBody()

	/**
	 * Import a register from configuration data.
	 *
	 * @param array $data The register data.
	 * @param string|null $owner The owner of the register.
	 * @param string|null $appId The application ID.
	 * @param string|null $version The version.
	 * @param bool $force Force import even if version is not newer.
	 *
	 * @return Register The imported register.
	 *
	 * @throws Exception If import fails.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)  Force flag to override version checks
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function importRegister(
		array $data,
		?string $owner = null,
		?string $appId = null,
		?string $version = null,
		bool $force = false,
	): Register {
		$options = new SchemaImportOptions(owner: $owner, appId: $appId, version: $version, force: $force);
		try {
			// Ensure data is consistently an array by converting any stdClass objects.
			$data = $this->ensureArrayStructure(data: $data);

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
			if ($force === false && $this->registerVersionIsCurrent(register: $existingRegister, data: $data) === true) {
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
	}//end importRegister()

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
			$this->handleDuplicateRegisterError(
				slug: $data['slug'],
				appId: $options->appId ?? 'unknown',
				version: $options->version ?? 'unknown'
			);
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
	 * @param array $data The mapping data from the JSON config.
	 * @param array $slugsAndIdsMap Existing slug-to-ID map for lookups.
	 * @param Configuration|null $configuration The configuration entity for tracking.
	 * @param string|null $version The configuration version.
	 * @param bool $force Force import regardless of version.
	 *
	 * @return Mapping|null The imported mapping entity or null if skipped.
	 *
	 * @throws Exception If import fails.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Force flag to override version checks
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function importMapping(
		array $data,
		array $slugsAndIdsMap,
		?Configuration $configuration = null,
		?string $version = null,
		bool $force = false,
	): ?Mapping {
		$slug = $data['slug'] ?? $data['name'] ?? null;

		if ($slug === null) {
			$this->logger->warning(
				message: '[ImportHandler] Mapping has no slug or name — skipping',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}

		// Associate mapping with the configuration.
		if ($configuration !== null) {
			$data['configurations'] = [$configuration->getUuid()];
		}

		// Check if mapping already exists by slug.
		$existingMapping = null;
		if (isset($slugsAndIdsMap[$slug]) === true) {
			try {
				$existingMapping = $this->mappingMapper->find(id: $slugsAndIdsMap[$slug], includeNullOrg: true);
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

			if ($force === false && version_compare($importedVersion, $existingVersion, '<=') === true) {
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
	 * Handle duplicate register error during import.
	 *
	 * @param string $slug The register slug that has duplicates.
	 * @param string $appId The application ID attempting the import.
	 * @param string $version The version being imported.
	 *
	 * @return never
	 *
	 * @throws Exception Always throws with duplicate register information.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function handleDuplicateRegisterError(string $slug, string $appId, string $version) {
		// Get details about the duplicate registers.
		$duplicateInfo = $this->getDuplicateRegisterInfo(slug: $slug);

		$formatStr = "Duplicate register detected during import from app '%s' (version %s). ";
		$formatStr .= "Register with slug '%s' has multiple entries in the database: %s. ";
		$formatStr .= 'Please resolve this by removing duplicate entries or updating the register ';
		$formatStr .= 'slugs to be unique. You can identify duplicates by checking registers with ';
		$formatStr .= 'the same slug, uuid, or id.';

		$errorMessage = sprintf($formatStr, $appId, $version, $slug, $duplicateInfo);

		$this->logger->error(message: '[ImportHandler] ' . $errorMessage, context: ['file' => __FILE__, 'line' => __LINE__]);
		throw new Exception($errorMessage);
	}//end handleDuplicateRegisterError()

	/**
	 * Get detailed information about duplicate registers.
	 *
	 * @param string $slug The register slug to check for duplicates.
	 *
	 * @return string Formatted string with duplicate register information.
	 */
	private function getDuplicateRegisterInfo(string $slug): string {
		try {
			// Try to get all registers with this slug to provide detailed info.
			$registers = $this->registerMapper->findAll();
			$duplicates = array_filter(
				$registers,
				function ($register) use ($slug) {
					return strtolower($register->getSlug() ?? '') === strtolower($slug);
				}
			);

			if (count($duplicates) <= 1) {
				return 'Unable to retrieve detailed duplicate information';
			}

			$info = [];
			foreach ($duplicates as $register) {
				// Format created date.
				$registerCreated = 'unknown';
				if ($register->getCreated() !== null) {
					$registerCreated = $register->getCreated()->format('Y-m-d H:i:s');
				}

				$info[] = sprintf(
					"ID: %s, UUID: %s, Title: '%s', Created: %s",
					$register->getId(),
					$register->getUuid() ?? '',
					$register->getTitle() ?? '',
					$registerCreated
				);
			}

			return implode('; ', $info);
		} catch (Exception $e) {
			return 'Unable to retrieve duplicate information: ' . $e->getMessage();
		}//end try
	}//end getDuplicateRegisterInfo()

	/**
	 * Handle duplicate schema error during import.
	 *
	 * @param string $slug The schema slug that has duplicates.
	 * @param string $appId The application ID attempting the import.
	 * @param string $version The version being imported.
	 *
	 * @return never
	 *
	 * @throws Exception Always throws with duplicate schema information.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function handleDuplicateSchemaError(string $slug, string $appId, string $version) {
		// Get details about the duplicate schemas.
		$duplicateInfo = $this->getDuplicateSchemaInfo(slug: $slug);

		$formatStr = "Duplicate schema detected during import from app '%s' (version %s). ";
		$formatStr .= "Schema with slug '%s' has multiple entries in the database: %s. ";
		$formatStr .= 'Please resolve this by removing duplicate entries or updating the schema ';
		$formatStr .= 'slugs to be unique. You can identify duplicates by checking schemas with ';
		$formatStr .= 'the same slug, uuid, or id.';

		$errorMessage = sprintf($formatStr, $appId, $version, $slug, $duplicateInfo);

		$this->logger->error(message: '[ImportHandler] ' . $errorMessage, context: ['file' => __FILE__, 'line' => __LINE__]);
		throw new Exception($errorMessage);
	}//end handleDuplicateSchemaError()

	/**
	 * Get detailed information about duplicate schemas.
	 *
	 * @param string $slug The schema slug to check for duplicates.
	 *
	 * @return string Formatted string with duplicate schema information.
	 */
	private function getDuplicateSchemaInfo(string $slug): string {
		try {
			// Try to get all schemas with this slug to provide detailed info.
			$schemas = $this->schemaMapper->findAll();
			$duplicates = array_filter(
				$schemas,
				function ($schema) use ($slug) {
					return strtolower($schema->getSlug() ?? '') === strtolower($slug);
				}
			);

			if (count($duplicates) <= 1) {
				return 'Unable to retrieve detailed duplicate information';
			}

			$info = [];
			foreach ($duplicates as $schema) {
				// Format created date.
				$createdDate = 'unknown';
				if ($schema->getCreated() !== null) {
					$createdDate = $schema->getCreated()->format('Y-m-d H:i:s');
				}

				$info[] = sprintf(
					"ID: %s, UUID: %s, Title: '%s', Created: %s",
					$schema->getId(),
					$schema->getUuid() ?? '',
					$schema->getTitle() ?? '',
					$createdDate
				);
			}

			return implode('; ', $info);
		} catch (Exception $e) {
			return 'Unable to retrieve duplicate information: ' . $e->getMessage();
		}//end try
	}//end getDuplicateSchemaInfo()

	/**
	 * Decide whether an incoming schema definition structurally differs from the stored one.
	 *
	 * The importer skips updating an existing schema when the incoming version
	 * is not newer. That gate assumes the `version` field is bumped on every
	 * change, which apps frequently do not do when they add a property or adjust
	 * an authorization rule via a register.d fragment. This method lets the
	 * importer detect that "version-equal but content-changed" case so the
	 * update is applied anyway (see #2075/#2082).
	 *
	 * Only the structural fields that must reach the database are compared:
	 * `properties` (drives the magic-table columns), `required`, and
	 * `authorization` (drives read/write access, whose match rules can
	 * reference newly-added properties). Comparison is order-insensitive so a
	 * mere reordering is not treated as a change.
	 *
	 * @param array $data The incoming schema definition.
	 * @param Schema $existing The currently-stored schema entity.
	 *
	 * @return bool True when a structural field differs and the update must be applied.
	 */
	/**
	 * The keys the shipped-baseline guard compares and resolves.
	 *
	 * The same three `schemaContentDiffers()` treats as structural, and the
	 * ones row 11.36 is written about: a municipality adds a property, or
	 * tightens a constraint, or widens an authorization rule. Annotations are
	 * DELIBERATELY not guarded here: `setConfiguration()` drops an unknown
	 * `x-openregister-*` key, so a guarded annotation would read as removed on
	 * every import and conflict with itself forever. That narrowing is named in
	 * the PR body rather than left to be discovered.
	 *
	 * @var array<int, string>
	 */
	private const SHIPPED_GUARD_KEYS = ['properties', 'required', 'authorization'];

	/**
	 * The generation of the rules an import writes by; raised when they change, so a stored hash does not skip it.
	 */
	private const IMPORT_RULES = 'seed-content-3:';

	/**
	 * Plain configuration keys an import compares besides the annotations.
	 *
	 * @var array<int, string>
	 */
	private const COMPARED_CONFIGURATION_KEYS = ['partyKinds', 'linkRoles', 'orphanCleanup'];

	/**
	 * Resolve an incoming shipped schema against what the instance changed.
	 *
	 * Returns the definition to write. When no guard is wired, or no baseline
	 * has ever been recorded for this schema, the incoming definition comes
	 * back untouched: that is exactly today's behaviour, and it is what every
	 * instance gets on the first import after this ships.
	 *
	 * The baseline that goes with that definition is returned, NOT recorded:
	 * the caller records it with recordPendingBaseline() only once the schema
	 * write has succeeded. Recorded here, a write that then fails (a property
	 * the validator refuses) leaves a baseline the instance never ran, and the
	 * next import reads every old live part as a local edit and keeps it,
	 * while the version still moves on.
	 *
	 * @param array<string, mixed> $data       The incoming schema definition.
	 * @param Schema               $existing   The schema the instance runs.
	 * @param string|null          $appId      The app shipping it.
	 * @param string|null          $appVersion The app version.
	 *
	 * @return array{data: array<string, mixed>, baseline: array<string, mixed>|null, slug: string}
	 *         The definition to write, the baseline to record after it is written
	 *         (null: nothing to record) and the schema slug.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	private function applyShippedBaselineGuard(
		array $data,
		Schema $existing,
		?string $appId,
		?string $appVersion
	): array {
		$unguarded = ['data' => $data, 'baseline' => null, 'slug' => ''];
		if ($this->shippedGuard === null || $appId === null) {
			return $unguarded;
		}

		$slug = (string)($data['slug'] ?? $existing->getSlug() ?? '');
		if ($slug === '') {
			return $unguarded;
		}

		$live = [
			'properties' => $existing->getProperties(),
			'required' => $existing->getRequired(),
			'authorization' => ($existing->getAuthorization() ?? []),
		];

		$incoming = [];
		foreach (self::SHIPPED_GUARD_KEYS as $key) {
			if (array_key_exists($key, $data) === true) {
				$incoming[$key] = $data[$key];
			}
		}

		$result = $this->shippedGuard->guardSchemaUpdate(
			slug: $slug,
			live: $live,
			incoming: $incoming,
			app: $appId,
			appVersion: ($appVersion ?? ''),
			record: false
		);

		$pending = ['data' => $data, 'baseline' => $result['baseline'], 'slug' => $slug];
		if ($result['guarded'] === false) {
			return $pending;
		}

		foreach (self::SHIPPED_GUARD_KEYS as $key) {
			if (array_key_exists($key, $result['definition']) === true) {
				$data[$key] = $result['definition'][$key];
			}
		}

		if ($result['conflicts'] !== []) {
			$this->keptLocal[] = ['slug' => $slug, 'paths' => array_column($result['conflicts'], 'path')];
			$this->logger->warning(
				message: '[ImportHandler] schema kept its local definition for parts the app also changed',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'schema_slug' => $slug,
					'conflicts' => array_column($result['conflicts'], 'path'),
				]
			);
		}

		$pending['data'] = $data;
		return $pending;
	}//end applyShippedBaselineGuard()

	/**
	 * Record the baseline the guard resolved, now that the schema is written.
	 *
	 * @param array{data: array<string, mixed>, baseline: array<string, mixed>|null, slug: string} $guarded    What applyShippedBaselineGuard() returned.
	 * @param string|null                                                                          $appId      The app shipping it.
	 * @param string|null                                                                          $appVersion The app version.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	private function recordPendingBaseline(array $guarded, ?string $appId, ?string $appVersion): void {
		if ($this->shippedGuard === null || $appId === null || $guarded['baseline'] === null || $guarded['slug'] === '') {
			return;
		}

		$this->shippedGuard->recordShipped(
			slug: $guarded['slug'],
			definition: $guarded['baseline'],
			app: $appId,
			appVersion: ($appVersion ?? '')
		);
	}//end recordPendingBaseline()

	/**
	 * Record what the app shipped for a schema that has just been created.
	 *
	 * @param array<string, mixed> $data       The schema definition.
	 * @param string|null          $appId      The app shipping it.
	 * @param string|null          $appVersion The app version.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	private function recordShippedBaseline(array $data, ?string $appId, ?string $appVersion): void {
		if ($this->shippedGuard === null || $appId === null) {
			return;
		}

		$slug = (string)($data['slug'] ?? '');
		if ($slug === '') {
			return;
		}

		$definition = [];
		foreach (self::SHIPPED_GUARD_KEYS as $key) {
			if (array_key_exists($key, $data) === true) {
				$definition[$key] = $data[$key];
			}
		}

		$this->shippedGuard->recordShipped(
			slug: $slug,
			definition: $definition,
			app: $appId,
			appVersion: ($appVersion ?? '')
		);
	}//end recordShippedBaseline()

	/**
	 * Classify the definition an import is about to write against the stored one.
	 *
	 * Null when there is no versioning service, when the import carries no
	 * definition, or when classifying failed: the import itself never breaks
	 * on this, it is only left unclassified, which is how it was before.
	 *
	 * @param Schema               $existing The schema already stored.
	 * @param array<string, mixed> $data     The incoming schema, as it will be written.
	 *
	 * @return SchemaChangeSet|null The change set, or null when not classified.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	private function classifyImportedSchemaChange(Schema $existing, array $data): ?SchemaChangeSet {
		if ($this->schemaVersioning === null
			|| (isset($data['properties']) === false && isset($data['required']) === false)
		) {
			return null;
		}

		try {
			return $this->schemaVersioning->classify(
				existing: $existing,
				newDefinition: [
					'properties' => ($data['properties'] ?? $existing->getProperties() ?? []),
					'required' => ($data['required'] ?? $existing->getRequired() ?? []),
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[ImportHandler] Could not classify an imported schema change: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'schema_id' => $existing->getId()]
			);
			return null;
		}
	}//end classifyImportedSchemaChange()

	/**
	 * Write the changelog entry for an imported schema change, and log a breaking one.
	 *
	 * @param Schema               $schema    The schema as written.
	 * @param SchemaChangeSet|null $changeSet The classified change, or null when not classified.
	 * @param string|null          $appId     The app whose import made the change.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	private function recordImportedSchemaChange(Schema $schema, ?SchemaChangeSet $changeSet, ?string $appId): void {
		if ($this->schemaVersioning === null || $changeSet === null || $changeSet->hasChanges() === false) {
			return;
		}

		$origin = 'configuration import';
		if ($appId !== null) {
			$origin .= ' of '.$appId;
		}

		$this->schemaVersioning->recordChangelog(
			schemaId: (int)$schema->getId(),
			version: $schema->getVersion(),
			changeSet: $changeSet,
			acknowledged: false,
			origin: $origin
		);
	}//end recordImportedSchemaChange()

	/**
	 * Whether an incoming schema says anything different from the stored one.
	 *
	 * @param array<string, mixed> $data The incoming schema definition.
	 * @param Schema $existing The schema already stored.
	 *
	 * @return bool True when the properties, the required list, the authorization or the annotations differ.
	 */
	private function schemaContentDiffers(array $data, Schema $existing): bool {
		$fields = [
			'properties' => $existing->getProperties(),
			'required' => $existing->getRequired(),
			'authorization' => $existing->getAuthorization(),
		];

		foreach ($fields as $key => $storedValue) {
			$incoming = ($data[$key] ?? null);
			if ($this->normaliseForCompare(value: $incoming) !== $this->normaliseForCompare(value: $storedValue)) {
				return true;
			}
		}

		return $this->schemaAnnotationsDiffer(data: $data, existing: $existing);
	}//end schemaContentDiffers()

	/**
	 * Whether the incoming schema declares an `x-openregister-*` annotation
	 * that the stored schema does not carry with the same value.
	 *
	 * WHY THIS EXISTS
	 * ---------------
	 * `schemaContentDiffers()` compared `properties`, `required` and
	 * `authorization` and nothing else, so a change that ONLY adds or edits an
	 * annotation block was invisible to it. Combined with the version gate
	 * above ("skip when incoming <= existing") that produced a silent,
	 * permanent no-op: the annotation sits declared in the app's register JSON,
	 * visible in the repo, and never reaches the running system.
	 *
	 * Measured on openbuild (2026-08-16): `exportJob` declares
	 * `x-openregister-lifecycle` at version 0.1.0 while the instance carried
	 * 1.0.0 (a schema edited once through the UI bumps to 1.0.0). The version
	 * said skip, the content check could not see the missing lifecycle, so
	 * `TransitionEngine::transition()` found no state machine and returned
	 * without doing anything — every export sat at `status: queued` forever,
	 * its background job consumed, with not one line in the log. The same trap
	 * applies to every key in the vocabulary: `mcp`, `calculations`,
	 * `notifications`, `widgets`, `relations`, `archival`.
	 *
	 * TWO DELIBERATE NARROWINGS, both to avoid re-importing on every load:
	 *
	 *  1. Only keys in `Schema::ANNOTATION_VOCABULARY` are compared. An
	 *     unknown key (openbuild really does declare
	 *     `x-openregister-lifecycle-exception`) is dropped on every save, so
	 *     comparing it would differ forever.
	 *  2. Only keys the INCOMING declares are compared. The stored
	 *     configuration also holds keys OpenRegister maintains itself
	 *     (`objectNameField`, `objectDescriptionField`, …) plus annotations an
	 *     operator may have added through the UI; treating those as a
	 *     difference would rewrite them away on the next import.
	 *
	 * Incoming annotations are read from BOTH the top level and
	 * `configuration`, because `Schema::hydrate()` folds sibling-of-properties
	 * `x-openregister-*` keys into `configuration` — app register JSON declares
	 * them at the top level, the stored schema keeps them nested.
	 *
	 * @param array<string,mixed> $data     Incoming schema definition.
	 * @param Schema              $existing The stored schema.
	 *
	 * @return bool True when a declared annotation is missing or different.
	 */
	private function schemaAnnotationsDiffer(array $data, Schema $existing): bool {
		// `getConfiguration(): ?array`, so the null-coalesce already yields an
		// array — an is_array() guard here is dead code and PHPStan says so.
		$stored = ($existing->getConfiguration() ?? []);

		$incomingConfig = ($data['configuration'] ?? []);
		if (is_array($incomingConfig) === false) {
			$incomingConfig = [];
		}

		// Top level first, then `configuration` — the nested form wins, which
		// is the same precedence hydrate() applies when it folds.
		$incoming = [];
		foreach ([$data, $incomingConfig] as $source) {
			foreach ($source as $key => $value) {
				if (self::isComparedConfigurationKey(key: $key) === true) {
					$incoming[$key] = $value;
				}
			}
		}

		foreach ($incoming as $key => $value) {
			$storedValue = ($stored[$key] ?? null);
			if ($this->normaliseForCompare(value: $value) !== $this->normaliseForCompare(value: $storedValue)) {
				return true;
			}
		}

		return false;
	}//end schemaAnnotationsDiffer()

	/**
	 * Whether an incoming configuration key is compared against the stored one.
	 *
	 * Annotations of the vocabulary, plus the party and role vocabularies: a
	 * party kind a schema starts accepting is a configuration change with no
	 * property or version change, and the party writer refuses that kind until
	 * it lands.
	 *
	 * @param int|string $key The configuration key.
	 *
	 * @return bool True when the key is compared.
	 */
	private static function isComparedConfigurationKey(int|string $key): bool {
		if (is_string($key) === false) {
			return false;
		}

		if (in_array($key, self::COMPARED_CONFIGURATION_KEYS, true) === true) {
			return true;
		}

		return str_starts_with($key, 'x-openregister-') === true
			&& in_array($key, Schema::ANNOTATION_VOCABULARY, true) === true;
	}//end isComparedConfigurationKey()

	/**
	 * Normalise a JSON-ish value into an order-insensitive canonical string.
	 *
	 * Associative arrays get their keys sorted recursively; lists of scalars
	 * are sorted by value so a reorder does not read as a change. Empty and
	 * null values collapse to the same canonical form, so "absent" and "empty
	 * array" compare equal.
	 *
	 * @param mixed $value The value to normalise.
	 *
	 * @return string A stable canonical representation.
	 */
	private function normaliseForCompare(mixed $value): string {
		if ($value === null || $value === []) {
			return 'null';
		}

		if (is_array($value) === true) {
			$isList = (array_keys($value) === range(0, (count($value) - 1)));
			if ($isList === true) {
				$parts = array_map(fn ($item) => $this->normaliseForCompare(value: $item), $value);
				sort($parts);
				return '[' . implode(',', $parts) . ']';
			}

			ksort($value);
			$parts = [];
			foreach ($value as $key => $item) {
				$parts[] = json_encode($key) . ':' . $this->normaliseForCompare(value: $item);
			}

			return '{' . implode(',', $parts) . '}';
		}

		return json_encode($value);
	}//end normaliseForCompare()

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
	 * @param array $data The schema data (MUST carry a non-empty `slug`).
	 * @param array|null $registerSchemaIds Pre-import schema ids of the target register(s), or null.
	 * @param string|null $appId The importing application id, or null.
	 * @param string|null $version The configuration version (for duplicate-error reporting).
	 *
	 * @return Schema|null The resolved existing schema, or null when none matches.
	 */
	private function resolveExistingSchemaForImport(
		array $data,
		?array $registerSchemaIds,
		?string $appId,
		?string $version,
	): ?Schema {
		if ($registerSchemaIds !== null) {
			return $this->resolveSchemaWithinRegisterScope(data: $data, registerSchemaIds: $registerSchemaIds, appId: $appId);
		}

		if ($appId !== null && $appId !== '') {
			return $this->resolveSchemaByApplication(data: $data, appId: $appId);
		}

		return $this->resolveSchemaGlobally(data: $data, appId: $appId, version: $version);
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
	 * @param array       $data              The schema data (MUST carry a non-empty `slug`).
	 * @param array       $registerSchemaIds Pre-import schema ids of the target register(s).
	 * @param string|null $appId             The importing application id, or null.
	 *
	 * @return Schema|null The schema already in scope, or null (create a new one).
	 */
	private function resolveSchemaWithinRegisterScope(array $data, array $registerSchemaIds, ?string $appId=null): ?Schema {
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
	 * @param array $data The schema data (MUST carry a non-empty `slug`).
	 * @param string|null $appId The importing application id, for duplicate-error reporting.
	 * @param string|null $version The configuration version, for duplicate-error reporting.
	 *
	 * @return Schema|null The globally-resolved schema, or null (create a new one).
	 */
	private function resolveSchemaGlobally(array $data, ?string $appId, ?string $version): ?Schema {
		try {
			return $this->schemaMapper->find($data['slug'], _multitenancy: false);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			$msg = "Schema '{$data['slug']}' not found, will create new one";
			$this->logger->debug(message: '[ImportHandler] ' . $msg, context: ['file' => __FILE__, 'line' => __LINE__]);
		} catch (\OCA\OpenRegister\Exception\ValidationException $e) {
			$msg = "Schema '{$data['slug']}' not found (ValidationException), will create new one";
			$this->logger->debug(message: '[ImportHandler] ' . $msg, context: ['file' => __FILE__, 'line' => __LINE__]);
		} catch (\OCP\AppFramework\Db\MultipleObjectsReturnedException $e) {
			$this->handleDuplicateSchemaError(
				slug: $data['slug'],
				appId: $appId ?? 'unknown',
				version: $version ?? 'unknown'
			);
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
		$this->assertImportableSchemaShape(data: $data);
		$this->assertImportableSchemaSlug(data: $data);
		$options = new SchemaImportOptions(owner: $owner, appId: $appId, version: $version, force: $force);

		try {
			unset($data['id'], $data['uuid'], $data['organisation']);
			$data = $this->withoutOrphanCleanupForNonAdmin(data: $data);
			$data = $this->normaliseImportedProperties(data: $data, slugsAndIdsMap: $slugsAndIdsMap);

			// A slug is resolved within the target register(s) when the caller
			// knows them (per-register-schema-slug-uniqueness), so an app never
			// reuses another register's schema that merely shares the slug.
			$existingSchema = $this->resolveExistingSchemaForImport(data: $data, registerSchemaIds: $registerSchemaIds, appId: $appId, version: $version);
			if ($existingSchema !== null) {
				return $this->updateImportedSchema(update: new ImportedSchemaUpdate(data: $data, existing: $existingSchema), options: $options);
			}

			return $this->createImportedSchema(data: $data, options: $options);
		} catch (Exception $e) {
			$this->logger->error(
				message: '[ImportHandler] Failed to import schema: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			throw new Exception('Failed to import schema: ' . $e->getMessage(), $e->getCode(), $e);
		}//end try
	}//end importSchema()

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
	 * Refuse a schema whose shape fails the minimal meta-schema, before it is mutated.
	 *
	 * A structurally invalid import (no `properties` map, wrong type) then
	 * fails with a clear error rather than deep in the mapper. Without a
	 * shape validator wired the check stands down.
	 *
	 * @param array $data The schema data.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the shape is invalid.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function assertImportableSchemaShape(array $data): void {
		if ($this->schemaShapeValidator === null) {
			return;
		}

		$shapeErrors = $this->schemaShapeValidator->validate(
			body: $data,
			schema: $this->minimalSchemaShapeMetaSchema()
		);
		if ($shapeErrors === []) {
			return;
		}

		$msg = 'imported schema fails OAS-shape validation: ' . implode(
			'; ',
			array_map(
				static fn ($e) => ($e['path'] . ' ' . $e['message']),
				$shapeErrors
			)
		);
		throw new RuntimeException($msg);
	}//end assertImportableSchemaShape()

	/**
	 * Refuse a schema fragment without a non-empty slug.
	 *
	 * The slug lookup would otherwise raise a \TypeError, an \Error the
	 * caller's `catch (Exception)` never sees, aborting the whole register
	 * import on one bad fragment. A \RuntimeException lets the caller skip
	 * this fragment and import the rest.
	 *
	 * @param array $data The schema data.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the slug is missing or blank.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function assertImportableSchemaSlug(array $data): void {
		$slug = $data['slug'] ?? null;
		if (is_string($slug) === true && trim($slug) !== '') {
			return;
		}

		$title = (string)($data['title'] ?? '');
		$hint = 'no title';
		if ($title !== '') {
			$hint = "title '{$title}'";
		}

		$msg = "imported schema fragment is missing a 'slug' ({$hint}); skipping fragment";
		$this->logger->error(
			message: '[ImportHandler] ' . $msg,
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
		throw new RuntimeException($msg);
	}//end assertImportableSchemaSlug()

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
			$property = $this->normaliseImportedPropertyShape(key: $key, property: $property);
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
	 * Give an imported property an array shape, a title, a type and object-typed empty configurations.
	 *
	 * Empty `objectConfiguration` / `fileConfiguration` maps decode from JSON
	 * as `[]` and are turned back into objects. The binary-ish formats
	 * `string`, `binary` and `byte` are dropped from the property and its items.
	 *
	 * @param int|string $key      The property name, the title when none is set.
	 * @param mixed      $property The property definition.
	 *
	 * @return array The normalised property.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function normaliseImportedPropertyShape(int|string $key, mixed $property): array {
		if (is_object($property) === true) {
			$property = (array)$property;
		}

		if (isset($property['title']) === false || empty($property['title']) === true) {
			$property['title'] = $key;
		}

		$property = $this->withObjectTypedEmptyConfigurations(definition: $property);
		if (($property['items'] ?? null) !== null) {
			if (is_object($property['items']) === true) {
				$property['items'] = (array)$property['items'];
			}

			if (is_array($property['items']) === true) {
				$property['items'] = $this->withObjectTypedEmptyConfigurations(definition: $property['items']);
			}
		}

		if (isset($property['type']) === false) {
			$property['type'] = 'string';
		}

		$property = $this->withoutBinaryFormat(definition: $property);
		if (is_array($property['items'] ?? null) === true) {
			$property['items'] = $this->withoutBinaryFormat(definition: $property['items']);
		}

		return $property;
	}//end normaliseImportedPropertyShape()

	/**
	 * Turn empty `objectConfiguration` and `fileConfiguration` arrays into objects.
	 *
	 * @param array $definition A property or items definition.
	 *
	 * @return array The definition.
	 */
	private function withObjectTypedEmptyConfigurations(array $definition): array {
		foreach (['objectConfiguration', 'fileConfiguration'] as $configKey) {
			if (($definition[$configKey] ?? null) === []) {
				$definition[$configKey] = new stdClass();
			}
		}

		return $definition;
	}//end withObjectTypedEmptyConfigurations()

	/**
	 * Drop a `format` of `string`, `binary` or `byte`.
	 *
	 * @param array $definition A property or items definition.
	 *
	 * @return array The definition.
	 */
	private function withoutBinaryFormat(array $definition): array {
		if (in_array(($definition['format'] ?? null), ['string', 'binary', 'byte'], true) === true) {
			unset($definition['format']);
		}

		return $definition;
	}//end withoutBinaryFormat()

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
		if (is_object($property['objectConfiguration'] ?? null) === true) {
			$property['objectConfiguration'] = (array)$property['objectConfiguration'];
		}

		if (is_array($property['objectConfiguration'] ?? null) === true) {
			$property['objectConfiguration'] = $this->resolveConfigurationSlugs(config: $property['objectConfiguration'], where: 'schema property');
		}

		if (($property['items'] ?? null) !== null && is_object($property['items']) === true) {
			$property['items'] = (array)$property['items'];
		}

		if (is_array($property['items'] ?? null) === false) {
			return $property;
		}

		if (is_object($property['items']['objectConfiguration'] ?? null) === true) {
			$property['items']['objectConfiguration'] = (array)$property['items']['objectConfiguration'];
		}

		if (is_array($property['items']['objectConfiguration'] ?? null) === true) {
			$property['items']['objectConfiguration'] = $this->resolveConfigurationSlugs(
				config: $property['items']['objectConfiguration'],
				where: 'array items schema property'
			);
		}

		return $property;
	}//end resolveImportedObjectConfigurations()

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
		$existing = $update->existing;
		if ($this->isUnchangedReimport(update: $update, options: $options) === true) {
			return $existing;
		}

		$guarded = $this->applyShippedBaselineGuard(
			data: $update->data,
			existing: $existing,
			appId: $options->appId,
			appVersion: $options->version
		);
		$changeSet = $this->classifyImportedSchemaChange(existing: $existing, data: $guarded['data']);
		$data = $guarded['data'];
		if (version_compare($update->incomingVersion(), $update->existingVersion(), '>') === false) {
			$data = $this->withRetainedSchemaVersion(update: new ImportedSchemaUpdate(data: $data, existing: $existing), changeSet: $changeSet);
		}

		$eventsBefore = $this->schemaMapper->updateEventCount(schemaId: (int)$existing->getId());
		$schema = $this->schemaMapper->updateFromArray(id: $existing->getId(), object: $data);
		$schema = $this->schemaMapper->update($this->withImportOwnership(schema: $schema, options: $options));
		$this->installWhenNoEventFired(schema: $schema, eventsBefore: $eventsBefore);
		$this->recordPendingBaseline(guarded: $guarded, appId: $options->appId, appVersion: $options->version);
		$this->recordImportedSchemaChange(schema: $schema, changeSet: $changeSet, appId: $options->appId);

		return $schema;
	}//end updateImportedSchema()

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
		$this->recordShippedBaseline(data: $data, appId: $options->appId, appVersion: $options->version);

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
		$run = new ImportRun(data: $this->ensureArrayStructure(data: $data), configuration: $configuration);
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

		$this->warnRejectedSchemas(run: $run);
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
			$this->recordRejectedSchema(run: $run, rejection: $component);
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
	 * Count and name a schema pass 1 rejected.
	 *
	 * A rejected schema is CONTAINED (siblings still import) but never silent:
	 * it is counted and NAMED in the result, so the operator sees it here
	 * rather than three layers downstream.
	 *
	 * @param ImportRun       $run       The import.
	 * @param ImportComponent $rejection The schema component and the exception that rejected it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function recordRejectedSchema(ImportRun $run, ImportComponent $rejection): void {
		$error = $rejection->error;
		$run->result['skipped']['schemas']++;
		$run->result['failed']['schemas'][] = [
			'key' => (string)$rejection->key,
			'slug' => (string)($rejection->data['slug'] ?? $rejection->key),
			'error' => $error->getMessage(),
		];
		$this->logger->error(
			message: '[ImportHandler] Failed to create schema (Pass 1)',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'schemaKey' => $rejection->key,
				'error' => $error->getMessage(),
				'trace' => $error->getTraceAsString(),
			]
		);
	}//end recordRejectedSchema()

	/**
	 * Warn once, naming every schema pass 1 rejected.
	 *
	 * The per-schema `error` lines are easy to miss in a log that is almost all
	 * `debug`; this is the line that says the import was PARTIAL.
	 *
	 * @param ImportRun $run The import.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function warnRejectedSchemas(ImportRun $run): void {
		if ($run->result['failed']['schemas'] === []) {
			return;
		}

		$rejectedSlugs = array_column($run->result['failed']['schemas'], 'slug');
		$this->logger->warning(
			message: sprintf(
				'[ImportHandler] PARTIAL IMPORT: %d of %d schema(s) were rejected and are NOT '
				. 'linked to any register in this import: %s. Registers declaring them were '
				. 'created without those schema references.',
				count($rejectedSlugs),
				count($run->data['components']['schemas']),
				implode(', ', $rejectedSlugs)
			),
			context: ['file' => __FILE__, 'line' => __LINE__, 'appId' => $run->appId, 'failedSchemas' => $run->result['failed']['schemas']]
		);
	}//end warnRejectedSchemas()

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
			if (($registerData['schemas'] ?? null) !== null && is_array($registerData['schemas']) === true) {
				$component->data['schemas'] = $this->registerSchemaIds(run: $run, schemaSlugs: $registerData['schemas']);
			}

			// Propagate parent-level x-openregister.type onto the register so
			// consuming apps can filter mock/demo data via
			// `GET /api/registers?filters[type]=mock`. A per-register `type`
			// takes precedence, so one configuration file can mix register types.
			$parentType = ($run->data['x-openregister']['type'] ?? null);
			if (isset($component->data['type']) === false && $parentType !== null && $parentType !== '') {
				$component->data['type'] = (string)$parentType;
			}

			$register = $this->importRegisterComponent(run: $run, component: $component);
			if ($register !== null) {
				$this->registersMap[$slug] = $register;
			}
		}
	}//end importRegisterComponents()

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
			$register = $this->importRegister(
				data: $component->data,
				owner: $run->owner,
				appId: $run->appId,
				version: $run->version,
				force: $run->force
			);
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
			$mapping = $this->importMapping(
				data: $component->data,
				slugsAndIdsMap: $run->slugsAndIdsMap,
				configuration: $run->configuration,
				version: $run->version,
				force: $run->force
			);
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
				$this->importListedObject(objectData: $objectData, appId: $run->appId, actingUser: $run->actingUser, result: $run->result);
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
				$this->importListedObject(objectData: $objectData, appId: $run->appId, actingUser: $run->actingUser, result: $run->result);
			} catch (\Throwable $e) {
				if ($e instanceof HookStoppedException === true) {
					$run->refusedByListener = true;
				}

				$this->recordFailedObject(objectData: $objectData, appId: $run->appId, error: $e, result: $run->result);
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
		$seedObjects = [];
		$seedSeen = [];
		foreach ([($data['components']['objects'] ?? null), ($data['objects'] ?? null)] as $seedBucket) {
			foreach ((array)$seedBucket as $seedCandidate) {
				if (is_array($seedBucket) === false || is_array($seedCandidate) === false) {
					continue;
				}

				$seedKey = $this->seedIdentity(seedSelf: $seedCandidate['@self'] ?? []);
				if ($seedKey !== '' && isset($seedSeen[$seedKey]) === true) {
					continue;
				}

				if ($seedKey !== '') {
					$seedSeen[$seedKey] = true;
				}

				$seedObjects[] = $seedCandidate;
			}
		}

		return $seedObjects;
	}//end listedSeedObjects()

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
			$this->importSeedData(
				configData: $run->data,
				owner: $run->owner,
				appId: $run->appId,
				configuration: $run->configuration,
				result: $run->result
			);
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
	 * @param array<string, mixed> $objectData The listed object, with `@self`.
	 * @param string|null          $appId      The importing app.
	 * @param IUser|null           $actingUser The user the save acts as.
	 * @param array<string, mixed> $result     The import result, updated in place.
	 *
	 * @return void
	 *
	 * @throws \Throwable When the object cannot be saved.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function importListedObject(array $objectData, ?string $appId, ?IUser $actingUser, array &$result): void {
		$seed = $this->listedSeed(objectData: $objectData, actor: ['appId' => $appId, 'user' => $actingUser]);
		if ($seed === null) {
			return;
		}

		// A seed whose schema slug resolved to ANOTHER app's schema,
		// one its register does not list, is a stale seed, not a
		// write target ({@see self::seedSchemaIsForeign()}).
		if ($this->seedSchemaIsForeign(register: $seed->register, schema: $seed->schema, appId: $appId) === true) {
			$result['skipped']['objects']++;
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
			$result['objects'][] = $this->createListedObject(seed: $seed, listed: ['data' => $objectData, 'uuid' => $listedUuid]);
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
			$result['unchanged']['objects']++;
			return;
		}

		$result['objects'][] = $updated;
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

		$seedKey = $seed->seedKey();
		$this->rememberShippedContent(appId: $seed->appId, seedKey: $seedKey, shipped: $objectData);
		$this->rememberShippedVersion(
			appId: $seed->appId,
			seedKey: $seedKey,
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
		$this->rememberShippedVersion(appId: $seed->appId, seedKey: $seedKey, version: (string)$listed['importedVersion']);
		$this->rememberShippedContent(appId: $seed->appId, seedKey: $seedKey, shipped: $objectData);
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

		$follow = $this->shippedContent->update(stored: $listed['existing'], shipped: $listed['data'], remembered: $remembered);
		if ($follow !== null) {
			$object = $this->saveListedObject(seed: $seed, save: ['data' => $follow, 'uuid' => $listed['uuid']]);
			$this->rememberShippedContent(appId: $seed->appId, seedKey: $seedKey, shipped: $listed['data']);
			return $object;
		}

		$this->rememberShippedContent(appId: $seed->appId, seedKey: $seedKey, shipped: $listed['data']);
		if ($listed['shipped'] === null) {
			$this->rememberShippedVersion(appId: $seed->appId, seedKey: $seedKey, version: (string)$listed['importedVersion']);
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
	 * @param string|null          $appId   The importing app.
	 * @param string|null          $seedKey The seed's identity; null when there is no app to remember it under.
	 * @param array<string, mixed> $shipped The seed as listed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function rememberShippedContent(?string $appId, ?string $seedKey, array $shipped): void {
		if ($appId === null || $appId === '' || $seedKey === null) {
			return;
		}

		$this->seedHashes()->record(appId: $appId, seed: $seedKey, version: $this->shippedContent->hash(shipped: $shipped));
	}//end rememberShippedContent()

	/**
	 * Remember the version a seed object was shipped at, when the import knows its app.
	 *
	 * @param string|null $appId   The importing app.
	 * @param string|null $seedKey The seed's identity; null when there is no app to remember it under.
	 * @param string      $version The shipped version.
	 *
	 * @return void
	 */
	private function rememberShippedVersion(?string $appId, ?string $seedKey, string $version): void {
		if ($appId === null || $appId === '' || $seedKey === null) {
			return;
		}

		$this->seedVersions()->record(appId: $appId, seed: $seedKey, version: $version);
	}//end rememberShippedVersion()

	/**
	 * Record a listed object that failed twice: counted, named and logged.
	 *
	 * @param array<string, mixed> $objectData The listed object, with `@self`.
	 * @param string|null          $appId      The importing app.
	 * @param \Throwable           $error      Why the save failed.
	 * @param array<string, mixed> $result     The import result, updated in place.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function recordFailedObject(array $objectData, ?string $appId, \Throwable $error, array &$result): void {
		$slug = (string)($objectData['@self']['slug'] ?? '');
		$result['skipped']['objects']++;
		$result['failed']['objects'][] = [
			'slug' => $slug,
			'register' => $objectData['@self']['register'] ?? null,
			'schema' => $objectData['@self']['schema'] ?? null,
			'error' => $error->getMessage(),
			'refusedByListener' => ($error instanceof HookStoppedException),
		];
		$this->logger->warning(
			message: "[ImportHandler] Skipping object '{$slug}' - import failed: " . $error->getMessage(),
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'appId' => $appId,
				'objectSlug' => $slug,
				'registerSlug' => $objectData['@self']['register'] ?? null,
				'schemaSlug' => $objectData['@self']['schema'] ?? null,
				'error' => $error->getMessage(),
			]
		);
	}//end recordFailedObject()

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

		foreach ($objects as $position => $targetData) {
			if (is_array($targetData) === false || $index->isReferenced(self: $targetData['@self'] ?? null) === false) {
				continue;
			}

			$uuid = $this->seedReferenceTargetUuid(targetData: $targetData, appId: $appId);
			if ($uuid === null) {
				continue;
			}

			$objects[$position]['@self']['id'] = $uuid;
			$index->record(self: $objects[$position]['@self']);
		}

		$data['components']['objects'] = $index->replaceInObjects(objects: $objects);
		return $data;
	}//end resolveSeedReferenceTokens()

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

		$uuid = $this->findExistingSeedUuid(
			register: $registerObject,
			schema: $schemaObject,
			slug: $targetData['@self']['slug']
		);
		if (empty($uuid) === true) {
			$uuid = $targetData['@self']['id'] ?? null;
		}

		if (empty($uuid) === true) {
			$uuid = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
		}

		return $uuid;
	}//end seedReferenceTargetUuid()

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
			&& $this->seedSchemaIsForeign(register: $registerObject, schema: $schemaObject, appId: $appId) === true
		) {
			$schemaObject = null;
		}

		return [$registerObject, $schemaObject];
	}//end resolveImportRegisterSchema()

	/**
	 * Whether a seed's schema belongs to another app and not to the seed's register.
	 *
	 * A seed names its schema by slug, and the import resolves a slug it did
	 * not import itself with a GLOBAL lookup. Two apps can ship a schema under
	 * the same slug: stackiq and opencatalogi both used `organization`. When
	 * opencatalogi dropped its own and kept a stale `organization` seed in its
	 * `publication` register, the global lookup found stackiq's schema and the
	 * seed was written into a `publication` x stackiq-`organization` table,
	 * failing NOT NULL on stackiq's required fields. Whichever app installed
	 * second broke.
	 *
	 * Foreign means all three: the register lists schemas and this one is not
	 * among them, the schema names an owning application, and that owner is
	 * neither the importing app nor the register's own app. Each condition
	 * keeps a legitimate seed working: a register with no schema list yet
	 * carries no evidence; an app's own schema may not be linked yet on a first
	 * install that imports its register fragments in several passes; and an app
	 * seeding into another app's register uses a schema that register lists.
	 *
	 * @param Register $register The register the seed names.
	 * @param Schema $schema The schema its slug resolved to.
	 * @param string|null $appId The importing app, or null for an import without one.
	 *
	 * @return bool True when the seed must be skipped.
	 *
	 * @spec openspec/changes/seed-schema-must-belong-to-its-register/specs/data-import-export/spec.md#requirement-a-seed-is-never-written-into-another-apps-schema-its-register-does-not-list
	 */
	private function seedSchemaIsForeign(Register $register, Schema $schema, ?string $appId): bool {
		$linked = array_map('strval', $register->getSchemas());
		if ($linked === []) {
			return false;
		}

		if (in_array((string)$schema->getId(), $linked, true) === true
			|| in_array((string)$schema->getSlug(), $linked, true) === true
		) {
			return false;
		}

		$owner = (string)($schema->getApplication() ?? '');
		if ($owner === ''
			|| $owner === (string)($appId ?? '')
			|| $owner === (string)($register->getApplication() ?? '')
		) {
			return false;
		}

		$this->logger->warning(
			message: '[ImportHandler] Skipping seed object: its schema belongs to another app and is not in its register',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'appId' => $appId,
				'registerSlug' => $register->getSlug(),
				'schemaSlug' => $schema->getSlug(),
				'schemaId' => $schema->getId(),
				'schemaApplication' => $owner,
			]
		);

		return true;
	}//end seedSchemaIsForeign()

	/**
	 * Look up the UUID of an already-imported object with the given
	 * register + schema + slug, so re-imports reuse the same identity.
	 *
	 * @param Register $register The resolved register.
	 * @param Schema $schema The resolved schema.
	 * @param string $slug The seed object slug.
	 *
	 * @return string|null The existing object UUID, or null when none exists.
	 */
	private function findExistingSeedUuid(Register $register, Schema $schema, string $slug): ?string {
		$search = [
			'@self' => [
				'register' => (int)$register->getId(),
				'schema' => (int)$schema->getId(),
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
		$report = $this->keptLocal;
		$this->keptLocal = [];

		return $report;
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
				data: $this->ensureArrayStructure(data: $data),
				version: $version,
				force: $force
			);

			$configuration = ($this->findAppConfiguration(import: $import) ?? $this->createAppConfiguration(import: $import));

			// Run under its own import job id so the objects it creates can be
			// removed by job later (a setup wizard's "remove this example set").
			$this->takeKeptLocalReport();
			$result = $this->importFromJsonAsJob(
				data: $import->data,
				configuration: $configuration,
				appId: $appId,
				version: $version,
				force: $force
			);
			$result['keptLocal'] = ['schemas' => $this->takeKeptLocalReport()];

			$update = new ImportedConfigurationUpdate(configuration: $configuration, result: $result);
			if (count($result['registers']) > 0 || count($result['schemas']) > 0 || count($result['objects']) > 0) {
				$this->trackAppImportResult(import: $import, update: $update);
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
	 * Create and insert the Configuration of a first app import.
	 *
	 * Title and description follow the OAS `info` block first, then the
	 * `x-openregister` extension, then the top level. Instance-specific
	 * settings (autoUpdate, notificationGroups, owner, organisation) are not
	 * imported.
	 *
	 * @param AppImport $import The app import.
	 *
	 * @return Configuration
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function createAppConfiguration(AppImport $import): Configuration {
		$appId = $import->appId;
		$data  = $import->data;
		$info  = $import->info();
		$xOpenregister = $import->extension();

		$configuration = new Configuration();
		$configuration->setTitle($info['title'] ?? $xOpenregister['title'] ?? $data['title'] ?? "Configuration for {$appId}");
		$configuration->setDescription(
			$info['description'] ?? $xOpenregister['description'] ?? $data['description'] ?? "Configuration imported by application {$appId}"
		);
		$configuration->setType($xOpenregister['type'] ?? $data['type'] ?? 'app');
		$configuration->setApp($appId);
		$configuration->setVersion($import->version);

		// A local configuration is maintained by the app.
		$configuration->setIsLocal(true);
		$configuration->setSyncEnabled(false);
		$configuration->setSyncStatus('never');

		if (($xOpenregister['openregister'] ?? null) !== null) {
			$configuration->setOpenregister($xOpenregister['openregister']);
		}

		$this->applyConfigurationSource(configuration: $configuration, xOpenregister: $xOpenregister);

		$configuration->setRegisters([]);
		$configuration->setSchemas([]);
		$configuration->setObjects([]);

		$configuration = $this->configurationMapper->insert($configuration);

		$this->logger->debug(
			message: "[ImportHandler] Created new configuration for app {$appId}",
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'configurationId' => $configuration->getId(),
				'version' => $import->version,
			]
		);

		return $configuration;
	}//end createAppConfiguration()

	/**
	 * Copy the source type, source url and GitHub location from the
	 * `x-openregister` extension onto a Configuration.
	 *
	 * GitHub is read from the nested `github` object, or from the flat
	 * `githubRepo` / `githubBranch` / `githubPath` keys when there is none.
	 *
	 * @param Configuration $configuration The configuration.
	 * @param mixed         $xOpenregister The `x-openregister` extension block.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function applyConfigurationSource(Configuration $configuration, mixed $xOpenregister): void {
		if (($xOpenregister['sourceType'] ?? null) !== null) {
			$configuration->setSourceType($xOpenregister['sourceType']);
		}

		if (($xOpenregister['sourceUrl'] ?? null) !== null) {
			$configuration->setSourceUrl($xOpenregister['sourceUrl']);
		}

		$github = [
			'repo'   => ($xOpenregister['githubRepo'] ?? null),
			'branch' => ($xOpenregister['githubBranch'] ?? null),
			'path'   => ($xOpenregister['githubPath'] ?? null),
		];
		if (($xOpenregister['github'] ?? null) !== null && is_array($xOpenregister['github']) === true) {
			$github = [
				'repo'   => ($xOpenregister['github']['repo'] ?? null),
				'branch' => ($xOpenregister['github']['branch'] ?? null),
				'path'   => ($xOpenregister['github']['path'] ?? null),
			];
		}

		if ($github['repo'] !== null) {
			$configuration->setGithubRepo($github['repo']);
		}

		if ($github['branch'] !== null) {
			$configuration->setGithubBranch($github['branch']);
		}

		if ($github['path'] !== null) {
			$configuration->setGithubPath($github['path']);
		}
	}//end applyConfigurationSource()

	/**
	 * Record what an app import brought in on its Configuration: the ids of
	 * the registers, schemas and objects, the version, and the current
	 * title, description and source.
	 *
	 * @param AppImport                   $import The app import.
	 * @param ImportedConfigurationUpdate $update The configuration and the import result.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function trackAppImportResult(AppImport $import, ImportedConfigurationUpdate $update): void {
		$configuration = $update->configuration;
		$result        = $update->result;

		$registerIds = $this->appendNewEntityIds(
			ids: ($configuration->getRegisters() ?? []),
			entities: array_filter($result['registers'], static fn ($entity): bool => $entity instanceof Register)
		);
		$schemaIds   = $this->appendNewEntityIds(
			ids: ($configuration->getSchemas() ?? []),
			entities: array_filter($result['schemas'], static fn ($entity): bool => $entity instanceof Schema)
		);
		$objectIds   = $this->appendNewEntityIds(
			ids: ($configuration->getObjects() ?? []),
			entities: array_filter($result['objects'], static fn ($entity): bool => $entity instanceof ObjectEntity)
		);

		$configuration->setRegisters($registerIds);
		$configuration->setSchemas($schemaIds);
		$configuration->setObjects($objectIds);
		$configuration->setVersion($import->version);

		$info          = $import->info();
		$xOpenregister = $import->extension();
		$title         = ($info['title'] ?? $xOpenregister['title'] ?? null);
		if ($title !== null) {
			$configuration->setTitle($title);
		}

		$description = ($info['description'] ?? $xOpenregister['description'] ?? null);
		if ($description !== null) {
			$configuration->setDescription($description);
		}

		$this->applyConfigurationSource(configuration: $configuration, xOpenregister: $xOpenregister);

		$this->configurationMapper->update($configuration);

		$this->logger->debug(
			message: "[ImportHandler] Updated configuration entity for app {$import->appId}",
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'configurationId' => $configuration->getId(),
				'totalRegisters' => count($registerIds),
				'totalSchemas' => count($schemaIds),
				'totalObjects' => count($objectIds),
			]
		);
	}//end trackAppImportResult()

	/**
	 * Append the ids of entities not yet in the list.
	 *
	 * @param array<int, mixed>               $ids      The ids already tracked.
	 * @param array<int|string, \OCP\AppFramework\Db\Entity> $entities The imported entities.
	 *
	 * @return array<int, mixed>
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function appendNewEntityIds(array $ids, array $entities): array {
		foreach ($entities as $entity) {
			if (in_array($entity->getId(), $ids, true) === false) {
				$ids[] = $entity->getId();
			}
		}

		return $ids;
	}//end appendNewEntityIds()

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
		$this->autoCreateRegisterIfApplication(
			data: $import->data,
			appId: $import->appId,
			schemas: $update->result['schemas'],
			configuration: $update->configuration,
			result: $update->result
		);

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
	 * @param array         $data          The configuration data.
	 * @param Configuration $configuration The configuration entity.
	 * @param string        $appId         The app id the import runs under.
	 * @param string        $version       The configuration version.
	 * @param bool          $force         Force import regardless of version.
	 *
	 * @return array The importFromJson() result plus `importJobId`.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Mirrors importFromApp()'s force flag.
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-an-app-configuration-import-must-run-under-its-own-import-job-id
	 */
	private function importFromJsonAsJob(
		array $data,
		Configuration $configuration,
		string $appId,
		string $version,
		bool $force
	): array {
		if ($this->importJobRecorder === null) {
			$result = $this->importFromJson(
				data: $data,
				configuration: $configuration,
				owner: $appId,
				appId: $appId,
				version: $version,
				force: $force
			);
			$result['importJobId'] = null;
			return $result;
		}

		$importJobId = $this->importJobRecorder->begin();
		try {
			$result = $this->importFromJson(
				data: $data,
				configuration: $configuration,
				owner: $appId,
				appId: $appId,
				version: $version,
				force: $force
			);
		} finally {
			$this->importJobRecorder->end();
		}

		$recorded = $this->importJobRecorder->record(
			appId: $appId,
			importJobId: $importJobId,
			version: $version,
			objectsWritten: count((array)($result['objects'] ?? []))
		);
		$result['importJobId'] = null;
		if ($recorded === true) {
			$result['importJobId'] = $importJobId;
		}

		return $result;
	}//end importFromJsonAsJob()

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

		foreach ($schemas as $schema) {
			if ($schema instanceof Schema === false || $schema->getId() === null) {
				continue;
			}

			$schemaId = (int)$schema->getId();

			try {
				$registerIds = $this->registerMapper->getAllRegisterIdsWithSchema(schemaId: $schemaId);
			} catch (\Exception $e) {
				$this->logger->warning(
					message: '[ImportHandler] Could not resolve registers for imported schema; magic table not reconciled',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'schema_id' => $schemaId,
						'error' => $e->getMessage(),
					]
				);
				continue;
			}

			foreach ($registerIds as $registerId) {
				try {
					$register = $this->registerMapper->find(
						id: (int)$registerId,
						_rbac: false,
						_multitenancy: false
					);

					$this->magicMapper->ensureTableForRegisterSchema(
						register: $register,
						schema: $schema
					);
				} catch (\Exception $e) {
					$this->logger->warning(
						message: '[ImportHandler] Failed to reconcile magic table for imported schema',
						context: [
							'file' => __FILE__,
							'line' => __LINE__,
							'schema_id' => $schemaId,
							'schema_slug' => $schema->getSlug(),
							'register_id' => $registerId,
							'error' => $e->getMessage(),
						]
					);
				}//end try
			}//end foreach
		}//end foreach

	}//end ensureMagicTablesForImportedSchemas()

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
	 * @param array $data The full OAS document.
	 * @param string $appId The app identifier (caller).
	 * @param array $schemas Imported schemas (Schema entities or stdClass with getId()).
	 * @param Configuration $configuration The Configuration row already persisted.
	 * @param array $result Mutable result array; the resulting Register entity is appended into $result['registers'].
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function autoCreateRegisterIfApplication(
		array $data,
		string $appId,
		array $schemas,
		Configuration $configuration,
		array &$result,
	): void {
		$details = $this->applicationRegisterDetails(data: $data, appId: $appId);
		if ($details === null) {
			return;
		}

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
		$result['registers'] = self::withRegister(registers: ($result['registers'] ?? []), register: $register);

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
	 * Import configuration from a file path.
	 *
	 * This method reads a JSON configuration file from the filesystem,
	 * resolves the path relative to Nextcloud root, and imports it.
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
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)  Force flag to override version checks
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) File path resolution has multiple fallback conditions
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Path resolution and JSON parsing have multiple outcomes
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function importFromFilePath(string $appId, string $filePath, string $version, bool $force = false): array {
		try {
			// SEC-SVC-7: contain the resolved file inside the Nextcloud root.
			// Reject absolute paths and any path-traversal sequence up front so
			// a crafted $filePath (e.g. '../../etc/passwd') cannot escape the
			// intended base directory.
			if (str_starts_with($filePath, '/') === true
				|| str_contains($filePath, '..') === true
				|| preg_match('/[\x00-\x1f]/', $filePath) === 1
			) {
				throw new Exception("Invalid configuration file path: {$filePath}");
			}

			// Establish the allowed base directory (Nextcloud root).
			$baseDir = realpath($this->appDataPath . '/../../../');
			if ($baseDir === false) {
				$baseDir = realpath('/var/www/html');
			}

			// Resolve the file path relative to the Nextcloud root.
			$fullPath = realpath($this->appDataPath . '/../../../' . $filePath);

			// If realpath fails, try direct path from Nextcloud root.
			if ($fullPath === false) {
				$fullPath = realpath('/var/www/html/' . $filePath);
			}

			if ($fullPath === false || file_exists($fullPath) === false) {
				throw new Exception("Configuration file not found: {$filePath}");
			}

			// Final containment check: the resolved real path MUST live under
			// the allowed base directory.
			if ($baseDir === false || str_starts_with($fullPath, $baseDir . '/') === false) {
				throw new Exception("Configuration file is outside the allowed directory: {$filePath}");
			}

			// Read the file contents.
			$jsonContent = file_get_contents($fullPath);
			if ($jsonContent === false) {
				throw new Exception("Failed to read configuration file: {$filePath}");
			}

			// Parse JSON.
			$data = json_decode($jsonContent, true);
			if (json_last_error() !== JSON_ERROR_NONE) {
				throw new Exception('Invalid JSON in configuration file: ' . json_last_error_msg());
			}

			// Set the sourceUrl in the data if not already set.
			// This allows the cron job to track the file location.
			if (isset($data['x-openregister']) === false) {
				$data['x-openregister'] = [];
			}

			if (isset($data['x-openregister']['sourceUrl']) === false) {
				$data['x-openregister']['sourceUrl'] = $filePath;
			}

			if (isset($data['x-openregister']['sourceType']) === false) {
				$data['x-openregister']['sourceType'] = 'local';
			}

			// Call importFromApp with the parsed data.
			return $this->importFromApp(
				appId: $appId,
				data: $data,
				version: $version,
				force: $force
			);
		} catch (Exception $e) {
			$this->logger->error(
				message: '[ImportHandler] Failed to import configuration from file: ' . $e->getMessage(),
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'appId' => $appId,
					'filePath' => $filePath,
				]
			);
			throw new Exception('Failed to import configuration from file: ' . $e->getMessage());
		}//end try
	}//end importFromFilePath()

	/**
	 * Import seed data objects from configuration.
	 *
	 * Processes the x-openregister.seedData section and creates initial objects
	 * using the ObjectService for proper validation and handling.
	 *
	 * @param array $configData The configuration data containing seedData.
	 * @param string|null $owner The owner of the objects.
	 * @param string|null $appId The application ID.
	 * @param Configuration $configuration The configuration entity.
	 * @param array $result The result array to append object IDs to.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 * @spec openspec/specs/data-import-export/spec.md
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function importSeedData(
		array $configData,
		?string $owner,
		?string $appId,
		Configuration $configuration,
		array &$result,
	): void {

		$seedData = $configData['x-openregister']['seedData'] ?? null;

		if ($seedData === null || empty($seedData['objects']) === true) {
			$this->logger->debug(
				message: '[ImportHandler] No seed data found for configuration',
				context: ['file' => __FILE__, 'line' => __LINE__, 'appId' => $appId]
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
		SystemOperationContext::run(
			function () use ($configData, $appId, $configuration, &$result): void {
				$seed = new SeedImport(configData: $configData, result: $result);
				$seed->appId = $appId;
				$this->importSeedDataObjects(seed: $seed, configuration: $configuration);
			}
		);
	}//end importSeedData()

	/**
	 * The object a seed already exists as: the one found by uuid or slug, else a suffixed copy.
	 *
	 * @param ObjectEntity|null $found      What the uuid or slug lookup found.
	 * @param array             $objectData The seed object.
	 * @param Schema            $schema     The schema the seed is imported into.
	 * @param Register|null     $register   The register it is imported into.
	 *
	 * @return ObjectEntity|null The existing object, or null when the seed is new.
	 */
	private function orSuffixedSeed(?ObjectEntity $found, array $objectData, Schema $schema, ?Register $register): ?ObjectEntity {
		if ($found !== null || isset($objectData['uuid']) === true || $register === null) {
			return $found;
		}

		return $this->findSuffixedSeed(objectData: $objectData, schema: $schema, register: $register);
	}//end orSuffixedSeed()

	/**
	 * Set the slug of a new seed object.
	 *
	 * A schema with managed slugs decides it through the one slug handler, so a seed
	 * cannot take a slug another live object holds; without the handler the seed slug
	 * is set as before.
	 *
	 * @param ObjectEntity $entity     The object being seeded.
	 * @param Schema       $schema     Its schema.
	 * @param array        $objectData The seed object.
	 * @param mixed        $objectSlug The slug the seed data carries.
	 *
	 * @return void
	 */
	private function applySeedSlug(ObjectEntity $entity, Schema $schema, array $objectData, mixed $objectSlug): void {
		if ($this->slugHandler === null) {
			$entity->setSlug($objectSlug);
			return;
		}

		$this->slugHandler->applyOnSeed(entity: $entity, schema: $schema, clientSlug: $this->seedClientSlug(objectData: $objectData));
	}//end applySeedSlug()

	/**
	 * A uuid-less seed object stored under a suffixed slug (`<slug>-<14 digits>`), if there is one.
	 *
	 * On a schema with `objectSlugSuffix`, a first import whose slug was held by another object
	 * stored the seed under the suffixed slug; the plain slug lookup does not find it, and
	 * the next import would copy it again.
	 *
	 * @param array    $objectData The seed object.
	 * @param Schema   $schema     The schema the seed is imported into.
	 * @param Register $register   The register it is imported into.
	 *
	 * @return ObjectEntity|null The object, or null when none is stored under a suffixed variant.
	 */
	private function findSuffixedSeed(array $objectData, Schema $schema, Register $register): ?ObjectEntity {
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
	 * @param array  $objectData The seed object.
	 * @param string $slug       The seed's slug, else its title.
	 * @param Schema $schema     The schema the seed is imported into.
	 *
	 * @return string The slug to look up.
	 */
	private function seedLookupSlug(array $objectData, string $slug, Schema $schema): string {
		if ($this->slugHandler === null || $this->slugHandler->isManaged(schema: $schema) === false) {
			return $slug;
		}

		$candidate = $this->slugHandler->candidate(
			objectData: $this->withoutSeedMetadataKeys(objectData: $objectData, schema: $schema),
			schema: $schema,
			clientSlug: $this->seedClientSlug(objectData: $objectData)
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
			&& $this->seedSchemaIsForeign(register: $seed->targetRegister, schema: $seed->schema, appId: $seed->appId) === true
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
				context: ['file' => __FILE__, 'line' => __LINE__, 'objectData' => $object->data]
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
			?? $this->seedLookupSlug(objectData: $object->data, slug: (string)$object->slug, schema: $object->schema));
		$existingObject = $this->findStoredSeedObject(seed: $seed, object: $object);
		if ($object->ambiguous === true) {
			return;
		}

		$existingObject = $this->orSuffixedSeed(found: $existingObject, objectData: $object->data, schema: $object->schema, register: $object->register);
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
			$this->processRelatedItems(
				object: $createdObject,
				relatedItems: $object->relatedItems,
				registerId: (int)$object->registerId,
				schemaId: (int)$object->schema->getId(),
				objectTitle: (string)($object->data['title'] ?? $object->slug),
				hasUserContext: $seed->hasUserContext,
				result: $seed->result
			);
		}
	}//end insertSeedObject()

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
		$this->applySeedSlug(entity: $objectEntity, schema: $object->schema, objectData: $object->data, objectSlug: $object->slug);
		$now = new DateTime();
		$objectEntity->setCreated($now);
		$objectEntity->setUpdated($now);
		return $objectEntity;
	}//end newSeedEntity()

	/**
	 * Create related Nextcloud items (files, notes, tasks) for a freshly
	 * seeded object. Each item type is attempted independently so a
	 * failure in one doesn't block the others.
	 *
	 * @param ObjectEntity $object The freshly seeded object the related items belong to.
	 * @param array<string, mixed> $relatedItems The `_relatedItems` payload — keys: files, notes, tasks.
	 * @param int $registerId Register ID the object lives in.
	 * @param int $schemaId Schema ID of the object.
	 * @param string $objectTitle Human-readable title used in note/task subjects.
	 * @param bool $hasUserContext Whether a logged-in user exists (gates note/task creation).
	 * @param array<string, mixed> $result Result accumulator updated in place with related-item counts.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 * @spec openspec/specs/data-import-export/spec.md
	 * @spec openspec/specs/data-import-export/spec.md
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function processRelatedItems(
		ObjectEntity $object,
		array $relatedItems,
		int $registerId,
		int $schemaId,
		string $objectTitle,
		bool $hasUserContext,
		array &$result,
	): void {
		$files = (array)($relatedItems['files'] ?? []);
		$notes = (array)($relatedItems['notes'] ?? []);
		$tasks = (array)($relatedItems['tasks'] ?? []);

		$this->logger->debug(
			message: '[ImportHandler] Processing related items for seed object',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'object_uuid' => $object->getUuid(),
				'files_count' => count($files),
				'notes_count' => count($notes),
				'tasks_count' => count($tasks),
			]
		);

		$filesCreated = 0;
		if (count($files) > 0 && $this->fileService !== null) {
			$filesCreated = $this->seedRelatedFiles(object: $object, files: $files);
		}

		// Notes and tasks are written as the logged-in user, so without one they are skipped.
		$notesCreated = 0;
		if (count($notes) > 0 && $this->noteService !== null) {
			$notesCreated = $this->seedRelatedNotes(
				object: $object,
				notes: $this->itemsForUser(items: $notes, context: ['kind' => 'notes', 'object' => $object, 'user' => $hasUserContext])
			);
		}

		$tasksCreated = 0;
		if (count($tasks) > 0 && $this->taskService !== null) {
			$tasksCreated = $this->seedRelatedTasks(
				tasks: $this->itemsForUser(items: $tasks, context: ['kind' => 'tasks', 'object' => $object, 'user' => $hasUserContext]),
				owner: ['object' => $object, 'registerId' => $registerId, 'schemaId' => $schemaId, 'title' => $objectTitle]
			);
		}

		$result['relatedFiles'] = ($result['relatedFiles'] ?? 0) + $filesCreated;
		$result['relatedNotes'] = ($result['relatedNotes'] ?? 0) + $notesCreated;
		$result['relatedTasks'] = ($result['relatedTasks'] ?? 0) + $tasksCreated;

		$this->logger->debug(
			message: '[ImportHandler] Related items processed for seed object',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'object_uuid' => $object->getUuid(),
				'files_created' => $filesCreated,
				'notes_created' => $notesCreated,
				'tasks_created' => $tasksCreated,
			]
		);
	}//end processRelatedItems()

	/**
	 * The seed notes or tasks to create: all of them with a user session, none without one.
	 *
	 * @param array                $items   The seed notes or tasks.
	 * @param array<string, mixed> $context The `kind` (notes or tasks), the `object` and whether a `user` is logged in.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function itemsForUser(array $items, array $context): array {
		if ($context['user'] === true) {
			return $items;
		}

		$this->logger->warning(
			message: "[ImportHandler] Skipping seed {$context['kind']} - no user session available",
			context: ['object_uuid' => $context['object']->getUuid(), 'count' => count($items)]
		);

		return [];
	}//end itemsForUser()

	/**
	 * Attach a seed object's files; a `base64:` prefix marks encoded content.
	 *
	 * A file without a name or with non-string content is skipped, and so is
	 * one whose base64 does not decode; a failed write is logged and skipped.
	 *
	 * @param ObjectEntity $object The seed object.
	 * @param array        $files  The file specs: name, content, tags, share.
	 *
	 * @return int The number of files created.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function seedRelatedFiles(ObjectEntity $object, array $files): int {
		$created = 0;
		foreach ($files as $fileSpec) {
			$name = (string)($fileSpec['name'] ?? '');
			$content = ($fileSpec['content'] ?? null);
			if ($name === '' || is_string($content) === false) {
				continue;
			}

			if (str_starts_with($content, 'base64:') === true) {
				$content = base64_decode(substr($content, 7), strict: true);
			}

			if ($content === false) {
				$this->logger->warning(
					message: '[ImportHandler] Seed file base64 decode failed - skipping',
					context: ['object_uuid' => $object->getUuid(), 'name' => $name]
				);
				continue;
			}

			try {
				$this->fileService->addFile($object, $name, $content, (bool)($fileSpec['share'] ?? false), (array)($fileSpec['tags'] ?? []));
				$created++;
			} catch (\Throwable $e) {
				$this->logger->warning(
					message: '[ImportHandler] Seed file creation failed',
					context: [
						'object_uuid' => $object->getUuid(),
						'name' => $name,
						'error' => $e->getMessage(),
					]
				);
			}
		}//end foreach

		return $created;
	}//end seedRelatedFiles()

	/**
	 * Add a seed object's notes; one without a message is skipped, a failed one logged.
	 *
	 * @param ObjectEntity $object The seed object.
	 * @param array        $notes  The note specs: message.
	 *
	 * @return int The number of notes created.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function seedRelatedNotes(ObjectEntity $object, array $notes): int {
		$created = 0;
		foreach ($notes as $noteSpec) {
			$message = (string)($noteSpec['message'] ?? '');
			if ($message === '') {
				continue;
			}

			try {
				$this->noteService->createNote((string)$object->getUuid(), $message);
				$created++;
			} catch (\Throwable $e) {
				$this->logger->warning(
					message: '[ImportHandler] Seed note creation failed',
					context: [
						'object_uuid' => $object->getUuid(),
						'error' => $e->getMessage(),
					]
				);
			}
		}

		return $created;
	}//end seedRelatedNotes()

	/**
	 * Add a seed object's tasks; one without a summary is skipped, a failed one logged.
	 *
	 * @param array                $tasks The task specs: summary, description, status, priority, due.
	 * @param array<string, mixed> $owner The seed `object`, its `registerId`, `schemaId` and `title`.
	 *
	 * @return int The number of tasks created.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function seedRelatedTasks(array $tasks, array $owner): int {
		$created = 0;
		foreach ($tasks as $taskSpec) {
			$summary = (string)($taskSpec['summary'] ?? '');
			if ($summary === '') {
				continue;
			}

			$taskData = [
				'summary' => $summary,
				'description' => (string)($taskSpec['description'] ?? ''),
				'status' => (string)($taskSpec['status'] ?? 'needs-action'),
				'priority' => (int)($taskSpec['priority'] ?? 0),
				'due' => $taskSpec['due'] ?? null,
			];
			try {
				$this->taskService->createTask(
					$owner['registerId'],
					$owner['schemaId'],
					(string)$owner['object']->getUuid(),
					$owner['title'],
					$taskData
				);
				$created++;
			} catch (\Throwable $e) {
				$this->logger->warning(
					message: '[ImportHandler] Seed task creation failed',
					context: [
						'object_uuid' => $owner['object']->getUuid(),
						'summary' => $summary,
						'error' => $e->getMessage(),
					]
				);
			}
		}//end foreach

		return $created;
	}//end seedRelatedTasks()

	/**
	 * Minimal meta-schema describing the structural shape an OR schema
	 * MUST satisfy at import time. Stricter shapes (per-property type
	 * validation, RBAC sigil presence) belong in `SchemaService` after
	 * the import succeeds — this pass only catches the cases that
	 * would fail catastrophically downstream (non-object, missing
	 * `properties`, wrong type for `properties`).
	 *
	 * The full OpenAPI 3.1 meta-schema is vendored at
	 * `lib/Service/Resources/meta/openapi-3.1.0.json` (closes #1378). It
	 * is used by `OasService::validateOas()` for the generated OAS
	 * document; for imported OR register schemas we keep using the
	 * smaller shape check here because OR schemas are not full OAS
	 * documents — they are JSON-Schema-with-OR-extensions, and the
	 * OpenAPI meta would over-reject them.
	 *
	 * @return array<string, mixed>
	 */
	private function minimalSchemaShapeMetaSchema(): array {
		return [
			'type' => 'object',
			'required' => ['properties'],
			'properties' => [
				'title' => ['type' => 'string'],
				'description' => ['type' => 'string'],
				'slug' => ['type' => 'string'],
				'properties' => [
					'type' => 'object',
				],
				'required' => [
					'type' => 'array',
					'items' => ['type' => 'string'],
				],
			],
		];

	}//end minimalSchemaShapeMetaSchema()
}//end class
