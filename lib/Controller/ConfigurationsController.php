<?php

/**
 * OpenRegister Configuration Controller
 *
 * This file contains the controller class for handling configuration operations
 * in the OpenRegister application.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\ConfigurationsControllerServices;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapperInterface;
use OCA\OpenRegister\Service\ConfigurationServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Symfony\Component\Uid\Uuid;

/**
 * Class ConfigurationController
 *
 * @package OCA\OpenRegister\Controller
 */

/**
 * Controller for managing configurations
 *
 * @psalm-suppress UnusedClass
 */
class ConfigurationsController extends Controller {

	/**
	 * Source types whose configurations are local (isLocal = true).
	 *
	 * @var string[]
	 */
	private const LOCAL_SOURCE_TYPES = ['local', 'manual'];

	/**
	 * Source types whose configurations are external (isLocal = false).
	 *
	 * @var string[]
	 */
	private const REMOTE_SOURCE_TYPES = ['github', 'gitlab', 'url'];

	/**
	 * The configuration mapper instance
	 *
	 * @var ConfigurationMapperInterface
	 */
	private readonly ConfigurationMapperInterface $configurationMapper;

	/**
	 * The configuration service instance
	 *
	 * @var ConfigurationServiceInterface
	 */
	private readonly ConfigurationServiceInterface $configurationService;

	/**
	 * The controller's injected services.
	 *
	 * @var ConfigurationsControllerServices
	 */
	private readonly ConfigurationsControllerServices $services;

	/**
	 * Constructor for ConfigurationController.
	 *
	 * @param IRequest                         $request  The request object
	 * @param ConfigurationsControllerServices $services The configurations controller services.
	 */
	public function __construct(
		IRequest $request,
		ConfigurationsControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->services = $services;
		$this->configurationMapper = $services->records->configurationMapper;
		$this->configurationService = $services->records->configurationService;
	}//end __construct()

	/**
	 * Check whether the currently authenticated user is a Nextcloud administrator.
	 *
	 * @return bool True if a user is signed in and belongs to the admin group.
	 */
	private function isCurrentUserAdmin(): bool {
		return $this->services->session->isAdmin();
	}//end isCurrentUserAdmin()

	/**
	 * List all configurations
	 *
	 * @return JSONResponse List of configurations.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<200, array{results: array<\OCA\OpenRegister\Db\Configuration>}, array<never, never>>
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function index(): JSONResponse {
		// Get request parameters for filtering and searching.
		$filters = $this->request->getParams();

		unset($filters['_route']);

		$searchParams = [];
		$searchConditions = [];
		$filters = $filters;

		// Admins bypass multitenancy so they can see all configurations.
		// Non-admin authenticated users see only their tenant's configurations.
		$multitenancy = ($this->isCurrentUserAdmin() === false);

		// Return all configurations that match the search conditions.
		return new JSONResponse(
			data: [
				'results' => $this->configurationMapper->findAll(
					limit: null,
					offset: null,
					filters: $filters,
					searchConditions: $searchConditions,
					searchParams: $searchParams,
					_multitenancy: $multitenancy
				),
			]
		);
	}//end index()

	/**
	 * Show a specific configuration
	 *
	 * @param int $id Configuration ID
	 *
	 * @return JSONResponse Configuration details
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<200, \OCA\OpenRegister\Db\Configuration,
	 *     array<never, never>>|JSONResponse<404,
	 *     array{error: 'Configuration not found'}, array<never, never>>
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function show(int $id): JSONResponse {
		try {
			// Admins bypass multitenancy; non-admins see only their tenant's configurations.
			$multitenancy = ($this->isCurrentUserAdmin() === false);
			return new JSONResponse(data: $this->configurationMapper->find($id, _multitenancy: $multitenancy));
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => 'Configuration not found'], statusCode: 404);
		}
	}//end show()

	/**
	 * Create a new configuration
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with created configuration or error
	 *
	 * @psalm-return JSONResponse<201, \OCA\OpenRegister\Db\Configuration,
	 *     array<never, never>>|JSONResponse<400|403, array{error: string},
	 *     array<never, never>>
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function create(): JSONResponse {
		if ($this->isCurrentUserAdmin() === false) {
			return new JSONResponse(data: ['error' => 'Admin privileges required'], statusCode: 403);
		}

		$data = self::newConfigurationData(params: $this->request->getParams());

		try {
			return new JSONResponse(
				data: $this->configurationMapper->createFromArray($data),
				statusCode: 201
			);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => 'Failed to create configuration: ' . $e->getMessage()], statusCode: 400);
		}
	}//end create()

	/**
	 * The data a new configuration is created from: the request parameters
	 * without internal keys, with a UUID, a sourceType and a matching isLocal.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4() is a standard utility pattern
	 *
	 * @return array<string, mixed> The configuration data.
	 */
	private static function newConfigurationData(array $params): array {
		$data = self::withoutInternalParams(params: $params);

		// Ensure we have a UUID.
		if (isset($data['uuid']) === false) {
			$data['uuid'] = Uuid::v4();
		}

		// Set default values for new local configurations.
		// If sourceType is not provided, assume it's a local configuration.
		if (self::hasNoSourceType(data: $data) === true) {
			$data['sourceType'] = 'local';
		}

		return self::withNewIsLocal(data: $data);
	}//end newConfigurationData()

	/**
	 * Whether the configuration data carries no usable sourceType.
	 *
	 * @param array<string, mixed> $data The configuration data.
	 *
	 * @return bool True when sourceType is missing, null or empty.
	 */
	private static function hasNoSourceType(array $data): bool {
		return isset($data['sourceType']) === false || $data['sourceType'] === null || $data['sourceType'] === '';
	}//end hasNoSourceType()

	/**
	 * Set isLocal on new configuration data from its sourceType (enforce consistency).
	 *
	 * Local configurations: sourceType === 'local' or 'manual' → isLocal = true.
	 * External configurations: sourceType === 'github', 'gitlab', or 'url' → isLocal = false.
	 * Fallback: if sourceType is something else and isLocal not set, default to true.
	 *
	 * @param array<string, mixed> $data The configuration data.
	 *
	 * @return array<string, mixed> The data with isLocal set.
	 */
	private static function withNewIsLocal(array $data): array {
		$isLocal = self::isLocalForSourceType(sourceType: $data['sourceType']);
		if ($isLocal !== null) {
			$data['isLocal'] = $isLocal;
		} elseif (isset($data['isLocal']) === false) {
			$data['isLocal'] = true;
		}

		return $data;
	}//end withNewIsLocal()

	/**
	 * Whether a sourceType is a local one, a remote one, or neither.
	 *
	 * @param mixed $sourceType The sourceType value.
	 *
	 * @return bool|null True for a local source, false for a remote one, null otherwise.
	 */
	private static function isLocalForSourceType(mixed $sourceType): ?bool {
		if (in_array($sourceType, self::LOCAL_SOURCE_TYPES, true) === true) {
			return true;
		}

		if (in_array($sourceType, self::REMOTE_SOURCE_TYPES, true) === true) {
			return false;
		}

		return null;
	}//end isLocalForSourceType()

	/**
	 * The request parameters without internal (`_`-prefixed) keys and the data attribute.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return array<string, mixed> The remaining parameters.
	 */
	private static function withoutInternalParams(array $params): array {
		foreach (array_keys($params) as $key) {
			if (str_starts_with($key, '_') === true || $key === 'data') {
				unset($params[$key]);
			}
		}

		return $params;
	}//end withoutInternalParams()

	/**
	 * Update an existing configuration
	 *
	 * @param int $id Configuration ID
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with updated configuration or error
	 *
	 * @psalm-return JSONResponse<200, \OCA\OpenRegister\Db\Configuration,
	 *     array<never, never>>|JSONResponse<400|403, array{error: string},
	 *     array<never, never>>
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function update(int $id): JSONResponse {
		if ($this->isCurrentUserAdmin() === false) {
			return new JSONResponse(data: ['error' => 'Admin privileges required'], statusCode: 403);
		}

		$data = self::updatedConfigurationData(params: $this->request->getParams());

		try {
			return new JSONResponse(
				data: $this->configurationMapper->updateFromArray(id: $id, data: $data)
			);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => 'Failed to update configuration: ' . $e->getMessage()], statusCode: 400);
		}
	}//end update()

	/**
	 * The data a configuration is updated with: the request parameters without
	 * internal keys and immutable fields, with isLocal matching a known sourceType.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return array<string, mixed> The configuration data.
	 */
	private static function updatedConfigurationData(array $params): array {
		$data = self::withoutInternalParams(params: $params);

		// Remove immutable fields to prevent tampering.
		unset($data['id']);
		unset($data['organisation']);
		unset($data['owner']);
		unset($data['created']);

		// Enforce consistency between sourceType and isLocal.
		if (($data['sourceType'] ?? null) !== null) {
			$isLocal = self::isLocalForSourceType(sourceType: $data['sourceType']);
			if ($isLocal !== null) {
				$data['isLocal'] = $isLocal;
			}
		}

		return $data;
	}//end updatedConfigurationData()

	/**
	 * Patch (partially update) a configuration.
	 *
	 * @param int $id The ID of the configuration to patch
	 *
	 * @return JSONResponse The updated configuration data
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<200, \OCA\OpenRegister\Db\Configuration,
	 *     array<never, never>>|JSONResponse<400|403, array{error: string},
	 *     array<never, never>>
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function patch(int $id): JSONResponse {
		return $this->update(id: $id);
	}//end patch()

	/**
	 * Delete a configuration
	 *
	 * @param int $id Configuration ID
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response on success (204) or error
	 *
	 * @psalm-return JSONResponse<204, null,
	 *     array<never, never>>|JSONResponse<400|403, array{error: string},
	 *     array<never, never>>
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function destroy(int $id): JSONResponse {
		if ($this->isCurrentUserAdmin() === false) {
			return new JSONResponse(data: ['error' => 'Admin privileges required'], statusCode: 403);
		}

		try {
			// Disable multitenancy filtering for delete operations.
			// When deleting by ID, admins should be able to delete configurations regardless of organisation.
			$configuration = $this->configurationMapper->find($id, _multitenancy: false);
			$this->configurationMapper->delete($configuration);
			return new JSONResponse(data: null, statusCode: 204);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => 'Failed to delete configuration: ' . $e->getMessage()], statusCode: 400);
		}
	}//end destroy()

	/**
	 * Import a configuration from uploaded file or JSON data
	 *
	 * Accepts either:
	 * - A file upload via 'file' parameter
	 * - Raw JSON data in the request body
	 *
	 * Additional parameters:
	 * - appId: Application ID for the configuration
	 * - owner: Owner of the configuration (defaults to current user)
	 * - force: Force import even if version is older
	 *
	 * @return JSONResponse The import result.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-object-data/tasks.md#task-14
	 */
	public function import(): JSONResponse {
		if ($this->isCurrentUserAdmin() === false) {
			return new JSONResponse(data: ['error' => 'Admin privileges required'], statusCode: 403);
		}

		try {
			return $this->runImport();
		} catch (\Throwable $e) {
			// Throwable, not Exception. A TypeError in this method is a bug in
			// OpenRegister rather than bad input, and answering it with a 500
			// HTML page hides which of the two it was — the caller cannot tell a
			// malformed descriptor from a crash, and neither could the operator
			// reading the response.
			return new JSONResponse(data: ['error' => 'Failed to import configuration: ' . $e->getMessage()], statusCode: 400);
		}//end try
	}//end import()

	/**
	 * Read the uploaded descriptor, create its configuration, import it and link the result.
	 *
	 * @return JSONResponse The import result, or the error getUploadedJson() answered.
	 */
	private function runImport(): JSONResponse {
		$jsonData = $this->uploadedImportJson();
		if ($jsonData instanceof JSONResponse) {
			return $jsonData;
		}

		$configuration = $this->createImportedConfiguration(jsonData: $jsonData);
		$result = $this->importIntoConfiguration(jsonData: $jsonData, configuration: $configuration);
		$this->linkImportedEntities(configuration: $configuration, result: $result);

		return new JSONResponse(
			data: [
				'message' => 'Import successful',
				'imported' => $result,
			]
		);
	}//end runImport()

	/**
	 * The uploaded JSON descriptor, from a single uploaded file or the request body.
	 *
	 * @return array|JSONResponse The descriptor, or an error response.
	 */
	private function uploadedImportJson(): array|JSONResponse {
		// Initialize uploadedFiles array.
		$uploadedFiles = [];

		// Get the uploaded file from the request if a single file has been uploaded.
		$uploadedFile = $this->request->getUploadedFile(key: 'file');
		if (empty($uploadedFile) === false) {
			$uploadedFiles[] = $uploadedFile;
		}

		// Get the uploaded JSON data.
		$params = $this->request->getParams();
		return $this->configurationService->getUploadedJson(data: $params, uploadedFiles: $uploadedFiles);
	}//end uploadedImportJson()

	/**
	 * Create and persist the Configuration entity from the JSON data.
	 *
	 * ImportHandler needs the entity for its tracking, and it has to be
	 * in the list afterwards, so it is built and inserted in one call.
	 *
	 * `created` and `updated` are NOT set here: the mapper stamps both
	 * on insert, so setting them was writing values it overwrote.
	 *
	 * @param array $jsonData The uploaded descriptor.
	 *
	 * @return Configuration The inserted configuration.
	 */
	private function createImportedConfiguration(array $jsonData): Configuration {
		return $this->configurationMapper->createFromArray(
			[
				'title' => ($jsonData['info']['title'] ?? 'Imported Configuration'),
				'description' => ($jsonData['info']['description'] ?? ''),
				'version' => ($jsonData['info']['version'] ?? '1.0.0'),
				'sourceType' => 'upload',
				'app' => ($this->request->getParam('appId') ?? ($jsonData['x-openregister']['app'] ?? 'unknown')),
				'owner' => ($this->request->getParam('owner') ?? $this->services->session->userSession->getUser()?->getUID()),
				'registers' => [],
				'schemas' => [],
				'objects' => [],
			]
		);
	}//end createImportedConfiguration()

	/**
	 * Import the descriptor into the configuration.
	 *
	 * @param array         $jsonData      The uploaded descriptor.
	 * @param Configuration $configuration The configuration it is imported into.
	 *
	 * @return array The import result.
	 */
	private function importIntoConfiguration(array $jsonData, Configuration $configuration): array {
		$force = $this->request->getParam('force') === 'true' || $this->request->getParam('force') === true;
		return $this->configurationService->importFromJson(
			data: $jsonData,
			configuration: $configuration,
			owner: $this->request->getParam('owner'),
			appId: $this->request->getParam('appId'),
			version: $this->request->getParam('version'),
			force: $force
		);
	}//end importIntoConfiguration()

	/**
	 * Link the imported registers, schemas and objects to the configuration.
	 *
	 * `$result['objects']` IS NOT UNIFORMLY ENTITIES. ImportHandler
	 * appends an ObjectEntity in two places and a bare id in two others
	 * (`$result['objects'][] = $existingObject->getId()`), so a
	 * descriptor that carries seed objects reached
	 * `$obj->getId()` on an int and died with a TypeError. That is an
	 * `Error`, not an `Exception`, so the catch in import() did not see it
	 * either: the endpoint answered 500 with Nextcloud's HTML error page
	 * instead of the JSON it documents. Measured on stackiq's register,
	 * whose import was the only one of eighteen to fail this way.
	 *
	 * @param Configuration $configuration The imported configuration.
	 * @param array         $result        The import result.
	 *
	 * @return void
	 */
	private function linkImportedEntities(Configuration $configuration, array $result): void {
		$registerIds = self::idsOf(items: $result['registers']);
		$schemaIds = self::idsOf(items: $result['schemas']);
		$objectIds = self::idsOf(items: $result['objects']);

		$configuration->setRegisters(array_values(array_unique($registerIds)));
		$configuration->setSchemas(array_values(array_unique($schemaIds)));
		$configuration->setObjects(array_values(array_unique($objectIds)));
		$this->configurationMapper->update($configuration);
	}//end linkImportedEntities()

	/**
	 * The ids of an import result list, whether it holds entities or ids.
	 *
	 * @param mixed $items Entities, ids, or a mix of the two.
	 *
	 * @return array<int, int|string> The ids, with anything unusable dropped.
	 *
	 * @spec openspec/changes/a-configuration-import-500s-on-a-descriptor-with-objects/specs/configuration-import/spec.md#requirement-the-import-endpoint-answers-in-json-req-cim-001
	 */
	private static function idsOf(mixed $items): array {
		if (is_array($items) === false) {
			return [];
		}

		$ids = [];
		foreach ($items as $item) {
			if (self::isEntityWithId(item: $item) === true) {
				$ids[] = $item->getId();
			} elseif (self::isBareId(item: $item) === true) {
				$ids[] = $item;
			}
		}

		return $ids;
	}//end idsOf()

	/**
	 * Whether an import result item is an entity that carries its id.
	 *
	 * @param mixed $item The import result item.
	 *
	 * @return bool True for an object with a getId() method.
	 */
	private static function isEntityWithId(mixed $item): bool {
		return is_object($item) === true && method_exists($item, 'getId') === true;
	}//end isEntityWithId()

	/**
	 * Whether an import result item is a bare id.
	 *
	 * @param mixed $item The import result item.
	 *
	 * @return bool True for an int or string.
	 */
	private static function isBareId(mixed $item): bool {
		return is_int($item) === true || is_string($item) === true;
	}//end isBareId()
}//end class
