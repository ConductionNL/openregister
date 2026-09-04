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
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Authorization\GroupProvisioner;
use OCA\OpenRegister\Service\Authorization\RbacGroupCollector;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\NoteService;
use OCA\OpenRegister\Service\ObjectService;
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
	 * Guard flag to prevent recursive dependency checking.
	 *
	 * When an app is enabled as a dependency, it may boot and load its own configuration,
	 * which could trigger another dependency check. This flag prevents infinite recursion.
	 *
	 * @var boolean
	 */
	private static bool $depCheckActive = false;

	/**
	 * Guard flag used by ensureDependenciesForSeedData() to prevent recursive dependency checking.
	 *
	 * @var boolean
	 */
	private static bool $isDependCheckActive = false;

	/**
	 * Schema mapper instance for handling schema operations.
	 *
	 * @var SchemaMapper The schema mapper instance.
	 */
	private readonly SchemaMapper $schemaMapper;

	/**
	 * Register mapper instance for handling register operations.
	 *
	 * @var RegisterMapper The register mapper instance.
	 */
	private readonly RegisterMapper $registerMapper;

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
	 * @var ObjectService|null The object service instance.
	 */
	private ?ObjectService $objectService = null;

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
	 * Optional file service for seeding related attachments.
	 *
	 * @var FileService|null
	 */
	private ?FileService $fileService = null;

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
	 * Collector for declared RBAC group ids. Dependency-free value object,
	 * created lazily via {@see self::rbacGroupCollector()}.
	 *
	 * @var RbacGroupCollector|null
	 */
	private ?RbacGroupCollector $rbacGroupCollector = null;

	/**
	 * Constructor for ImportHandler.
	 *
	 * @param SchemaMapper $schemaMapper The schema mapper.
	 * @param RegisterMapper $registerMapper The register mapper.
	 * @param MagicMapper $objectEntityMapper The object entity mapper.
	 * @param ConfigurationMapper $configurationMapper The configuration mapper.
	 * @param MappingMapper $mappingMapper The mapping mapper.
	 * @param Client $client The HTTP client for URL fetching.
	 * @param IAppConfig $appConfig The app config.
	 * @param LoggerInterface $logger The logger interface.
	 * @param string $appDataPath The app data path.
	 * @param UploadHandler $uploadHandler The upload handler.
	 * @param ObjectService $objectService The object service.
	 * @param ?\OCA\OpenRegister\Service\Oas\OasRequestValidator $schemaShapeValidator Optional schema-shape validator used at import time.
	 */
	public function __construct(
		SchemaMapper $schemaMapper,
		RegisterMapper $registerMapper,
		MagicMapper $objectEntityMapper,
		ConfigurationMapper $configurationMapper,
		MappingMapper $mappingMapper,
		Client $client,
		IAppConfig $appConfig,
		LoggerInterface $logger,
		string $appDataPath,
		UploadHandler $uploadHandler,
		ObjectService $objectService,
		private readonly ?\OCA\OpenRegister\Service\Oas\OasRequestValidator $schemaShapeValidator = null,
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
	}//end __construct()

	/**
	 * Set the ObjectService dependency.
	 *
	 * This method allows setting the ObjectService after construction
	 * to avoid circular dependency issues.
	 *
	 * @param ObjectService $objectService The object service instance.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function setObjectService(ObjectService $objectService): void {
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
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Register import has multiple exception and version checks
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Version checking and update/create paths add complexity
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
		try {
			// Ensure data is consistently an array by converting any stdClass objects.
			$data = $this->ensureArrayStructure(data: $data);

			// Remove id, uuid, and organisation from the data.
			// Organisation is instance-specific and should not be imported.
			unset($data['id'], $data['uuid'], $data['organisation']);

			// Check if register already exists by slug.
			// CRITICAL: Disable RBAC and multitenancy to find registers from any app/tenant
			// during import. This prevents duplicate creation when importing configurations.
			$existingRegister = null;
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
			} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
				// Register doesn't exist, we'll create a new one.
				$this->logger->debug(
					message: "[ImportHandler] Register '{$data['slug']}' not found, will create new one",
					context: ['file' => __FILE__, 'line' => __LINE__, 'appId' => $appId]
				);
			} catch (\OCP\AppFramework\Db\MultipleObjectsReturnedException $e) {
				// Multiple registers found with the same identifier.
				$this->handleDuplicateRegisterError(
					slug: $data['slug'],
					appId: $appId ?? 'unknown',
					version: $version ?? 'unknown'
				);
			}//end try

			if ($existingRegister !== null) {
				// Compare versions using version_compare for proper semver comparison.
				$existingVersion = $existingRegister->getVersion() ?? '0.0.0';
				if ($force === false && version_compare($data['version'], $existingVersion, '<=') === true) {
					$this->logger->debug(
						message: '[ImportHandler] Skipping register import as existing version is newer or equal.',
						context: ['file' => __FILE__, 'line' => __LINE__]
					);
					// Even though we're skipping the update, we still need to add it to the map.
					return $existingRegister;
				}

				// NEVER DROP A SCHEMA LINK THIS IMPORT COULD NOT RE-ESTABLISH.
				//
				// The caller builds `$data['schemas']` purely from `schemasMap`,
				// which holds only the schemas THIS run processed — the schema
				// pass does `if ($schema === null) { continue; }` before
				// populating it, so a schema that already existed and was skipped
				// or rejected never enters the map. The id list arriving here is
				// then missing it, and this update REPLACES the register's list,
				// unlinking a schema the register still owns.
				//
				// Nothing fails when that happens. The schema keeps its table and
				// its rows; the register simply stops listing it. Every
				// register-scoped read then returns an empty collection for data
				// that is present — indistinguishable from a working-but-empty
				// install, which is exactly how #2935 presented: 60 of dossiq's
				// schemas unlinked, 11 of them holding rows, reported as "the
				// config import silently writes zero objects".
				//
				// Union rather than replace: a link this run can prove stays, and
				// a link it merely cannot see is left alone. The failure direction
				// becomes a STALE link, which `occ
				// openregister:registers:relink-schemas` already reports and
				// repairs; the opposite direction loses reachable data with no
				// error anywhere.
				if (isset($data['schemas']) === true && is_array($data['schemas']) === true) {
					$existingSchemaIds = $existingRegister->getSchemas();
					if (is_array($existingSchemaIds) === true && $existingSchemaIds !== []) {
						$data['schemas'] = array_values(
							array_unique(array_merge($existingSchemaIds, $data['schemas']))
						);
					}
				}

				// Update existing register.
				$existingRegister = $this->registerMapper->updateFromArray(id: $existingRegister->getId(), object: $data);
				if ($owner !== null) {
					$existingRegister->setOwner($owner);
				}

				// Set application if provided.
				if ($appId !== null) {
					$existingRegister->setApplication($appId);
				}

				return $this->registerMapper->update($existingRegister);
			}//end if

			// Create new register.
			// NOTE: createFromArray already calls insert(), so we get a register with an ID.
			$register = $this->registerMapper->createFromArray($data);

			// Set owner and application if provided.
			// These must be set AFTER creation because createFromArray doesn't handle them.
			$needsUpdate = false;

			if ($owner !== null) {
				$register->setOwner($owner);
				$needsUpdate = true;
			}

			if ($appId !== null) {
				$register->setApplication($appId);
				$needsUpdate = true;
			}

			// If we set owner or application, update the register.
			if ($needsUpdate === true) {
				$register = $this->registerMapper->update($register);
			}

			return $register;
		} catch (Exception $e) {
			$this->logger->error(
				message: '[ImportHandler] Failed to import register: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			throw new Exception('Failed to import register: ' . $e->getMessage());
		}//end try
	}//end importRegister()

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
				if (is_string($key) === false || str_starts_with($key, 'x-openregister-') === false) {
					continue;
				}

				if (in_array($key, Schema::ANNOTATION_VOCABULARY, true) === false) {
					continue;
				}

				$incoming[$key] = $value;
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
			return $this->resolveSchemaWithinRegisterScope(data: $data, registerSchemaIds: $registerSchemaIds);
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
	 * @param array $data The schema data (MUST carry a non-empty `slug`).
	 * @param array $registerSchemaIds Pre-import schema ids of the target register(s).
	 *
	 * @return Schema|null The schema already in scope, or null (create a new one).
	 */
	private function resolveSchemaWithinRegisterScope(array $data, array $registerSchemaIds): ?Schema {
		$existingSchema = $this->schemaMapper->findBySlugInIds(slug: $data['slug'], schemaIds: $registerSchemaIds);
		if ($existingSchema !== null) {
			return $existingSchema;
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
	 * @SuppressWarnings(PHPMD.NPathComplexity)       Schema import requires many conditional transformations
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Schema property processing has many type conditions
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Schema import involves complex property transformations
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
		// Pre-validate the schema's shape against a minimal meta-schema
		// before we mutate it. Catches structurally-invalid imports
		// (no `properties` map, wrong type) early, before persistence,
		// so the import API returns a clear error instead of a deep
		// mapper failure later. Validator is null-safe: when not wired,
		// import proceeds on the legacy path.
		if ($this->schemaShapeValidator !== null) {
			$shapeErrors = $this->schemaShapeValidator->validate(
				body: $data,
				schema: $this->minimalSchemaShapeMetaSchema()
			);
			if ($shapeErrors !== []) {
				$msg = 'imported schema fails OAS-shape validation: ' . implode(
					'; ',
					array_map(
						static fn ($e) => ($e['path'] . ' ' . $e['message']),
						$shapeErrors
					)
				);
				throw new RuntimeException($msg);
			}
		}

		// Guard: a schema fragment MUST carry a non-empty slug. Without it the
		// later `$this->schemaMapper->find($data['slug'])` lookup receives null
		// and raises an uncaught \TypeError — which is an \Error, NOT an
		// \Exception, so the caller's `catch (Exception)` never sees it and the
		// WHOLE register import aborts on one bad fragment. Fail fast with a
		// descriptive \RuntimeException (an \Exception) so the caller skips this
		// fragment and continues importing the rest of the app's data layer.
		$slug = $data['slug'] ?? null;
		if (is_string($slug) === false || trim($slug) === '') {
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
		}

		try {
			// Remove id, uuid, and organisation from the data.
			unset($data['id'], $data['uuid'], $data['organisation']);

			// Fix properties that don't have types or have invalid formats.
			if (($data['properties'] ?? null) !== null) {
				foreach ($data['properties'] as $key => &$property) {
					// Ensure property is always an array.
					if (is_object($property) === true) {
						$property = (array)$property;
					}

					// Only set title to key if no title exists, to preserve existing titles.
					if (isset($property['title']) === false || empty($property['title']) === true) {
						$property['title'] = $key;
					}

					// Fix empty objects that became arrays during JSON deserialization.
					if (($property['objectConfiguration'] ?? null) !== null) {
						if (is_array($property['objectConfiguration']) === true && $property['objectConfiguration'] === []) {
							$property['objectConfiguration'] = new stdClass();
						}
					}

					if (($property['fileConfiguration'] ?? null) !== null) {
						if (is_array($property['fileConfiguration']) === true && $property['fileConfiguration'] === []) {
							$property['fileConfiguration'] = new stdClass();
						}
					}

					// Do the same for array items.
					if (($property['items'] ?? null) !== null) {
						if (is_object($property['items']) === true) {
							$property['items'] = (array)$property['items'];
						}

						if (($property['items']['objectConfiguration'] ?? null) !== null) {
							$itemsObjConfig = $property['items']['objectConfiguration'];
							if (is_array($itemsObjConfig) === true && $itemsObjConfig === []) {
								$property['items']['objectConfiguration'] = new stdClass();
							}
						}

						if (($property['items']['fileConfiguration'] ?? null) !== null) {
							$itemsFileConfig = $property['items']['fileConfiguration'];
							if (is_array($itemsFileConfig) === true && $itemsFileConfig === []) {
								$property['items']['fileConfiguration'] = new stdClass();
							}
						}
					}

					if (isset($property['type']) === false) {
						$property['type'] = 'string';
					}

					if (($property['format'] ?? null) !== null
						&& ($property['format'] === 'string'
						|| $property['format'] === 'binary'
						|| $property['format'] === 'byte')
					) {
						unset($property['format']);
					}

					if (($property['items']['format'] ?? null) !== null
						&& ($property['items']['format'] === 'string'
						|| $property['items']['format'] === 'binary'
						|| $property['items']['format'] === 'byte')
					) {
						unset($property['items']['format']);
					}

					// Check if we have the schema for the slug and set that id.
					if (($property['$ref'] ?? null) !== null) {
						if (($slugsAndIdsMap[$property['$ref']] ?? null) !== null) {
							$property['$ref'] = $slugsAndIdsMap[$property['$ref']];
						} elseif (($this->schemasMap[$property['$ref']] ?? null) !== null) {
							$property['$ref'] = $this->schemasMap[$property['$ref']]->getId();
						}
					}

					// 🔴 Both branches write to `items.$ref`. The second one used to
					// write to `$property['$ref']` — the ARRAY's own ref — which did
					// two wrong things at once: it left `items.$ref` as an unmapped
					// slug, and it grafted a schema ID (an INT) onto the array
					// property as a top-level `$ref`.
					//
					// That second effect is not cosmetic. `ValidateObject` strips a
					// top-level `$ref` from string-typed properties and from
					// `items`, but never from an array-typed property, so the int
					// survived into the schema handed to Opis. Opis parses a
					// property's subschema lazily — only when that property is
					// PRESENT in the written data — and then throws
					// `$ref must be a non-empty string` because an int is not a
					// string. The message names neither the property nor the
					// schema, so it reads like a broken register.
					//
					// Only reachable on the `schemasMap` fallback, i.e. when the
					// referenced slug was not part of this import's own
					// slug->id map. That is the FIRST install of a configuration
					// whose cross-references resolve against already-present
					// schemas — which is why it never showed on a long-lived
					// instance and surfaced on every clean one (hermiq's `agent`
					// schema: skillInstalls / contextRefs / delegationAllowlist).
					if (($property['items']['$ref'] ?? null) !== null) {
						if (($slugsAndIdsMap[$property['items']['$ref']] ?? null) !== null) {
							$property['items']['$ref'] = $slugsAndIdsMap[$property['items']['$ref']];
						} elseif (($this->schemasMap[$property['items']['$ref']] ?? null) !== null) {
							$property['items']['$ref'] = $this->schemasMap[$property['items']['$ref']]->getId();
						}
					}

					// Ensure objectConfiguration is an array for consistent access before any checks.
					$objConfig = $property['objectConfiguration'] ?? null;
					if ($objConfig !== null && is_object($objConfig) === true) {
						$property['objectConfiguration'] = (array)$property['objectConfiguration'];
					}

					// Handle register slug/ID in objectConfiguration (new structure).
					if (is_array($property['objectConfiguration'] ?? null) === true
						&& ($property['objectConfiguration']['register'] ?? null) !== null
					) {
						$registerSlug = (string)$property['objectConfiguration']['register'];
						if (($this->registersMap[$registerSlug] ?? null) !== null) {
							$property['objectConfiguration']['register'] = $this->registersMap[$registerSlug]->getId();
						} elseif ($registerSlug !== '') {
							// Try to find existing register in database.
							try {
								$existingRegister = $this->registerMapper->find($registerSlug, _multitenancy: false);
								$property['objectConfiguration']['register'] = $existingRegister->getId();
								$this->registersMap[$registerSlug] = $existingRegister;
							} catch (\OCP\AppFramework\Db\DoesNotExistException|ValidationException $e) {
								$msg = 'Register with slug %s not found during schema property import ';
								$msg .= '(will be resolved after registers are imported).';
								$this->logger->debug(
									message: '[ImportHandler] ' . sprintf($msg, $registerSlug),
									context: ['file' => __FILE__, 'line' => __LINE__]
								);
								unset($property['objectConfiguration']['register']);
							}
						}//end if
					}//end if

					// Handle schema slug/ID in objectConfiguration (new structure).
					if (is_array($property['objectConfiguration'] ?? null) === true
						&& ($property['objectConfiguration']['schema'] ?? null) !== null
					) {
						$schemaSlug = (string)$property['objectConfiguration']['schema'];
						if ($schemaSlug !== '') {
							if (($this->schemasMap[$schemaSlug] ?? null) !== null) {
								$property['objectConfiguration']['schema'] = $this->schemasMap[$schemaSlug]->getId();
							}

							if (($this->schemasMap[$schemaSlug] ?? null) === null) {
								// Try to find existing schema in database.
								try {
									$existingSchema = $this->schemaMapper->find($schemaSlug, _multitenancy: false);
									$property['objectConfiguration']['schema'] = $existingSchema->getId();
									$this->schemasMap[$schemaSlug] = $existingSchema;
								} catch (\OCP\AppFramework\Db\DoesNotExistException|ValidationException $e) {
									$msg = 'Schema with slug %s not found during schema property import ';
									$msg .= '(will be resolved after schemas are imported).';
									$this->logger->debug(
										message: '[ImportHandler] ' . sprintf($msg, $schemaSlug),
										context: ['file' => __FILE__, 'line' => __LINE__]
									);
									unset($property['objectConfiguration']['schema']);
								}
							}
						}//end if
					}//end if

					// Ensure items and its objectConfiguration are arrays for consistent access.
					if (($property['items'] ?? null) !== null) {
						if (is_object($property['items']) === true) {
							$property['items'] = (array)$property['items'];
						}

						if (is_array($property['items']) === true
							&& ($property['items']['objectConfiguration'] ?? null) !== null
							&& is_object($property['items']['objectConfiguration']) === true
						) {
							$property['items']['objectConfiguration'] = (array)$property['items']['objectConfiguration'];
						}
					}

					// Handle register slug/ID in array items objectConfiguration (new structure).
					if (is_array($property['items'] ?? []) === true
						&& is_array($property['items']['objectConfiguration'] ?? []) === true
						&& isset($property['items']['objectConfiguration']['register']) === true
					) {
						$registerSlug = (string)$property['items']['objectConfiguration']['register'];
						if (($this->registersMap[$registerSlug] ?? null) !== null) {
							$mappedRegister = $this->registersMap[$registerSlug];
							$property['items']['objectConfiguration']['register'] = $mappedRegister->getId();
						} elseif ($registerSlug !== '') {
							// Try to find existing register in database.
							try {
								$existingRegister = $this->registerMapper->find($registerSlug, _multitenancy: false);
								$property['items']['objectConfiguration']['register'] = $existingRegister->getId();
								$this->registersMap[$registerSlug] = $existingRegister;
							} catch (\OCP\AppFramework\Db\DoesNotExistException|ValidationException $e) {
								$msg = 'Register with slug %s not found during array items schema property ';
								$msg .= 'import (will be resolved after registers are imported).';
								$this->logger->debug(
									message: '[ImportHandler] ' . sprintf($msg, $registerSlug),
									context: ['file' => __FILE__, 'line' => __LINE__]
								);
								unset($property['items']['objectConfiguration']['register']);
							}
						}//end if
					}//end if

					// Handle schema slug/ID in array items objectConfiguration (new structure).
					if (is_array($property['items'] ?? []) === true
						&& is_array($property['items']['objectConfiguration'] ?? []) === true
						&& isset($property['items']['objectConfiguration']['schema']) === true
					) {
						$schemaSlug = (string)$property['items']['objectConfiguration']['schema'];
						if ($schemaSlug !== '') {
							if (($this->schemasMap[$schemaSlug] ?? null) !== null) {
								$schemaId = $this->schemasMap[$schemaSlug]->getId();
								$property['items']['objectConfiguration']['schema'] = $schemaId;
							}

							if (($this->schemasMap[$schemaSlug] ?? null) === null) {
								// Try to find existing schema in database.
								try {
									$existingSchema = $this->schemaMapper->find($schemaSlug, _multitenancy: false);
									$schemaId = $existingSchema->getId();
									$property['items']['objectConfiguration']['schema'] = $schemaId;
									$this->schemasMap[$schemaSlug] = $existingSchema;
								} catch (\OCP\AppFramework\Db\DoesNotExistException|ValidationException $e) {
									$msg = 'Schema with slug %s not found during array items schema ';
									$msg .= 'property import (will be resolved after schemas are imported).';
									$this->logger->debug(
										message: '[ImportHandler] ' . sprintf($msg, $schemaSlug),
										context: ['file' => __FILE__, 'line' => __LINE__]
									);
									unset($property['items']['objectConfiguration']['schema']);
								}
							}
						}//end if
					}//end if

					// Legacy support: Handle old register property structure.
					if (($property['register'] ?? null) !== null) {
						if (($slugsAndIdsMap[$property['register']] ?? null) !== null) {
							$property['register'] = $slugsAndIdsMap[$property['register']];
						} elseif (($this->registersMap[$property['register']] ?? null) !== null) {
							$property['register'] = $this->registersMap[$property['register']]->getId();
						}
					}

					if (is_array($property['items'] ?? []) === true && isset($property['items']['register']) === true) {
						if (($slugsAndIdsMap[$property['items']['register']] ?? null) !== null) {
							$property['items']['register'] = $slugsAndIdsMap[$property['items']['register']];
						} elseif (($this->registersMap[$property['items']['register']] ?? null) !== null) {
							$property['items']['register'] = $this->registersMap[$property['items']['register']]->getId();
						}
					}
				}//end foreach
			}//end if

			// Check if schema already exists by slug.
			//
			// PER-REGISTER SLUG-UNIQUENESS FIX (openspec/changes/
			// per-register-schema-slug-uniqueness). A schema slug is unique
			// WITHIN a register's own schema set — not globally, and not even
			// per-application: an app can legitimately own several registers
			// that each need their own schema under the same generic slug, and
			// (the historical bug) an app-scoped or global lookup can still
			// resolve to a schema owned by a completely different app/register
			// that merely happens to share the slug (this is how OpenBuild's
			// 'automation' import silently reused a CRM app's schema #71
			// instead of creating its own). When the caller (importFromJson())
			// knows which register(s) this slug is destined for, it passes
			// their PRE-IMPORT schema-id set as $registerSchemaIds; resolution
			// is then scoped to exactly that set via findBySlugInIds(). A slug
			// shared by design across multiple registers still resolves (its id
			// is already in every register that references it); a slug owned
			// elsewhere but NOT in the target register's set is NOT reused —
			// this register gets its own new schema instead (see the create
			// path below). Without register context (a schema not declared by
			// any register in this import) the previous application-scoped /
			// global fallback is preserved for backward compatibility.
			$existingSchema = $this->resolveExistingSchemaForImport(
				data: $data,
				registerSchemaIds: $registerSchemaIds,
				appId: $appId,
				version: $version
			);

			if ($existingSchema !== null) {
				// Compare versions using version_compare for proper semver comparison.
				$existingVersion = $existingSchema->getVersion() ?? '0.0.0';
				$incomingVersion = $data['version'] ?? '0.0.0';
				// The version field is an OPTIMISATION, not the source of truth.
				// Apps routinely add a property (or change an authorization rule)
				// on a schema via a register.d fragment WITHOUT bumping that
				// schema's own `version`, so incoming == existing and this gate
				// would skip the update — yet the configuration-level content
				// version advances separately, leaving the instance "imported"
				// but the schema stale. That silently drops columns (no place to
				// persist the new property) and, when an authorization rule
				// matches the new property, makes every read a 500 (#2075/#2082).
				// So when the version says skip, still apply the update if the
				// schema's structural content actually differs from what is
				// stored. Content is authoritative; the version only lets us
				// skip the no-op case cheaply.
				$versionSaysSkip = ($force === false && version_compare($incomingVersion, $existingVersion, '<=') === true);
				if ($versionSaysSkip === true && $this->schemaContentDiffers(data: $data, existing: $existingSchema) === false) {
					$this->logger->debug(
						message: '[ImportHandler] Skipping schema import: version not newer and content unchanged.',
						context: ['file' => __FILE__, 'line' => __LINE__]
					);
					return $existingSchema;
				}

				if ($versionSaysSkip === true) {
					// Info: an update applied against the version ordering.
					// Exactly the decision someone re-reads the log to find.
					$this->logger->info(
						message: '[ImportHandler] Schema version not newer but content differs; applying update anyway.',
						context: [
							'file' => __FILE__,
							'line' => __LINE__,
							'schema_slug' => $existingSchema->getSlug(),
							'schema_id' => $existingSchema->getId(),
						]
					);
				}

				// Update existing schema.
				$existingSchema = $this->schemaMapper->updateFromArray(id: $existingSchema->getId(), object: $data);
				if ($owner !== null) {
					$existingSchema->setOwner($owner);
				}

				if ($appId !== null) {
					$existingSchema->setApplication($appId);
				}

				return $this->schemaMapper->update($existingSchema);
			}//end if

			// Create new schema.
			$schema = $this->schemaMapper->createFromArray($data);
			if ($owner !== null) {
				$schema->setOwner($owner);
			}

			if ($appId !== null) {
				$schema->setApplication($appId);
			}

			$schema = $this->schemaMapper->update($schema);

			return $schema;
		} catch (Exception $e) {
			$this->logger->error(
				message: '[ImportHandler] Failed to import schema: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			throw new Exception('Failed to import schema: ' . $e->getMessage(), $e->getCode(), $e);
		}//end try
	}//end importSchema()

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
	 * reordering at most triggers one extra import pass.
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

		return hash('sha256', $encoded);
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
		$data = $this->ensureArrayStructure(data: $data);

		// Extract appId and version from data if not provided as parameters.
		if ($appId === null && (($data['appId'] ?? null) !== null)) {
			$appId = $data['appId'];
		}

		if ($version === null && (($data['version'] ?? null) !== null)) {
			$version = $data['version'];
		}

		// Content hash of the definitional payload, computed ONCE from the incoming
		// data before any import step mutates $data (schema import resolves $refs and
		// stamps ids into it). The same value is reused for the skip check below and
		// for the post-import store, so an unchanged release always produces an
		// identical hash on the next run.
		$definitionHash = $this->computeDefinitionHash(data: $data);

		// Create the Nextcloud groups this configuration declares. Deliberately
		// ahead of the content-hash skip below: the skip means "the stored data
		// already matches", which says nothing about whether the GROUPS those
		// authorization blocks name still exist.
		$this->provisionDeclaredGroups(data: $data, appId: $appId);

		// Perform version check if appId and version are available (unless force is enabled).
		if ($appId !== null && $version !== null && $force === false) {
			$storedVersion = $this->appConfig->getValueString('openregister', "imported_config_{$appId}_version", '');
			$storedHash = $this->appConfig->getValueString('openregister', "imported_config_{$appId}_hash", '');

			// The CONTENT HASH decides, on its own. `$definitionHash` covers the
			// fully-merged configuration — monolith plus every register.d
			// fragment — so an identical hash means importing would write exactly
			// what is already stored. There is nothing to do, whatever the
			// version says.
			//
			// This used to require `version_compare($version, $storedVersion, '<=')`
			// as well, and that made the skip unreliable in one direction and
			// wasteful in the other. Three apps (opencatalogi, procest,
			// softwarecatalog) fold a digest into the version they pass here —
			// `1.2.3+frag.a1b2c3d4`, per ADR-037 — so that changing a fragment
			// forces a re-import. But version_compare does NOT treat `+…` as
			// semver build metadata; it compares it as further version parts,
			// LEXICALLY. Whether the gate fired therefore depended on how two md5
			// hashes happened to sort:
			//
			// incoming 1.0.0+frag.abc12345 vs stored 1.0.0+frag.def67890 -> no skip,
			// and the same pair the other way round -> skip.
			//
			// Unchanged content re-imported the whole configuration roughly half
			// the time — measured on the dev instance as the dominant cost of
			// `occ maintenance:repair`, which stalls in OpenCatalogi's
			// InitializeSettings step. A digest has no order, so version_compare
			// was the wrong instrument for it.
			//
			// Correctness is unaffected: a CHANGED fragment changes the merged
			// data, which changes the hash, which fails this equality and lets
			// the import proceed to the per-entity gates exactly as before (#426).
			// A never-stored hash ('' on an install predating the hash) also fails
			// it, so those heal on the next run.
			if ($storedHash !== '' && $storedHash === $definitionHash) {
				$this->logger->debug(
					message: "[ImportHandler] Skipping {$appId}: config content unchanged (v{$version}, stored v{$storedVersion})",
					context: ['file' => __FILE__, 'line' => __LINE__]
				);

				// Return empty result to indicate no import was performed.
				// The `skipped`/`failed` keys are present and zeroed on this path
				// too: a caller that reads them must not have to know which return
				// it got, and "nothing was imported" is not the same answer as
				// "something was rejected".
				return [
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
					'skipped' => [
						'registers' => 0,
						'schemas' => 0,
						'objects' => 0,
						'mappings' => 0,
						'seedObjects' => 0,
					],
					'unchanged' => ['objects' => 0],
					'failed' => ['schemas' => []],
				];
			}//end if
		}//end if

		// Log force import if enabled.
		if ($force === true && $appId !== null && $version !== null) {
			$msg = "Force import enabled for app {$appId} version {$version} - bypassing version check";
			$this->logger->debug(message: '[ImportHandler] ' . $msg, context: ['file' => __FILE__, 'line' => __LINE__]);
		}

		// Reset the maps for this import.
		$this->registersMap = [];
		$this->schemasMap = [];

		$result = [
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
			// Per-entity-resilience observability: how many entities were
			// skipped (with a logged warning) instead of aborting the import.
			'skipped' => [
				'registers' => 0,
				'schemas' => 0,
				'objects' => 0,
				'mappings' => 0,
				'seedObjects' => 0,
			],
			// The entities this import REJECTED, named with the reason. A count
			// alone tells an operator something went wrong but not what, and a
			// rejected SCHEMA is the one failure that propagates: the registers
			// that declare it are created without the link, so the damage
			// surfaces layers away (empty `*_schema` config keys, a seed step
			// that cannot resolve a schema) with nothing pointing back here.
			// The entities this import LEFT ALONE because they are already
			// present at the same or a newer version. Counted because a caller
			// otherwise cannot tell "nothing needed doing" from "everything
			// failed": both arrive as `objects: []`. dossiq's demo-data step
			// read that ambiguity as failure and refused every re-install,
			// breaking the promise its own UI makes ("safe to run more than
			// once").
			'unchanged' => [
				'objects' => 0,
			],
			'failed' => [
				'schemas' => [],
			],
		];

		// PER-REGISTER SLUG-UNIQUENESS (openspec/changes/per-register-schema-slug-uniqueness):
		// computed ONCE, from the RAW (pre-mutation) `components.registers.*.schemas` slug
		// lists, before the schema pass below touches anything. Maps each schema slug this
		// import declares for a register to the UNION of that register's (or those
		// registers') PRE-IMPORT schema ids, so importSchema() below can resolve "does the
		// TARGET register already own this slug" instead of "does ANY app/register own this
		// slug" (the latter is how a foreign same-slug schema gets silently reused).
		$slugToSchemaIds = $this->computeRegisterScopedSchemaIds(data: $data);

		// Process and import schemas if present.
		// TWO-PASS APPROACH: First create all schemas without resolving cross-references,
		// then resolve cross-references after all schemas exist to avoid "Schema not found" errors.
		if (($data['components']['schemas'] ?? null) !== null && is_array($data['components']['schemas']) === true) {
			$slugsAndIdsMap = $this->schemaMapper->getSlugToIdMap();
			$this->logger->debug(
				message: '[ImportHandler] Starting TWO-PASS schema import process',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'totalSchemas' => count($data['components']['schemas']),
					'schemaKeys' => array_keys($data['components']['schemas']),
				]
			);

			// PASS 1: Create all schemas without resolving objectConfiguration.schema references.
			// This ensures all schema entities exist before we try to look them up.
			$this->logger->debug(
				message: '[ImportHandler] PASS 1: Creating all schemas without cross-reference resolution',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			$schemasToResolve = [];
			// Track schemas that need $ref resolution in Pass 2.
			foreach ($data['components']['schemas'] as $key => $schemaData) {
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

				// Blanking `schemasMap` is a TEMPORARY mutation of shared state
				// whose only purpose is to stop importSchema() resolving $refs in
				// Pass 1. Its undo therefore belongs to leaving this region — on
				// EVERY path — which is what `finally` is for.
				//
				// 🔴 It used to be undone on the success path only. One rejected
				// schema then left the map empty for everything that followed, so
				// each later schema re-saved an already-emptied map and the map
				// ended Pass 1 holding ONLY the schemas declared after the LAST
				// failure. The register pass resolves its slugs against that map,
				// finds nothing, and creates the register WITHOUT those links.
				// The fingerprint is arithmetic: in softwarecatalog 2 rejected
				// schemas produced 18 missing register<->schema links out of 20.
				// See ImportHandlerSchemaMapRestoreTest.
				$savedSchemasMap = $this->schemasMap;
				$this->schemasMap = [];
				$schema = null;

				try {
					// Create schema without resolving cross-references.
					$schemaSlugLower = strtolower((string)($schemaData['slug'] ?? $key));
					$schema = $this->importSchema(
						data: $schemaData,
						slugsAndIdsMap: $slugsAndIdsMap,
						owner: $owner,
						appId: $appId,
						version: $version,
						force: $force,
						registerSchemaIds: $slugToSchemaIds[$schemaSlugLower] ?? null
					);
				} catch (Exception $e) {
					// A rejected schema is CONTAINED (siblings still import) but it
					// is never silent: it is counted and NAMED in the result, so the
					// operator sees it here rather than three layers downstream.
					$result['skipped']['schemas']++;
					$result['failed']['schemas'][] = [
						'key' => (string)$key,
						'slug' => (string)($schemaData['slug'] ?? $key),
						'error' => $e->getMessage(),
					];
					$this->logger->error(
						message: '[ImportHandler] Failed to create schema (Pass 1)',
						context: [
							'file' => __FILE__,
							'line' => __LINE__,
							'schemaKey' => $key,
							'error' => $e->getMessage(),
							'trace' => $e->getTraceAsString(),
						]
					);
					// Continue with other schemas instead of failing the entire import.
				} finally {
					// Restore on every path — success, rejection, or an \Error
					// escaping this loop entirely.
					$this->schemasMap = $savedSchemasMap;
				}//end try

				if ($schema === null) {
					continue;
				}

				$this->schemasMap[$schema->getSlug()] = $schema;
				$result['schemas'][] = $schema;
				$schemasToResolve[$key] = $schemaData;
				// Save for Pass 2.
				$this->logger->debug(
					message: '[ImportHandler] Successfully created schema (Pass 1)',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'schemaKey' => $key,
						'schemaSlug' => $schema->getSlug(),
						'schemaId' => $schema->getId(),
					]
				);
			}//end foreach

			// One warning naming every rejected schema, at the end of the pass.
			// The per-schema `error` lines above are one line each in a log that
			// is otherwise almost all `debug`, so on a real import they are easy
			// to miss; this is the line that says the import was PARTIAL.
			if ($result['failed']['schemas'] !== []) {
				$rejectedSlugs = array_column($result['failed']['schemas'], 'slug');
				$this->logger->warning(
					message: sprintf(
						'[ImportHandler] PARTIAL IMPORT: %d of %d schema(s) were rejected and are NOT '
						. 'linked to any register in this import: %s. Registers declaring them were '
						. 'created without those schema references.',
						count($rejectedSlugs),
						count($data['components']['schemas']),
						implode(', ', $rejectedSlugs)
					),
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'appId' => $appId,
						'failedSchemas' => $result['failed']['schemas'],
					]
				);
			}

			$this->logger->debug(
				message: '[ImportHandler] Pass 1 completed - all schemas created',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'createdCount' => count($result['schemas']),
					'createdSchemas' => array_map(fn ($schema) => $schema->getSlug(), $result['schemas']),
				]
			);

			// PASS 2: Now resolve cross-references (objectConfiguration.schema) for all schemas.
			// All schemas now exist, so find() calls will succeed.
			$this->logger->debug(
				message: '[ImportHandler] PASS 2: Resolving schema cross-references',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);

			foreach ($schemasToResolve as $key => $schemaData) {
				if (isset($schemaData['title']) === false && is_string($key) === true) {
					$schemaData['title'] = $key;
				}

				$schemaSlug = $schemaData['slug'] ?? $key;

				// Find the schema we created in Pass 1. Schemas Pass 1 skipped
				// as up-to-date (or already reported as failed) are not in the
				// map — the expected case on every boot of an unchanged config,
				// so debug rather than one warning per schema per import.
				if (($this->schemasMap[$schemaSlug] ?? null) === null) {
					$this->logger->debug(
						message: '[ImportHandler] Schema not found in map for Pass 2 - skipping cross-reference resolution',
						context: ['file' => __FILE__, 'line' => __LINE__, 'schemaSlug' => $schemaSlug]
					);
					continue;
				}

				try {
					$this->logger->debug(
						message: '[ImportHandler] Resolving cross-references for schema (Pass 2)',
						context: ['file' => __FILE__, 'line' => __LINE__, 'schemaSlug' => $schemaSlug]
					);

					// Pass 2 MUST resolve back to the EXACT same schema Pass 1 just
					// created/updated (schemasMap[$schemaSlug]) — not fork a second
					// one. $slugToSchemaIds was computed from PRE-IMPORT
					// register state, so a schema freshly CREATED in Pass 1 is not
					// in it yet; union that schema's own id in so findBySlugInIds()
					// below still finds it deterministically.
					$schemaSlugLower = strtolower((string)$schemaSlug);
					$pass2SchemaIds = $slugToSchemaIds[$schemaSlugLower] ?? null;
					$resolvedSchemaId = $this->schemasMap[$schemaSlug]->getId();
					if ($resolvedSchemaId !== null) {
						$pass2SchemaIds = ($pass2SchemaIds ?? []);
						$pass2SchemaIds[] = (int)$resolvedSchemaId;
					}

					// Re-import with schemasMap populated to resolve cross-references.
					$schema = $this->importSchema(
						data: $schemaData,
						slugsAndIdsMap: $slugsAndIdsMap,
						owner: $owner,
						appId: $appId,
						version: $version,
						force: true,
						// Force update to resolve cross-references.
						registerSchemaIds: $pass2SchemaIds
					);

					// Update in map with resolved version.
					$this->schemasMap[$schema->getSlug()] = $schema;

					$this->logger->debug(
						message: '[ImportHandler] Cross-references resolved for schema (Pass 2)',
						context: [
							'file' => __FILE__,
							'line' => __LINE__,
							'schemaSlug' => $schemaSlug,
							'schemaId' => $schema->getId(),
						]
					);
				} catch (Exception $e) {
					$this->logger->error(
						message: '[ImportHandler] Failed to resolve cross-references for schema (Pass 2)',
						context: [
							'file' => __FILE__,
							'line' => __LINE__,
							'schemaKey' => $key,
							'error' => $e->getMessage(),
							'trace' => $e->getTraceAsString(),
						]
					);
				}//end try
			}//end foreach

			$this->logger->debug(
				message: '[ImportHandler] Schema import process completed (TWO-PASS)',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'importedCount' => count($result['schemas']),
					'importedSchemas' => array_map(fn ($schema) => $schema->getSlug(), $result['schemas']),
				]
			);
		}//end if

		// Process and import registers if present.
		if (($data['components']['registers'] ?? null) !== null && is_array($data['components']['registers']) === true) {
			foreach ($data['components']['registers'] as $slug => $registerData) {
				$slug = strtolower($slug);

				if (($registerData['schemas'] ?? null) !== null && is_array($registerData['schemas']) === true) {
					$schemaIds = [];
					foreach ($registerData['schemas'] as $schemaSlug) {
						// First check if schema exists in schemasMap (schemas imported in this session).
						if (($this->schemasMap[$schemaSlug] ?? null) !== null) {
							$schemaId = $this->schemasMap[$schemaSlug]->getId();
							$schemaIds[] = $schemaId;
							$this->logger->debug(
								message: "[ImportHandler] Schema '{$schemaSlug}' found in schemasMap",
								context: ['file' => __FILE__, 'line' => __LINE__, 'schemaId' => $schemaId]
							);
							continue;
						}

						// Schema not in map. Since the Pass-1 restore fix this has
						// exactly ONE cause — the schema pass rejected it — so name
						// that reason here instead of leaving the operator to
						// correlate two log lines. Do not fall back to a database
						// lookup: it may fail on organisation/multi-tenancy filters
						// during cross-instance imports.
						$rejection = null;
						foreach ($result['failed']['schemas'] as $failedSchema) {
							if ($failedSchema['slug'] === $schemaSlug || $failedSchema['key'] === $schemaSlug) {
								$rejection = $failedSchema['error'];
								break;
							}
						}

						$reason = 'This schema should have been created in the TWO-PASS schema import phase. ';
						if ($rejection !== null) {
							$reason = 'It was REJECTED by the schema import phase (' . $rejection . '). ';
						}

						$msg = 'Schema with slug %s not found in schemasMap during register import. ';
						$msg .= $reason;
						$msg .= 'This register will be created without this schema reference.';
						$this->logger->warning(
							message: '[ImportHandler] ' . sprintf($msg, $schemaSlug),
							context: ['file' => __FILE__, 'line' => __LINE__]
						);
					}//end foreach

					$registerData['schemas'] = $schemaIds;
				}//end if

				// Propagate parent-level x-openregister.type onto the register
				// so consuming apps can filter mock/demo data via
				// `GET /api/registers?filters[type]=mock`. Per-register
				// overrides on `$registerData['type']` take precedence so a
				// single configuration file can mix register types.
				$parentType = ($data['x-openregister']['type'] ?? null);
				if (isset($registerData['type']) === false && $parentType !== null && $parentType !== '') {
					$registerData['type'] = (string)$parentType;
				}

				// PER-ENTITY RESILIENCE: a single failing register MUST NOT abort
				// the rest of the data-layer import (sibling registers, mappings,
				// objects, seed data). Catch \Throwable (not just \Exception) so
				// opis/json-schema validation failures and folder-access denials
				// are also contained. Skip-and-continue with a descriptive warning,
				// mirroring the existing per-schema / per-mapping idiom.
				try {
					$register = $this->importRegister(
						data: $registerData,
						owner: $owner,
						appId: $appId,
						version: $version,
						force: $force
					);
					// Store register in map by slug for reference. The import call
					// above is declared non-nullable and throws on failure, which
					// the catch below handles.
					$this->registersMap[$slug] = $register;
					$result['registers'][] = $register;
				} catch (\Throwable $e) {
					$result['skipped']['registers']++;
					$this->logger->warning(
						message: "[ImportHandler] Skipping register '{$slug}' - import failed: " . $e->getMessage(),
						context: [
							'file' => __FILE__,
							'line' => __LINE__,
							'appId' => $appId,
							'registerSlug' => $slug,
							'error' => $e->getMessage(),
						]
					);
				}//end try
			}//end foreach
		}//end if

		// Process and import mappings if present.
		if (($data['components']['mappings'] ?? null) !== null
			&& is_array($data['components']['mappings']) === true
		) {
			$slugsAndIdsMap = $this->mappingMapper->getSlugToIdMap(includeNullOrg: true);

			$this->logger->debug(
				message: '[ImportHandler] Starting mapping import',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'totalMappings' => count($data['components']['mappings']),
					'mappingKeys' => array_keys($data['components']['mappings']),
				]
			);

			foreach ($data['components']['mappings'] as $key => $mappingData) {
				if (isset($mappingData['name']) === false && is_string($key) === true) {
					$mappingData['name'] = $key;
				}

				$mappingSlug = $mappingData['slug'] ?? $key;

				try {
					$mapping = $this->importMapping(
						data: $mappingData,
						slugsAndIdsMap: $slugsAndIdsMap,
						configuration: $configuration,
						version: $version,
						force: $force
					);

					if ($mapping !== null) {
						$result['mappings'][] = $mapping;
					}

					$mappingId = null;
					if ($mapping !== null) {
						$mappingId = $mapping->getId();
					}

					$this->logger->debug(
						message: '[ImportHandler] Mapping imported successfully',
						context: [
							'file' => __FILE__,
							'line' => __LINE__,
							'mappingSlug' => $mappingSlug,
							'mappingId' => $mappingId,
						]
					);
				} catch (Exception $e) {
					$this->logger->error(
						message: '[ImportHandler] Failed to import mapping',
						context: [
							'file' => __FILE__,
							'line' => __LINE__,
							'mappingKey' => $key,
							'error' => $e->getMessage(),
						]
					);
				}//end try
			}//end foreach

			$this->logger->debug(
				message: '[ImportHandler] Mapping import completed',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'importedCount' => count($result['mappings']),
				]
			);
		}//end if

		// Resolve `@ref:<slug>` seed-reference tokens to concrete target UUIDs
		// before the import loop. Seed objects reference siblings by slug; the
		// referenced schema properties are `format: uuid`, so the tokens must be
		// rewritten to the target object's UUID (and the targets given a stable
		// id) before validation runs inside saveObject().
		$data = $this->resolveSeedReferenceTokens(data: $data);

		// NOTE: We do NOT build ID maps - we'll pass the actual objects to avoid organisation filter issues.
		// When saveObject() receives Register/Schema objects, it skips the find() lookup entirely.
		// Resolve the acting user once for the object loop. On the occ/installer/cron
		// path there is no user session, so saveObject()'s folder access checks would
		// default-deny ("Access to folder NNNN is denied for the acting user").
		// resolveActingUser() returns the session user when present, otherwise a
		// fallback admin; null when none is resolvable (folder op then skips, not the
		// whole import).
		$actingUser = $this->resolveActingUser();
		// Process and import objects. Seed objects may be authored under
		// components.objects (the canonical export location) OR at the top-level
		// `objects` key (how some app register files place seed). Merge both,
		// de-duped by @self identity (uuid, else register/schema/slug), so either
		// authoring location seeds and re-import never duplicates.
		$seedObjects = [];
		$seedSeen = [];
		foreach ([($data['components']['objects'] ?? null), ($data['objects'] ?? null)] as $seedBucket) {
			if (is_array($seedBucket) === false) {
				continue;
			}

			foreach ($seedBucket as $seedCandidate) {
				if (is_array($seedCandidate) === false) {
					continue;
				}

				$seedSelf = ($seedCandidate['@self'] ?? []);
				$seedKey = (string)($seedSelf['uuid'] ?? '');
				if ($seedKey === '') {
					$seedKey = trim(($seedSelf['register'] ?? '') . '/' . ($seedSelf['schema'] ?? '') . '/' . ($seedSelf['slug'] ?? ''), '/');
				}

				if ($seedKey !== '' && isset($seedSeen[$seedKey]) === true) {
					continue;
				}

				if ($seedKey !== '') {
					$seedSeen[$seedKey] = true;
				}

				$seedObjects[] = $seedCandidate;
			}//end foreach
		}//end foreach

		if (count($seedObjects) > 0) {
			foreach ($seedObjects as $objectData) {
				// Log raw values before any mapping.
				$rawRegister = $objectData['@self']['register'] ?? null;
				$rawSchema = $objectData['@self']['schema'] ?? null;
				$rawSlug = $objectData['@self']['slug'] ?? null;

				// Only import objects with a slug.
				$slug = $rawSlug;
				if (empty($slug) === true) {
					continue;
				}

				// PER-ENTITY RESILIENCE: wrap the whole per-object resolve / search /
				// save sequence so one validation-failing object (e.g. a name-missing
				// or title-less seed fragment) is skipped with a warning instead of
				// aborting every sibling object and the rest of the app import.
				// Catch \Throwable so opis/json-schema validation throws and folder
				// access denials are contained too.
				try {
					// Get the actual Register and Schema objects from maps (not IDs!).
					// This is CRITICAL - passing objects avoids organisation filter in find().
					$registerObject = $this->registersMap[$rawRegister] ?? null;
					$schemaObject = $this->schemasMap[$rawSchema] ?? null;

					// Fallback for object-only bundles that reference pre-existing
					// registers/schemas (e.g. the rapportage templates that ship a
					// dashboard against the already-imported reports/dashboard).
					if ($registerObject === null && is_string($rawRegister) === true && $rawRegister !== '') {
						try {
							$registerObject = $this->registerMapper->find(
								$rawRegister,
								_rbac: false,
								_multitenancy: false
							);
							$this->registersMap[$rawRegister] = $registerObject;
						} catch (\Throwable $e) {
							$registerObject = null;
						}
					}

					if ($schemaObject === null && is_string($rawSchema) === true && $rawSchema !== '') {
						try {
							$schemaObject = $this->schemaMapper->find(
								$rawSchema,
								_rbac: false,
								_multitenancy: false
							);
							$this->schemasMap[$rawSchema] = $schemaObject;
						} catch (\Throwable $e) {
							$schemaObject = null;
						}
					}

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
						continue;
					}

					// Get IDs for searching existing objects.
					$registerId = $registerObject->getId();
					$schemaId = $schemaObject->getId();

					// Use ObjectService::searchObjects to find existing object by register+schema+slug.
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

					// Search for existing object.
					// Use _rbac: false and _multitenancy: false to ensure we find objects regardless of organisation context.
					// This prevents duplicate objects with the same UUID being created.
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
					$existingObject = null;
					if ((is_array($results) === true) && count($results) > 0) {
						$existingObject = $results[0];
					}

					if ($existingObject === null) {
						// Debug: fires once PER OBJECT during an import, and says
						// only that the normal path was taken. The import's
						// per-phase summaries are the info-level story.
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
					}

					// Replace string slugs with integer IDs in objectData's @self metadata.
					// This prevents any internal lookups from using string slugs.
					$objectData['@self']['register'] = (int)$registerId;
					$objectData['@self']['schema'] = (int)$schemaId;

					if ($existingObject !== null) {
						// Handle both ObjectEntity instances and array results from searchObjects.
						// searchObjects returns ObjectEntity or arrays depending on configuration.
						// @var ObjectEntity|array $existingObject.
						$existingObjectData = $existingObject->jsonSerialize();
						if (is_array($existingObject) === true) {
							$existingObjectData = $existingObject;
						}

						$importedVersion = $objectData['@self']['version'] ?? $objectData['version'] ?? '1.0.0';
						$existingVersion = $existingObjectData['@self']['version'] ?? $existingObjectData['version'] ?? '1.0.0';
						if (version_compare($importedVersion, $existingVersion, '>') > 0) {
							$uuid = $existingObjectData['@self']['id'] ?? $existingObjectData['id'] ?? null;
							// CRITICAL: Pass Register and Schema OBJECTS, not IDs.
							// This avoids organisation filter issues in find().
							// Installer-time seeding runs in the repair/CLI context
							// with no user session ('Anonymous'); bypass RBAC and
							// multitenancy here as everywhere else in this trusted
							// import path, otherwise seed objects on RBAC-guarded
							// schemas (e.g. Retainer Drawdown) abort the whole import.
							$object = $this->objectService->saveObject(
								object: $objectData,
								register: $registerObject,
								schema: $schemaObject,
								uuid: $uuid,
								_rbac: false,
								_multitenancy: false,
								currentUser: $actingUser
							);
							$result['objects'][] = $object;
						}

						if (version_compare($importedVersion, $existingVersion, '>') <= 0) {
							// COUNTED, not just logged. Skipping an unchanged
							// object is the DESIGNED behaviour of a re-import,
							// but a debug line is invisible to the caller, and
							// the caller is the one that has to tell an operator
							// whether the import worked.
							$result['unchanged']['objects']++;
							$this->logger->debug(
								message: '[ImportHandler] Skipped object update: imported version not higher',
								context: [
									'file' => __FILE__,
									'line' => __LINE__,
									'slug' => $slug,
									'register' => $registerId,
									'schema' => $schemaId,
									'importedVersion' => $importedVersion,
									'existingVersion' => $existingVersion,
								]
							);
							continue;
						}//end if
					}//end if

					if ($existingObject === null) {
						// Create new object.
						// CRITICAL: Pass Register and Schema OBJECTS, not IDs.
						// This avoids organisation filter issues in find().
						// Installer-time seeding runs without a user session
						// ('Anonymous'); bypass RBAC/multitenancy as in the rest of
						// this trusted import path so seed objects on RBAC-guarded
						// schemas don't abort the whole import.
						$object = $this->objectService->saveObject(
							object: $objectData,
							register: $registerObject,
							schema: $schemaObject,
							_rbac: false,
							_multitenancy: false,
							currentUser: $actingUser
						);
						$result['objects'][] = $object;
					}//end if
				} catch (\Throwable $e) {
					// PER-ENTITY RESILIENCE: skip this object, keep importing the rest.
					$result['skipped']['objects']++;
					$this->logger->warning(
						message: "[ImportHandler] Skipping object '{$slug}' - import failed: " . $e->getMessage(),
						context: [
							'file' => __FILE__,
							'line' => __LINE__,
							'appId' => $appId,
							'objectSlug' => $slug,
							'registerSlug' => $rawRegister,
							'schemaSlug' => $rawSchema,
							'error' => $e->getMessage(),
						]
					);
				}//end try
			}//end foreach
		}//end if

		// Process OpenConnector integration if available.
		if ($this->connectorConfigSvc !== null) {
			try {
				$openConnectorResult = $this->connectorConfigSvc->importConfiguration($data);
				$result = array_replace_recursive($openConnectorResult, $result);
			} catch (Exception $e) {
				$this->logger->warning(
					message: '[ImportHandler] OpenConnector integration failed: ' . $e->getMessage(),
					context: ['file' => __FILE__, 'line' => __LINE__]
				);
			}
		}

		// Create or update configuration entity to track imported data.
		// ONLY create/update if configuration was NOT provided by caller (e.g. importFromApp already created it).
		if ($configuration === null
			&& $appId !== null
			&& $version !== null
			&& (count($result['registers']) > 0
			|| count($result['schemas']) > 0
			|| count($result['objects']) > 0)
		) {
			$configuration = $this->createOrUpdateConfiguration(
				data: $data,
				appId: $appId,
				version: $version,
				result: $result,
				owner: $owner
			);
		}

		// Store the version information if appId and version are available.
		if ($appId !== null && $version !== null) {
			$this->appConfig->setValueString('openregister', "imported_config_{$appId}_version", $version);
			// Persist the content hash computed from the pre-mutation snapshot above so
			// the next run can fast-skip only when the definitional content is unchanged
			// (#426). Storing it even when the per-entity gates ended up updating nothing
			// is correct: it records "this exact config content has been seen".
			$this->appConfig->setValueString('openregister', "imported_config_{$appId}_hash", $definitionHash);
			// Info: an app's configuration genuinely changed version. The
			// far more common "unchanged, skipping" is debug — reporting the
			// NON-event at info is what made a repair unreadable.
			$this->logger->info(
				message: "[ImportHandler] Stored version {$version} for app {$appId} after successful import",
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}

		// Import seed data objects if present (only if configuration was created/updated).
		if ($configuration === null) {
			$this->logger->debug(
				message: '[ImportHandler] Skipping seedData import - no configuration entity available',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return $result;
		}

		// PER-ENTITY RESILIENCE: seed-data import runs AFTER registers/schemas/objects
		// are already persisted. A throw inside it must never unwind that completed
		// work, so wrap the whole call. Per-schema and per-object resilience also
		// live inside importSeedData().
		try {
			$this->importSeedData(
				configData: $data,
				owner: $owner,
				appId: $appId,
				configuration: $configuration,
				result: $result
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[ImportHandler] Seed-data import failed - registers/schemas/objects kept: ' . $e->getMessage(),
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'appId' => $appId,
					'error' => $e->getMessage(),
				]
			);
		}//end try

		return $result;
	}//end importFromJson()

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
	 *
	 * @return array The data with target `@self.id` populated and `@ref:` tokens resolved.
	 */
	private function resolveSeedReferenceTokens(array $data): array {
		if (($data['components']['objects'] ?? null) === null
			|| is_array($data['components']['objects']) === false
		) {
			return $data;
		}

		// Collect the slugs that are actually referenced, so we only resolve
		// identities for genuine reference targets (and skip the work entirely
		// when no object references another).
		$referencedSlugs = [];
		$refSchemaSlugs = [];
		foreach ($data['components']['objects'] as $objectData) {
			if (is_array($objectData) === true) {
				$this->collectRefTargets(
					value: $objectData,
					bareSlugs: $referencedSlugs,
					schemaSlugs: $refSchemaSlugs
				);
			}
		}

		if (count($referencedSlugs) === 0 && count($refSchemaSlugs) === 0) {
			return $data;
		}

		// PASS 1 — assign a stable UUID to each referenced target and index it.
		$uuidBySchemaSlug = [];
		$uuidBySlug = [];
		$ambiguousSlugs = [];

		foreach ($data['components']['objects'] as &$targetData) {
			if (is_array($targetData) === false) {
				continue;
			}

			$objectSlug = $targetData['@self']['slug'] ?? null;
			if (empty($objectSlug) === true) {
				continue;
			}

			$schemaSlug = $targetData['@self']['schema'] ?? null;
			$schemaSlugKey = null;
			if (is_string($schemaSlug) === true && $schemaSlug !== '') {
				$schemaSlugKey = $schemaSlug . ':' . $objectSlug;
			}

			// Only objects that something references need a pre-resolved identity.
			$isReferenced = (array_key_exists($objectSlug, $referencedSlugs) === true
				|| ($schemaSlugKey !== null && array_key_exists($schemaSlugKey, $refSchemaSlugs) === true));
			if ($isReferenced === false) {
				continue;
			}

			// A target whose register/schema cannot be resolved will be skipped
			// by the import loop and never persisted; pre-assigning it an id
			// would leave referrers pointing at a dangling, never-stored UUID.
			// Leave it unmapped so replaceRefTokens logs the unresolved reference
			// instead of silently fabricating one.
			[$registerObject, $schemaObject] = $this->resolveImportRegisterSchema(objectData: $targetData);
			if ($registerObject === null || $schemaObject === null) {
				continue;
			}

			// Prefer the UUID already persisted for this register+schema+slug so
			// re-imports stay idempotent and resolved references always match the
			// id the import loop will keep on update; fall back to an explicit
			// seed id (first import), then a freshly minted one.
			$uuid = $this->findExistingSeedUuid(
				register: $registerObject,
				schema: $schemaObject,
				slug: $objectSlug
			);
			if (empty($uuid) === true) {
				$uuid = $targetData['@self']['id'] ?? null;
			}

			if (empty($uuid) === true) {
				$uuid = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
			}

			$targetData['@self']['id'] = $uuid;

			if ($schemaSlugKey !== null) {
				$uuidBySchemaSlug[$schemaSlugKey] = $uuid;
			}

			$isDuplicateSlug = (array_key_exists($objectSlug, $uuidBySlug) === true
				&& $uuidBySlug[$objectSlug] !== $uuid);
			if ($isDuplicateSlug === true) {
				$ambiguousSlugs[$objectSlug] = true;
			}

			if ($isDuplicateSlug === false) {
				$uuidBySlug[$objectSlug] = $uuid;
			}
		}//end foreach

		unset($targetData);

		// PASS 2 — replace @ref: tokens in every object's property values.
		foreach ($data['components']['objects'] as &$objectData) {
			if (is_array($objectData) === false) {
				continue;
			}

			$self = $objectData['@self'] ?? null;
			unset($objectData['@self']);

			$objectData = $this->replaceRefTokens(
				value: $objectData,
				uuidBySchemaSlug: $uuidBySchemaSlug,
				uuidBySlug: $uuidBySlug,
				ambiguousSlugs: $ambiguousSlugs
			);

			if ($self !== null) {
				$objectData['@self'] = $self;
			}
		}//end foreach

		unset($objectData);

		return $data;
	}//end resolveSeedReferenceTokens()

	/**
	 * Recursively collect the slugs referenced by `@ref:` tokens in a value.
	 *
	 * @param mixed $value The property value to scan.
	 * @param array $bareSlugs Accumulator of "objectSlug" => true (by reference).
	 * @param array $schemaSlugs Accumulator of "schemaSlug:objectSlug" => true (by reference).
	 *
	 * @return void
	 */
	private function collectRefTargets(mixed $value, array &$bareSlugs, array &$schemaSlugs): void {
		if (is_array($value) === true) {
			foreach ($value as $item) {
				$this->collectRefTargets(value: $item, bareSlugs: $bareSlugs, schemaSlugs: $schemaSlugs);
			}

			return;
		}

		if (is_string($value) === false || str_starts_with($value, '@ref:') === false) {
			return;
		}

		$reference = substr($value, strlen('@ref:'));
		if (str_contains($reference, ':') === true) {
			$schemaSlugs[$reference] = true;
			return;
		}

		$bareSlugs[$reference] = true;
	}//end collectRefTargets()

	/**
	 * Recursively replace `@ref:` tokens in a value with resolved target UUIDs.
	 *
	 * A token is matched only when it spans the whole string value, in one of:
	 *  - `@ref:<slug>`               — resolved by slug (must be unambiguous).
	 *  - `@ref:<schema-slug>:<slug>` — resolved by schema + slug (explicit).
	 *
	 * @param mixed $value The property value (scalar, list, or map).
	 * @param array $uuidBySchemaSlug Map of "schemaSlug:objectSlug" => uuid.
	 * @param array $uuidBySlug Map of "objectSlug" => uuid.
	 * @param array $ambiguousSlugs Set of slugs that map to multiple objects.
	 *
	 * @return mixed The value with any `@ref:` tokens replaced.
	 */
	private function replaceRefTokens(
		mixed $value,
		array $uuidBySchemaSlug,
		array $uuidBySlug,
		array $ambiguousSlugs,
	): mixed {
		if (is_array($value) === true) {
			foreach ($value as $key => $item) {
				$value[$key] = $this->replaceRefTokens(
					value: $item,
					uuidBySchemaSlug: $uuidBySchemaSlug,
					uuidBySlug: $uuidBySlug,
					ambiguousSlugs: $ambiguousSlugs
				);
			}

			return $value;
		}

		if (is_string($value) === false || str_starts_with($value, '@ref:') === false) {
			return $value;
		}

		$reference = substr($value, strlen('@ref:'));
		$schemaSlug = null;
		$objectSlug = $reference;
		if (str_contains($reference, ':') === true) {
			[$schemaSlug, $objectSlug] = explode(':', $reference, 2);
		}

		if ($schemaSlug !== null && $schemaSlug !== '') {
			if (array_key_exists($schemaSlug . ':' . $objectSlug, $uuidBySchemaSlug) === true) {
				return $uuidBySchemaSlug[$schemaSlug . ':' . $objectSlug];
			}

			$this->logger->warning(
				message: '[ImportHandler] Unresolved seed reference "' . $value . '" — no imported object for that schema+slug.',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);

			return $value;
		}

		if (array_key_exists($objectSlug, $ambiguousSlugs) === true) {
			$this->logger->warning(
				message: '[ImportHandler] Ambiguous seed reference "' . $value . '"; slug exists in '
					. 'multiple schemas — use @ref:<schema>:<slug>. Left unresolved.',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);

			return $value;
		}

		if (array_key_exists($objectSlug, $uuidBySlug) === true) {
			return $uuidBySlug[$objectSlug];
		}

		$this->logger->warning(
			message: '[ImportHandler] Unresolved seed reference "' . $value . '" — no imported object with that slug.',
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		return $value;
	}//end replaceRefTokens()

	/**
	 * Resolve a seed object's `@self` register + schema slugs to their entities,
	 * using the in-flight import maps first and falling back to a direct mapper
	 * lookup (RBAC/multitenancy bypassed, as everywhere else in this trusted
	 * import path). Returns nulls when either cannot be resolved.
	 *
	 * @param array $objectData The seed object (with @self register/schema).
	 *
	 * @return array{0: ?Register, 1: ?Schema} The resolved register and schema.
	 */
	private function resolveImportRegisterSchema(array $objectData): array {
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

		return [$registerObject, $schemaObject];
	}//end resolveImportRegisterSchema()

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
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Force flag to override version checks
	 * @SuppressWarnings(PHPMD.NPathComplexity)       App import requires many conditional transformations
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Configuration lookup and metadata mapping has many branches
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) App import with entity tracking requires detailed logic
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function importFromApp(string $appId, array $data, string $version, bool $force = false): array {
		try {
			// Ensure data is consistently an array by converting any stdClass objects.
			$data = $this->ensureArrayStructure(data: $data);

			// Try to find existing configuration for this app.
			// First check by sourceUrl (unique identifier), then by appId.
			$configuration = null;
			$xOpenregister = $data['x-openregister'] ?? [];
			$sourceUrl = $xOpenregister['sourceUrl'] ?? null;

			// If sourceUrl is provided, try to find by sourceUrl first (ensures uniqueness).
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
					}
				} catch (Exception $e) {
					// No configuration found by sourceUrl.
				}
			}

			// If not found by sourceUrl, try by appId.
			if ($configuration === null) {
				try {
					$configurations = $this->configurationMapper->findByApp($appId, systemLookup: true);
					if (count($configurations) > 0) {
						// Use the first (most recent) configuration.
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

						// Check version and decide if we should update or skip.
						$existingVersion = $configuration->getVersion() ?? '0.0.0';
						$newVersion = $version ?? '0.0.0';

						// Only skip if not forced AND version is not newer.
						// Note: We deliberately do NOT early-return here, even when skipping,
						// because we still want to check for seedData changes.
						// The importFromJson method will handle version checks for schemas/registers.
						if ($force === false && version_compare($newVersion, $existingVersion, '<=') === true) {
							$msg = "Config version ({$existingVersion}) up-to-date, checking seedData";
							$this->logger->debug(
								message: '[ImportHandler] ' . $msg,
								context: ['file' => __FILE__, 'line' => __LINE__, 'app' => $appId, 'force' => $force]
							);
							// Continue to importFromJson, which will skip schemas/registers but may import seedData.
						}
					}//end if
				} catch (Exception $e) {
					// No existing configuration found, we'll create a new one.
					$this->logger->debug(
						message: "[ImportHandler] No existing configuration found for app {$appId}, will create new one",
						context: ['file' => __FILE__, 'line' => __LINE__]
					);
				}//end try
			}//end if

			// Create new configuration if none exists.
			if ($configuration === null) {
				$configuration = new Configuration();

				// Extract metadata following OAS standard first, then x-openregister extension.
				$info = $data['info'] ?? [];
				$xOpenregister = $data['x-openregister'] ?? [];

				// Standard OAS properties from info section.
				$defaultTitle = "Configuration for {$appId}";
				$defaultDesc = "Configuration imported by application {$appId}";
				$title = $info['title'] ?? $xOpenregister['title'] ?? $data['title'] ?? $defaultTitle;
				$desc = $info['description'] ?? $xOpenregister['description'] ?? $data['description'];
				$description = $desc ?? $defaultDesc;

				// OpenRegister-specific properties.
				$type = $xOpenregister['type'] ?? $data['type'] ?? 'app';

				$configuration->setTitle($title);
				$configuration->setDescription($description);
				$configuration->setType($type);
				$configuration->setApp($appId);
				$configuration->setVersion($version);

				// Mark as local configuration (maintained by the app).
				$configuration->setIsLocal(true);
				$configuration->setSyncEnabled(false);
				$configuration->setSyncStatus('never');

				// Set version requirements from x-openregister if available.
				if (($xOpenregister['openregister'] ?? null) !== null) {
					$configuration->setOpenregister($xOpenregister['openregister']);
				}

				// Set additional metadata from x-openregister if available.
				// Note: Internal properties (autoUpdate, notificationGroups, owner, organisation).
				// Are not imported as they are instance-specific settings.
				if (($xOpenregister['sourceType'] ?? null) !== null) {
					$configuration->setSourceType($xOpenregister['sourceType']);
				}

				if (($xOpenregister['sourceUrl'] ?? null) !== null) {
					$configuration->setSourceUrl($xOpenregister['sourceUrl']);
				}

				// Support both nested github structure (new) and flat structure (backward compatibility).
				if (($xOpenregister['github'] ?? null) !== null && is_array($xOpenregister['github']) === true) {
					// New nested structure.
					if (($xOpenregister['github']['repo'] ?? null) !== null) {
						$configuration->setGithubRepo($xOpenregister['github']['repo']);
					}

					if (($xOpenregister['github']['branch'] ?? null) !== null) {
						$configuration->setGithubBranch($xOpenregister['github']['branch']);
					}

					if (($xOpenregister['github']['path'] ?? null) !== null) {
						$configuration->setGithubPath($xOpenregister['github']['path']);
					}
				}

				if (($xOpenregister['github'] ?? null) === null || is_array($xOpenregister['github']) === false) {
					// Legacy flat structure (backward compatibility).
					if (($xOpenregister['githubRepo'] ?? null) !== null) {
						$configuration->setGithubRepo($xOpenregister['githubRepo']);
					}

					if (($xOpenregister['githubBranch'] ?? null) !== null) {
						$configuration->setGithubBranch($xOpenregister['githubBranch']);
					}

					if (($xOpenregister['githubPath'] ?? null) !== null) {
						$configuration->setGithubPath($xOpenregister['githubPath']);
					}
				}//end if

				$configuration->setRegisters([]);
				$configuration->setSchemas([]);
				$configuration->setObjects([]);

				// Insert the configuration to get an ID.
				$configuration = $this->configurationMapper->insert($configuration);

				$this->logger->debug(
					message: "[ImportHandler] Created new configuration for app {$appId}",
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'configurationId' => $configuration->getId(),
						'version' => $version,
					]
				);
			}//end if

			// Perform the import using the configuration entity.
			$result = $this->importFromJson(
				data: $data,
				configuration: $configuration,
				owner: $appId,
				appId: $appId,
				version: $version,
				force: $force
			);

			// Update the configuration with the import results.
			if (count($result['registers']) > 0 || count($result['schemas']) > 0 || count($result['objects']) > 0) {
				// Merge imported entity IDs with existing ones.
				$existingRegisterIds = $configuration->getRegisters() ?? [];
				$existingSchemaIds = $configuration->getSchemas() ?? [];
				$existingObjectIds = $configuration->getObjects() ?? [];

				foreach ($result['registers'] as $register) {
					$isRegister = $register instanceof Register;
					$alreadyExists = in_array($register->getId(), $existingRegisterIds, true);
					if ($isRegister === true && $alreadyExists === false) {
						$existingRegisterIds[] = $register->getId();
					}
				}

				foreach ($result['schemas'] as $schema) {
					if ($schema instanceof Schema && in_array($schema->getId(), $existingSchemaIds, true) === false) {
						$existingSchemaIds[] = $schema->getId();
					}
				}

				foreach ($result['objects'] as $object) {
					if ($object instanceof ObjectEntity && in_array($object->getId(), $existingObjectIds, true) === false) {
						$existingObjectIds[] = $object->getId();
					}
				}

				$configuration->setRegisters($existingRegisterIds);
				$configuration->setSchemas($existingSchemaIds);
				$configuration->setObjects($existingObjectIds);
				$configuration->setVersion($version);

				// Update metadata following OAS standard first, then x-openregister extension.
				// This ensures sourceUrl and other tracking info stays current.
				$info = $data['info'] ?? [];
				$xOpenregister = $data['x-openregister'] ?? [];

				// Standard OAS properties from info section.
				if (($info['title'] ?? null) !== null) {
					$configuration->setTitle($info['title']);
				} elseif (($xOpenregister['title'] ?? null) !== null) {
					$configuration->setTitle($xOpenregister['title']);
				}

				if (($info['description'] ?? null) !== null) {
					$configuration->setDescription($info['description']);
				} elseif (($xOpenregister['description'] ?? null) !== null) {
					$configuration->setDescription($xOpenregister['description']);
				}

				// OpenRegister-specific properties from x-openregister.
				if (($xOpenregister['sourceType'] ?? null) !== null) {
					$configuration->setSourceType($xOpenregister['sourceType']);
				}

				if (($xOpenregister['sourceUrl'] ?? null) !== null) {
					$configuration->setSourceUrl($xOpenregister['sourceUrl']);
				}

				// Update github properties (nested or flat).
				if (($xOpenregister['github'] ?? null) !== null && is_array($xOpenregister['github']) === true) {
					if (($xOpenregister['github']['repo'] ?? null) !== null) {
						$configuration->setGithubRepo($xOpenregister['github']['repo']);
					}

					if (($xOpenregister['github']['branch'] ?? null) !== null) {
						$configuration->setGithubBranch($xOpenregister['github']['branch']);
					}

					if (($xOpenregister['github']['path'] ?? null) !== null) {
						$configuration->setGithubPath($xOpenregister['github']['path']);
					}
				}

				if (($xOpenregister['github'] ?? null) === null || is_array($xOpenregister['github']) === false) {
					// Legacy flat structure.
					if (($xOpenregister['githubRepo'] ?? null) !== null) {
						$configuration->setGithubRepo($xOpenregister['githubRepo']);
					}

					if (($xOpenregister['githubBranch'] ?? null) !== null) {
						$configuration->setGithubBranch($xOpenregister['githubBranch']);
					}

					if (($xOpenregister['githubPath'] ?? null) !== null) {
						$configuration->setGithubPath($xOpenregister['githubPath']);
					}
				}//end if

				$this->configurationMapper->update($configuration);

				$this->logger->debug(
					message: "[ImportHandler] Updated configuration entity for app {$appId}",
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'configurationId' => $configuration->getId(),
						'totalRegisters' => count($existingRegisterIds ?? []),
						'totalSchemas' => count($existingSchemaIds ?? []),
						'totalObjects' => count($existingObjectIds ?? []),
					]
				);
			}//end if

			// **AUTO-REGISTER CREATION (runtime-schema-api / data-import-export spec)**:
			// When a runtime caller (e.g. OpenBuild's schema editor) imports an OAS
			// marked as `x-openregister.type=application`, the installer-time `repair`
			// step that normally provisions a Register row does NOT run. Without this
			// step, the smoke-test foot-gun reappears: schemas exist but no Register
			// wraps them, so slug-aware searches return zero. This block closes that gap.
			$this->autoCreateRegisterIfApplication(
				data: $data,
				appId: $appId,
				schemas: $result['schemas'],
				configuration: $configuration,
				result: $result
			);

			// MAGIC-TABLE COLUMN SYNC (fixes #2082): reconcile the physical
			// table of EVERY imported schema, in every register that holds it.
			//
			// The two pre-existing ensureTableForRegisterSchema() call sites
			// only cover (a) registers that were just auto-created and (b)
			// schemas that ship seed objects. An EXISTING schema in an
			// EXISTING register with no seed data — by far the common case for
			// an app shipping a `register.d` fragment that adds a property —
			// was never reconciled, so the new column was simply absent until
			// something happened to write an object of that type.
			//
			// Observed 2026-07-24 on softwarecatalog: `beoordeeling` gained a
			// `status` property and `gebruik` gained TIME fields; neither
			// column existed afterwards, not even after a FORCED re-import.
			// Because the schema's own RBAC read rule matched on `status`, the
			// generated SQL referenced a non-existent column and EVERY read —
			// including the anonymous public path — returned HTTP 500.
			$this->ensureMagicTablesForImportedSchemas(schemas: $result['schemas'] ?? []);

			return $result;
		} catch (Exception $e) {
			$this->logger->error(
				message: "[ImportHandler] Failed to import configuration for app {$appId}: " . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			throw new Exception("Failed to import configuration for app {$appId}: " . $e->getMessage());
		}//end try
	}//end importFromApp()

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
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multi-branch auto-create / reconcile logic.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Idempotent insert-or-update with multiple optional fields.
	 */
	private function autoCreateRegisterIfApplication(
		array $data,
		string $appId,
		array $schemas,
		Configuration $configuration,
		array &$result,
	): void {
		$xOpenregister = $data['x-openregister'] ?? [];
		$type = $xOpenregister['type'] ?? null;

		// Only trigger on `application`-typed configurations.
		if ($type !== 'application') {
			return;
		}

		// Derive register attributes from the OAS document.
		$info = $data['info'] ?? [];
		$slug = $xOpenregister['app'] ?? $appId;
		if (is_string($slug) === false || $slug === '') {
			$this->logger->warning(
				message: '[ImportHandler] Skipping auto-Register: x-openregister.app missing or empty',
				context: ['file' => __FILE__, 'line' => __LINE__, 'appId' => $appId]
			);
			return;
		}

		$title = $info['title'] ?? $xOpenregister['title'] ?? $appId;
		$description = $info['description'] ?? $xOpenregister['description'] ?? null;

		// Collect numeric schema IDs from the import result.
		$newSchemaIds = [];
		foreach ($schemas as $schema) {
			if ($schema instanceof Schema && $schema->getId() !== null) {
				$newSchemaIds[] = (int)$schema->getId();
			}
		}

		// Idempotent lookup on (slug, organisationId). The find cache on
		// RegisterMapper already keys by slug, so this is cheap.
		// Use findAll with filters so we can scope to organisation explicitly
		// without relying on session-derived multi-tenancy (the import path
		// may run under a system context where the active org is null).
		$existingRegisters = $this->registerMapper->findAll(
			limit: 1,
			offset: 0,
			filters: ['slug' => $slug],
			_rbac: false,
			_multitenancy: true
		);

		$register = null;
		if (count($existingRegisters) > 0) {
			$register = $existingRegisters[0];

			// Reconcile: refresh title/description and union schema IDs.
			$register->setTitle($title);
			if ($description !== null) {
				$register->setDescription($description);
			}

			// Reconcile schema references. Union the freshly-imported (app-owned)
			// ids in, but first PRUNE any currently-listed id that is now
			// SHADOWED by a newly-imported schema sharing the same slug. This
			// self-heals a register that — before the cross-app slug-collision
			// fix — had a FOREIGN app's same-slug schema bound into it (e.g.
			// pipelinq's 'conversation' #701 living in hermiq's register). Once
			// the app imports its OWN 'conversation', the foreign shadow is
			// dropped from THIS app's register so a slug resolves unambiguously
			// to the app's own schema.
			$currentSchemaIds = $register->getSchemas() ?? [];

			// Map lower(slug) -> app-owned id for everything we just imported.
			$newSlugToId = [];
			foreach ($schemas as $schema) {
				if ($schema instanceof Schema
					&& $schema->getId() !== null
					&& $schema->getSlug() !== null
				) {
					$newSlugToId[strtolower($schema->getSlug())] = (int)$schema->getId();
				}
			}

			// Drop any currently-listed id whose slug matches a freshly-imported
			// schema but whose id differs (the shadowed, foreign/stale one).
			$prunedSchemaIds = [];
			foreach ($currentSchemaIds as $currentId) {
				$currentId = (int)$currentId;
				$keep = true;
				try {
					$existingSlug = $this->schemaMapper->find($currentId, _multitenancy: false)->getSlug();
					if ($existingSlug !== null) {
						$slugKey = strtolower($existingSlug);
						if (isset($newSlugToId[$slugKey]) === true && $newSlugToId[$slugKey] !== $currentId) {
							$keep = false;
							$this->logger->debug(
								message: sprintf(
									"[ImportHandler] Auto-Register '%s': pruning shadowed schema id %d (slug '%s') in favour of app-owned id %d",
									$slug,
									$currentId,
									$existingSlug,
									$newSlugToId[$slugKey]
								),
								context: ['file' => __FILE__, 'line' => __LINE__]
							);
						}
					}
				} catch (\Throwable $ignore) {
					// Missing / undecodable schema id — keep it as-is; nothing to compare against.
				}//end try

				if ($keep === true) {
					$prunedSchemaIds[] = $currentId;
				}
			}//end foreach

			// Union the freshly-imported app-owned ids into the pruned list.
			$unionSchemaIds = $prunedSchemaIds;
			foreach ($newSchemaIds as $newId) {
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
					'slug' => $slug,
					'unionSchemaIds' => $unionSchemaIds,
				]
			);
		}//end if

		if ($register === null) {
			// Fresh insert: derive a new Register entity.
			$register = $this->registerMapper->createFromArray(
				object: [
					'title' => $title,
					'description' => $description ?? '',
					'slug' => $slug,
					'schemas' => $newSchemaIds,
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
					'slug' => $slug,
					'schemaIds' => $newSchemaIds,
				]
			);
		}//end if

		// Surface the auto-created register in the import result so callers
		// see a complete (Configuration, Schemas, Register) triple.
		$resultRegisters = $result['registers'] ?? [];
		$alreadyPresent = false;
		foreach ($resultRegisters as $existing) {
			if ($existing instanceof Register && $existing->getId() === $register->getId()) {
				$alreadyPresent = true;
				break;
			}
		}

		if ($alreadyPresent === false) {
			$resultRegisters[] = $register;
			$result['registers'] = $resultRegisters;
		}

		// Keep the Configuration entity's registers[] field in sync so the
		// triple stays consistent and a follow-up `_extend=registers` GET
		// serializes the new ID without an extra round-trip.
		$configRegisterIds = $configuration->getRegisters() ?? [];
		if (in_array($register->getId(), $configRegisterIds, true) === false) {
			$configRegisterIds[] = $register->getId();
			$configuration->setRegisters($configRegisterIds);
			$this->configurationMapper->update($configuration);
		}

		// EAGER MAGIC-TABLE CREATION (fixes #1615): provision the per-schema
		// magic table for EVERY imported schema, not just those that ship
		// seed data. Without this, log-style schemas (call_log, job_log,
		// synchronization_log, …) — which typically have no seed data —
		// never get their `oc_openregister_table_{registerId}_{schemaId}`
		// table created on `occ app:enable`, and the first runtime write
		// from a service like CallService throws "relation does not exist".
		//
		// Idempotent on re-import: ensureTableForRegisterSchema short-
		// circuits when the table already exists (see MagicTableHandler
		// line 109-112). The seed-objects loop further down still calls
		// the same method as a defensive no-op.
		if ($this->magicMapper !== null) {
			foreach ($schemas as $schema) {
				if ($schema instanceof Schema === false) {
					continue;
				}

				try {
					$this->magicMapper->ensureTableForRegisterSchema(
						register: $register,
						schema: $schema
					);
				} catch (\Exception $e) {
					// Non-fatal: surfaced via logger so the rest of the
					// import (other schemas, seed data) still completes.
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
		}//end if
	}//end autoCreateRegisterIfApplication()

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
	 * Create or update a Configuration entity to track imports.
	 *
	 * @param array $data The original import data.
	 * @param string $appId The application ID.
	 * @param string $version The version of the import.
	 * @param array $result The import result containing created entities.
	 * @param string|null $owner The owner of the configuration.
	 *
	 * @return Configuration The created or updated configuration.
	 *
	 * @throws Exception If configuration creation/update fails.
	 *
	 * @SuppressWarnings(PHPMD.NPathComplexity)       Configuration creation requires many conditional checks
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Entity ID collection and metadata mapping has many branches
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Configuration tracking involves detailed entity management
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function createOrUpdateConfiguration(
		array $data,
		string $appId,
		string $version,
		array $result,
		?string $owner = null,
	): Configuration {
		try {
			// Ensure data is consistently an array by converting any stdClass objects.
			$data = $this->ensureArrayStructure(data: $data);

			// Try to find existing configuration for this app.
			$existingConfig = null;
			try {
				$configurations = $this->configurationMapper->findByApp($appId, systemLookup: true);
				if (count($configurations) > 0) {
					$existingConfig = $configurations[0];
				}
			} catch (Exception $e) {
				// No existing configuration found, we'll create a new one.
			}

			// Extract metadata following OAS standard first, then x-openregister extension.
			$info = $data['info'] ?? [];
			$xOpenregister = $data['x-openregister'] ?? [];

			// Standard OAS properties from info section.
			$defaultTitle = "Configuration for {$appId}";
			$defaultDesc = "Imported configuration for application {$appId}";
			$title = $info['title'] ?? $xOpenregister['title'] ?? $data['title'] ?? $defaultTitle;
			$description = $info['description'] ?? $xOpenregister['description'] ?? $data['description'] ?? $defaultDesc;

			// OpenRegister-specific properties.
			$type = $xOpenregister['type'] ?? $data['type'] ?? 'imported';

			// Collect IDs of imported entities.
			$registerIds = [];
			foreach ($result['registers'] as $register) {
				if ($register instanceof Register) {
					$registerIds[] = $register->getId();
				}
			}

			$schemaIds = [];
			foreach ($result['schemas'] as $schema) {
				if ($schema instanceof Schema) {
					$schemaIds[] = $schema->getId();
				}
			}

			$objectIds = [];
			foreach ($result['objects'] as $object) {
				if ($object instanceof ObjectEntity) {
					$objectIds[] = $object->getId();
				}
			}

			if ($existingConfig !== null) {
				// Update existing configuration.
				$existingConfig->setTitle($title);
				$existingConfig->setDescription($description);
				$existingConfig->setType($type);
				$existingConfig->setVersion($version);

				// Merge with existing IDs to avoid losing previously imported entities.
				$existingRegisterIds = $existingConfig->getRegisters() ?? [];
				$existingSchemaIds = $existingConfig->getSchemas() ?? [];
				$existingObjectIds = $existingConfig->getObjects() ?? [];

				$existingConfig->setRegisters(array_unique(array_merge($existingRegisterIds, $registerIds)));
				$existingConfig->setSchemas(array_unique(array_merge($existingSchemaIds, $schemaIds)));
				$existingConfig->setObjects(array_unique(array_merge($existingObjectIds, $objectIds)));

				$configuration = $this->configurationMapper->update($existingConfig);
				$this->logger->debug(
					message: "[ImportHandler] Updated existing configuration for app {$appId} with version {$version}",
					context: ['file' => __FILE__, 'line' => __LINE__]
				);
			}//end if

			if ($existingConfig === null) {
				// Create new configuration.
				$configuration = new Configuration();
				$configuration->setTitle($title);
				$configuration->setDescription($description);
				$configuration->setType($type);
				$configuration->setApp($appId);
				$configuration->setVersion($version);
				$configuration->setRegisters($registerIds);
				$configuration->setSchemas($schemaIds);
				$configuration->setObjects($objectIds);

				// Mark as local configuration (maintained by the app).
				$configuration->setIsLocal(true);
				$configuration->setSyncEnabled(false);
				$configuration->setSyncStatus('never');

				// Set version requirements from x-openregister if available.
				if (($xOpenregister['openregister'] ?? null) !== null) {
					$configuration->setOpenregister($xOpenregister['openregister']);
				}

				// Set additional metadata from x-openregister if available.
				if (($xOpenregister['sourceType'] ?? null) !== null) {
					$configuration->setSourceType($xOpenregister['sourceType']);
				}

				if (($xOpenregister['sourceUrl'] ?? null) !== null) {
					$configuration->setSourceUrl($xOpenregister['sourceUrl']);
				}

				// Support both nested github structure (new) and flat structure (backward compatibility).
				if (($xOpenregister['github'] ?? null) !== null && is_array($xOpenregister['github']) === true) {
					// New nested structure.
					if (($xOpenregister['github']['repo'] ?? null) !== null) {
						$configuration->setGithubRepo($xOpenregister['github']['repo']);
					}

					if (($xOpenregister['github']['branch'] ?? null) !== null) {
						$configuration->setGithubBranch($xOpenregister['github']['branch']);
					}

					if (($xOpenregister['github']['path'] ?? null) !== null) {
						$configuration->setGithubPath($xOpenregister['github']['path']);
					}
				}

				if (($xOpenregister['github'] ?? null) === null || is_array($xOpenregister['github']) === false) {
					// Legacy flat structure (backward compatibility).
					if (($xOpenregister['githubRepo'] ?? null) !== null) {
						$configuration->setGithubRepo($xOpenregister['githubRepo']);
					}

					if (($xOpenregister['githubBranch'] ?? null) !== null) {
						$configuration->setGithubBranch($xOpenregister['githubBranch']);
					}

					if (($xOpenregister['githubPath'] ?? null) !== null) {
						$configuration->setGithubPath($xOpenregister['githubPath']);
					}
				}//end if

				// Set owner from parameter if provided (for backward compatibility).
				if ($owner !== null) {
					$configuration->setOwner($owner);
				}

				$configuration = $this->configurationMapper->insert($configuration);
				$this->logger->debug(
					message: "[ImportHandler] Created new configuration for app {$appId} with version {$version}",
					context: ['file' => __FILE__, 'line' => __LINE__]
				);
			}//end if

			return $configuration;
		} catch (Exception $e) {
			$this->logger->error(
				message: "[ImportHandler] Failed to create or update configuration for app {$appId}: " . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			throw new Exception('Failed to create or update configuration: ' . $e->getMessage());
		}//end try
	}//end createOrUpdateConfiguration()

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
			function () use ($configData, $owner, $appId, $configuration, &$result): void {
				$this->importSeedDataObjects(
					configData: $configData,
					owner: $owner,
					appId: $appId,
					configuration: $configuration,
					result: $result
				);
			}
		);
	}//end importSeedData()

	/**
	 * Import the seed-data objects themselves.
	 *
	 * Split out of {@see importSeedData()} so the whole pass runs inside one
	 * SystemOperationContext without indenting several hundred lines.
	 *
	 * @param array $configData The configuration payload.
	 * @param string|null $owner The owner to attribute seeded objects to.
	 * @param string|null $appId The owning app id.
	 * @param Configuration $configuration The configuration entity.
	 * @param array $result Accumulated import result, by reference.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function importSeedDataObjects(
		array $configData,
		?string $owner,
		?string $appId,
		Configuration $configuration,
		array &$result,
	): void {
		$seedData = $configData['x-openregister']['seedData'] ?? null;

		// Tasks + notes both require a logged-in actor (CalDAV calendar
		// lookup, comment authorship). Capture this once at the top of
		// the import — at occ install time there's no user session, so
		// those item types skip with a warning. Files are tied to the
		// object's folder, not the actor, so they always run.
		$hasUserContext = ($this->userSession !== null && $this->userSession->getUser() !== null);
		$this->logger->debug(
			message: '[ImportHandler] Seed data import context',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'has_user_context' => $hasUserContext,
			]
		);

		$result['relatedFiles'] = ($result['relatedFiles'] ?? 0);
		$result['relatedNotes'] = ($result['relatedNotes'] ?? 0);
		$result['relatedTasks'] = ($result['relatedTasks'] ?? 0);

		// Ensure the skipped-entity observability map exists even when this
		// method is invoked with a result array that predates it.
		$result['skipped'] = ($result['skipped'] ?? []);
		$result['skipped']['seedObjects'] = ($result['skipped']['seedObjects'] ?? 0);

		// Determine target register for seedData objects.
		// Prefer the registers imported IN THIS RUN ($this->registersMap): on a
		// FIRST import the configuration entity's registers list is only
		// persisted after importFromJson returns, so reading it here yielded
		// "register 0" and every seed object failed with "Cannot insert object
		// without register and schema context" (verified live with the DSAR
		// policy-pack register). Fall back to the configuration's recorded
		// registers for the version-equal "checking seedData" re-import path,
		// where the map is empty but the configuration is populated.
		$targetRegister = null;
		$freshRegisters = array_values($this->registersMap);
		if ($freshRegisters !== []) {
			$targetRegister = $freshRegisters[0];
		}

		if ($targetRegister === null) {
			$registerIds = $configuration->getRegisters();
			if (empty($registerIds) === false) {
				$targetRegister = $this->registerMapper->find($registerIds[0], _multitenancy: false, _rbac: false);
			}
		}

		$targetRegisterId = 0;
		if ($targetRegister === null) {
			$this->logger->warning(
				message: '[ImportHandler] No register found for seedData - using register 0',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'appId' => $appId,
					'config_title' => $configData['info']['title'] ?? 'unknown',
				]
			);
		}

		if ($targetRegister !== null) {
			$targetRegisterId = $targetRegister->getId();
			$this->logger->debug(
				message: '[ImportHandler] SeedData will be imported into register',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'register_id' => $targetRegisterId,
					'register_slug' => $targetRegister->getSlug(),
					'register_title' => $targetRegister->getTitle(),
				]
			);
		}

		$this->logger->debug(
			message: '[ImportHandler] Importing seed data objects',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'config_title' => $configData['info']['title'] ?? 'unknown',
				'description' => $seedData['description'] ?? 'no description',
				'object_types' => array_keys($seedData['objects']),
				'target_register' => $targetRegisterId,
			]
		);

		// Ensure dependencies are met before importing seedData.
		// This is checked here (lazy) rather than at start of import to avoid circular dependency issues.
		// TEMPORARILY DISABLED: Causes hanging due to circular dependency when apps try to load configs at boot.
		// See: $this->ensureDependenciesForSeedData(configData: $configData).
		foreach ($seedData['objects'] as $schemaSlug => $objects) {
			// Find schema by slug - first check schemasMap, then database.
			$schema = $this->schemasMap[$schemaSlug] ?? null;

			if ($schema === null) {
				// Try to find schema in database (may be from another app/config).
				// Disable multitenancy to allow cross-app schema lookup.
				try {
					$schema = $this->schemaMapper->find(
						id: $schemaSlug,
						_extend: [],
						_rbac: false,
						_multitenancy: false
					);
					$this->logger->debug(
						message: "[ImportHandler] Found schema '{$schemaSlug}' in database for seedData",
						context: [
							'file' => __FILE__,
							'line' => __LINE__,
							'schemaId' => $schema->getId(),
							'schemaApp' => $schema->getApplication(),
						]
					);
				} catch (\Throwable $e) {
					// PER-ENTITY RESILIENCE: any failure resolving this schema (not
					// found, validation, or otherwise) skips only this schema's seed
					// objects with a warning - never the rest of the seed import.
					$this->logger->warning(
						message: "[ImportHandler] Skipping seed data for schema '{$schemaSlug}' - schema not resolvable: " . $e->getMessage(),
						context: [
							'file' => __FILE__,
							'line' => __LINE__,
							'appId' => $appId,
							'error' => $e->getMessage(),
							'availableSchemasInMap' => array_keys($this->schemasMap),
						]
					);
					continue;
				}//end try
			}//end if

			$this->logger->debug(
				message: "[ImportHandler] Importing seed objects for schema '{$schemaSlug}'",
				context: ['file' => __FILE__, 'line' => __LINE__, 'count' => count($objects)]
			);

			// PRE-CREATE MAGIC MAPPER TABLE: Ensure the magic mapper table exists BEFORE inserting objects.
			if ($this->magicMapper !== null && $targetRegister !== null) {
				try {
					$this->logger->debug(
						message: '[ImportHandler] Pre-creating magic mapper table for schema before importing seed objects',
						context: [
							'file' => __FILE__,
							'line' => __LINE__,
							'schema_id' => $schema->getId(),
							'schema_slug' => $schemaSlug,
							'register_id' => $targetRegisterId,
						]
					);
					$this->magicMapper->ensureTableForRegisterSchema(
						register: $targetRegister,
						schema: $schema
					);
				} catch (\Exception $e) {
					// Non-fatal: if table creation fails, object saving may fail.
					$this->logger->warning(
						message: '[ImportHandler] Failed to pre-create magic mapper table',
						context: [
							'file' => __FILE__,
							'line' => __LINE__,
							'schema_slug' => $schemaSlug,
							'register_id' => $targetRegisterId,
							'error' => $e->getMessage(),
						]
					);
				}//end try
			}//end if

			foreach ($objects as $objectData) {
				// Strip + capture _relatedItems before any other processing.
				// Must happen before the object is persisted so the marker
				// never reaches the database. Tasks/notes/files are created
				// AFTER successful insert so we have a real UUID to link to.
				$relatedItems = ($objectData['_relatedItems'] ?? null);
				unset($objectData['_relatedItems']);

				// Check if object has @self with external configuration reference.
				// This allows seedData from one app to reference schemas/registers from another app's configuration.
				$selfData = $objectData['@self'] ?? null;
				$externalConfigUrl = $selfData['configuration'] ?? null;
				$externalRegisterSlug = $selfData['register'] ?? null;
				$externalSchemaSlug = $selfData['schema'] ?? null;

				// Start with the current target register (from configuration).
				$targetRegId = $targetRegisterId;
				$objectSchema = $schema;
				$objectRegister = $targetRegister;
				// Track the Register object for idempotency checks.
				// If object references external configuration, resolve schema and register from that config.
				if ($externalConfigUrl !== null) {
					$this->logger->debug(
						message: '[ImportHandler] SeedData object references external configuration',
						context: [
							'file' => __FILE__,
							'line' => __LINE__,
							'config_url' => $externalConfigUrl,
							'register_slug' => $externalRegisterSlug,
							'schema_slug' => $externalSchemaSlug,
							'object_title' => $objectData['title'] ?? 'unknown',
						]
					);

					// Find the external register by slug.
					if ($externalRegisterSlug !== null) {
						try {
							// Use slug-to-ID map for efficient lookup.
							$slugToIdMap = $this->registerMapper->getSlugToIdMap();

							if (isset($slugToIdMap[$externalRegisterSlug]) === false) {
								$this->logger->warning(
									message: '[ImportHandler] External register not found - using default',
									context: [
										'file' => __FILE__,
										'line' => __LINE__,
										'requested_slug' => $externalRegisterSlug,
										'available_slugs' => array_keys($slugToIdMap),
									]
								);
							}

							if (isset($slugToIdMap[$externalRegisterSlug]) === true) {
								$externalRegisterId = $slugToIdMap[$externalRegisterSlug];
								$externalRegister = $this->registerMapper->find(
									id: $externalRegisterId,
									_rbac: false,
									_multitenancy: false
								);
								$targetRegId = $externalRegister->getId();
								$objectRegister = $externalRegister;
								// Update for idempotency check.
								$this->logger->debug(
									message: '[ImportHandler] Resolved external register for seedData object',
									context: [
										'file' => __FILE__,
										'line' => __LINE__,
										'slug' => $externalRegisterSlug,
										'id' => $targetRegId,
										'title' => $externalRegister->getTitle(),
									]
								);
							}//end if
						} catch (\Exception $e) {
							$this->logger->error(
								message: '[ImportHandler] Failed to resolve external register',
								context: [
									'file' => __FILE__,
									'line' => __LINE__,
									'slug' => $externalRegisterSlug,
									'error' => $e->getMessage(),
								]
							);
						}//end try
					}//end if

					// Find the external schema by slug (if different from current schema).
					if ($externalSchemaSlug !== null) {
						try {
							$externalSchemas = $this->schemaMapper->findBySlug(
								slug: $externalSchemaSlug,
								limit: 1,
								offset: 0,
								_multitenancy: false,
								_rbac: false
							);

							if (empty($externalSchemas) === true) {
								$this->logger->warning(
									message: '[ImportHandler] External schema not found - using current schema',
									context: [
										'file' => __FILE__,
										'line' => __LINE__,
										'requested_slug' => $externalSchemaSlug,
										'current_schema' => $schemaSlug,
									]
								);
							}

							if (empty($externalSchemas) === false) {
								$objectSchema = $externalSchemas[0];
								$this->logger->debug(
									message: '[ImportHandler] Resolved external schema for seedData object',
									context: [
										'file' => __FILE__,
										'line' => __LINE__,
										'slug' => $externalSchemaSlug,
										'id' => $objectSchema->getId(),
										'title' => $objectSchema->getTitle(),
									]
								);
							}
						} catch (\Exception $e) {
							$this->logger->error(
								message: '[ImportHandler] Failed to resolve external schema',
								context: [
									'file' => __FILE__,
									'line' => __LINE__,
									'slug' => $externalSchemaSlug,
									'error' => $e->getMessage(),
								]
							);
						}//end try
					}//end if
				}//end if

				$objectSlug = $objectData['slug'] ?? $objectData['title'] ?? null;
				if ($objectSlug === null) {
					$this->logger->error(
						message: "[ImportHandler] Seed for '{$schemaSlug}' missing slug and title",
						context: [
							'file' => __FILE__,
							'line' => __LINE__,
							'objectData' => $objectData,
						]
					);
					continue;
				}

				try {
					// IDEMPOTENCY CHECK: Check if object already exists by slug or uuid.
					// This prevents duplicate data when configuration is run multiple times.
					$existingObject = null;
					$lookupIdentifier = $objectData['uuid'] ?? $objectSlug;

					try {
						// Try to find existing object using MagicMapper.
						// Disable RBAC/multitenancy to find objects from any app/tenant.
						// No MagicMapper or register context available - cannot look up.
						$existingObject = null;
						if ($this->routingMapper !== null && $objectRegister !== null) {
							$existingObject = $this->routingMapper->find(
								identifier: $lookupIdentifier,
								register: $objectRegister,
								schema: $objectSchema,
								includeDeleted: false,
								_rbac: false,
								_multitenancy: false
							);
						}
					} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
						// Object doesn't exist - this is expected, we'll create it.
						$existingObject = null;
					} catch (\OCP\AppFramework\Db\MultipleObjectsReturnedException $e) {
						// Multiple objects found with same identifier - log warning and skip.
						$warnMsg = '[ImportHandler] Multiple seed objects found with identifier';
						$warnMsg .= " '{$lookupIdentifier}' - skipping to avoid duplication";
						$this->logger->warning(
							message: $warnMsg,
							context: [
								'file' => __FILE__,
								'line' => __LINE__,
								'schema' => $schemaSlug,
								'identifier' => $lookupIdentifier,
							]
						);
						continue;
					}//end try

					if ($existingObject !== null) {
						// Object already exists - skip creation to prevent duplication.
						$this->logger->debug(
							message: '[ImportHandler] Seed object already exists - skipping',
							context: [
								'file' => __FILE__,
								'line' => __LINE__,
								'schema' => $schemaSlug,
								'identifier' => $lookupIdentifier,
								'object_id' => $existingObject->getId(),
							]
						);
						$result['objects'][] = $existingObject->getId();
						continue;
					}

					// Use MagicMapper directly for seedData objects to avoid complex ObjectService dependencies.
					// SeedData objects are simple and don't require cascading or complex validation.
					$objectEntity = new ObjectEntity();

					// Generate UUID if not provided.
					$uuid = $objectData['uuid'] ?? \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
					$objectEntity->setUuid($uuid);

					// Set the slug for future idempotency checks.
					$objectEntity->setSlug($objectSlug);

					// Set schema reference - use resolved external schema if available.
					$objectEntity->setSchema($objectSchema->getId());

					// Use the resolved target register (either from external config or default).
					// SeedData with external config references goes to the external register.
					$objectEntity->setRegister($targetRegId);

					// Store object data.
					$objectEntity->setObject($objectData);

					// Set timestamps.
					$now = new DateTime();
					$objectEntity->setCreated($now);
					$objectEntity->setUpdated($now);

					// Insert into database using MagicMapper if available.
					// Fallback: MagicMapper not available, use objectEntityMapper.
					$createdObject = $this->objectEntityMapper->insert($objectEntity);
					if ($this->routingMapper !== null) {
						$createdObject = $this->routingMapper->insert($objectEntity);
					}

					$result['objects'][] = $createdObject->getId();
					$this->logger->debug(
						message: '[ImportHandler] Seed object imported',
						context: [
							'file' => __FILE__,
							'line' => __LINE__,
							'schema' => $schemaSlug,
							'object_id' => $createdObject->getId(),
							'slug' => $objectSlug,
						]
					);

					if (is_array($relatedItems) === true && count($relatedItems) > 0) {
						$this->processRelatedItems(
							object: $createdObject,
							relatedItems: $relatedItems,
							registerId: (int)$targetRegId,
							schemaId: (int)$objectSchema->getId(),
							objectTitle: (string)($objectData['title'] ?? $objectSlug),
							hasUserContext: $hasUserContext,
							result: $result
						);
					}
				} catch (\Throwable $e) {
					// PER-ENTITY RESILIENCE: skip this seed object, keep the rest.
					$result['skipped']['seedObjects']++;
					$this->logger->warning(
						message: "[ImportHandler] Skipping seed object for '{$schemaSlug}' - import failed: " . $e->getMessage(),
						context: [
							'file' => __FILE__,
							'line' => __LINE__,
							'schemaSlug' => $schemaSlug,
							'objectSlug' => $objectSlug ?? null,
							'error' => $e->getMessage(),
						]
					);
				}//end try
			}//end foreach
		}//end foreach

		// Info: the summary line, carrying counts. One per import, and the
		// thing you actually want when asking what an import did.
		$this->logger->info(
			message: '[ImportHandler] Seed data import complete',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'config_title' => $configData['info']['title'] ?? 'unknown',
				'imported' => count($result['objects']),
				'related_files' => $result['relatedFiles'] ?? 0,
				'related_notes' => $result['relatedNotes'] ?? 0,
				'related_tasks' => $result['relatedTasks'] ?? 0,
			]
		);
	}//end importSeedDataObjects()

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

		// Debug: per seed object.
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
		$notesCreated = 0;
		$tasksCreated = 0;

		if (count($files) > 0 && $this->fileService !== null) {
			foreach ($files as $fileSpec) {
				$name = (string)($fileSpec['name'] ?? '');
				$content = ($fileSpec['content'] ?? null);
				if ($name === '' || is_string($content) === false) {
					continue;
				}

				$tags = (array)($fileSpec['tags'] ?? []);
				$share = (bool)($fileSpec['share'] ?? false);

				// `base64:` prefix means the content was encoded; strip + decode.
				if (str_starts_with($content, 'base64:') === true) {
					$decoded = base64_decode(substr($content, 7), strict: true);
					if ($decoded === false) {
						$this->logger->warning(
							message: '[ImportHandler] Seed file base64 decode failed - skipping',
							context: ['object_uuid' => $object->getUuid(), 'name' => $name]
						);
						continue;
					}

					$content = $decoded;
				}

				try {
					$this->fileService->addFile($object, $name, $content, $share, $tags);
					$filesCreated++;
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
		}//end if

		if (count($notes) > 0 && $this->noteService !== null && $hasUserContext === false) {
			$this->logger->warning(
				message: '[ImportHandler] Skipping seed notes - no user session available',
				context: ['object_uuid' => $object->getUuid(), 'count' => count($notes)]
			);
		} elseif (count($notes) > 0 && $this->noteService !== null) {
			foreach ($notes as $noteSpec) {
				$message = (string)($noteSpec['message'] ?? '');
				if ($message === '') {
					continue;
				}

				try {
					$this->noteService->createNote((string)$object->getUuid(), $message);
					$notesCreated++;
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
		}//end if

		if (count($tasks) > 0 && $this->taskService !== null && $hasUserContext === false) {
			$this->logger->warning(
				message: '[ImportHandler] Skipping seed tasks - no user session available',
				context: ['object_uuid' => $object->getUuid(), 'count' => count($tasks)]
			);
		} elseif (count($tasks) > 0 && $this->taskService !== null) {
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
						$registerId,
						$schemaId,
						(string)$object->getUuid(),
						$objectTitle,
						$taskData
					);
					$tasksCreated++;
				} catch (\Throwable $e) {
					$this->logger->warning(
						message: '[ImportHandler] Seed task creation failed',
						context: [
							'object_uuid' => $object->getUuid(),
							'summary' => $summary,
							'error' => $e->getMessage(),
						]
					);
				}
			}//end foreach
		}//end if

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
	 * Ensure Nextcloud app dependencies are met for seedData import.
	 *
	 * This method is called ONLY when importing seedData (lazy resolution) to avoid
	 * circular dependency issues. It uses a guard flag to prevent recursive calls
	 * when enabled apps load their own configurations.
	 *
	 * @param array $configData The configuration data.
	 *
	 * @return void
	 */
	private function ensureDependenciesForSeedData(array $configData): void {
		// GUARD: Prevent recursive dependency checking.
		// When we enable an app, it may boot and load its own config, which would
		// trigger this method again. The guard prevents infinite recursion.
		if (self::$isDependCheckActive === true) {
			$this->logger->debug(
				message: '[ImportHandler] Skipping recursive dependency check (guard flag active)',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return;
		}

		$dependencies = $configData['x-openregister']['dependencies'] ?? [];
		if (empty($dependencies) === true) {
			return;
		}

		$this->logger->debug(
			message: '[ImportHandler] Ensuring Nextcloud app dependencies for seedData',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'count' => count($dependencies),
			]
		);

		// Set guard flag before processing.
		self::$isDependCheckActive = true;

		try {
			foreach ($dependencies as $dependency) {
				$type = $dependency['type'] ?? null;

				// Only handle Nextcloud app dependencies.
				if ($type !== 'nextcloud-app') {
					continue;
				}

				$appId = $dependency['app'] ?? null;
				$required = $dependency['required'] ?? false;
				$reason = $dependency['reason'] ?? 'Required for seedData import';

				if ($appId === null) {
					$this->logger->warning(
						message: '[ImportHandler] Nextcloud app dependency missing app ID - skipping',
						context: ['file' => __FILE__, 'line' => __LINE__]
					);
					continue;
				}

				$this->logger->debug(
					message: "[ImportHandler] Checking Nextcloud app dependency: {$appId}",
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'required' => $required,
						'reason' => $reason,
					]
				);

				try {
					$appManager = \OC::$server->get(\OCP\App\IAppManager::class);

					// First check if app is installed.
					if ($appManager->isInstalled($appId) === false) {
						$msg = "Nextcloud app '{$appId}' is not installed";
						$this->logger->warning(
							message: '[ImportHandler] ' . $msg,
							context: ['file' => __FILE__, 'line' => __LINE__]
						);

						if ($required === true) {
							throw new Exception($msg . ' - cannot enable required app for seedData');
						}

						continue;
					}

					if ($appManager->isEnabledForUser($appId) === true) {
						$this->logger->debug(
							message: "[ImportHandler] Nextcloud app '{$appId}' is already enabled",
							context: ['file' => __FILE__, 'line' => __LINE__]
						);
						continue;
					}

					$this->logger->debug(
						message: "[ImportHandler] Nextcloud app '{$appId}' is not enabled - enabling now",
						context: ['file' => __FILE__, 'line' => __LINE__]
					);

					try {
						$appManager->enableApp($appId);
						$this->logger->debug(
							message: "[ImportHandler] Successfully enabled Nextcloud app '{$appId}'",
							context: ['file' => __FILE__, 'line' => __LINE__]
						);

						// Load the app to ensure its services are available.
						\OC_App::loadApp($appId);
						$this->logger->debug(
							message: "[ImportHandler] Successfully loaded Nextcloud app '{$appId}'",
							context: ['file' => __FILE__, 'line' => __LINE__]
						);
					} catch (Exception $e) {
						$msg = "Failed to enable Nextcloud app '{$appId}': {$e->getMessage()}";
						if ($required === true) {
							throw new Exception($msg);
						}

						$this->logger->warning(
							message: '[ImportHandler] ' . $msg,
							context: ['file' => __FILE__, 'line' => __LINE__]
						);
					}//end try
				} catch (Exception $e) {
					$msg = "Error checking/enabling Nextcloud app '{$appId}': {$e->getMessage()}";
					if ($required === true) {
						throw new Exception($msg);
					}

					$this->logger->warning(
						message: '[ImportHandler] ' . $msg,
						context: ['file' => __FILE__, 'line' => __LINE__]
					);
				}//end try
			}//end foreach
		} finally {
			// Always reset guard flag, even if exception occurred.
			self::$isDependCheckActive = false;
		}//end try
	}//end ensureDependenciesForSeedData()

	/**
	 * Handle Nextcloud app dependencies.
	 *
	 * @param array $configData The configuration data.
	 *
	 * @return void
	 *
	 * @deprecated Use ensureDependenciesForSeedData() instead. This method is kept for backwards compatibility.
	 *
	 * @SuppressWarnings(PHPMD.UnusedPrivateMethod) Kept for backwards compatibility
	 */
	private function handleNextcloudAppDependencies(array $configData): void {
		$this->ensureDependenciesForSeedData(configData: $configData);
	}//end handleNextcloudAppDependencies()

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
