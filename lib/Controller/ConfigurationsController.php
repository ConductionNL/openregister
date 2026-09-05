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

use DateTime;
use Exception;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\UploadService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
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
	 * User ID for ownership tracking.
	 *
	 * @var string|null User ID or null if not authenticated.
	 */
	private readonly ?string $userId;

	/**
	 * Constructor for ConfigurationController.
	 *
	 * @param string $appName The name of the app
	 * @param IRequest $request The request object
	 * @param ConfigurationMapper $configurationMapper The configuration mapper instance
	 * @param ConfigurationService $configurationService The configuration service instance
	 * @param UploadService $uploadService The upload service instance
	 * @param string|null $userId The current user ID
	 * @param IUserSession $userSession User session for admin checks
	 * @param IGroupManager $groupManager Group manager for admin checks
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ConfigurationMapper $configurationMapper,
		private readonly ConfigurationService $configurationService,
		private readonly UploadService $uploadService,
		?string $userId,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->userId = $userId;
	}//end __construct()

	/**
	 * Check whether the currently authenticated user is a Nextcloud administrator.
	 *
	 * @return bool True if a user is signed in and belongs to the admin group.
	 */
	private function isCurrentUserAdmin(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->groupManager->isAdmin($user->getUID());
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
	 * @SuppressWarnings(PHPMD.StaticAccess)         Uuid::v4() is a standard utility pattern
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
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

		$data = $this->request->getParams();

		// Remove internal parameters and data attribute.
		foreach (array_keys($data) as $key) {
			if (str_starts_with($key, '_') === true || $key === 'data') {
				unset($data[$key]);
			}
		}

		// Ensure we have a UUID.
		if (isset($data['uuid']) === false) {
			$data['uuid'] = Uuid::v4();
		}

		// Set default values for new local configurations.
		// If sourceType is not provided, assume it's a local configuration.
		if (isset($data['sourceType']) === false || $data['sourceType'] === null || $data['sourceType'] === '') {
			$data['sourceType'] = 'local';
		}

		// Set isLocal based on sourceType (enforce consistency).
		// Local configurations: sourceType === 'local' or 'manual' → isLocal = true.
		// External configurations: sourceType === 'github', 'gitlab', or 'url' → isLocal = false.
		if (in_array($data['sourceType'], ['local', 'manual'], true) === true) {
			$data['isLocal'] = true;
		} elseif (in_array($data['sourceType'], ['github', 'gitlab', 'url'], true) === true) {
			$data['isLocal'] = false;
		} elseif (isset($data['isLocal']) === false) {
			// Fallback: if sourceType is something else and isLocal not set, default to true.
			$data['isLocal'] = true;
		}

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

		$data = $this->request->getParams();

		// Remove internal parameters and data attribute.
		foreach (array_keys($data) as $key) {
			if (str_starts_with($key, '_') === true || $key === 'data') {
				unset($data[$key]);
			}
		}

		// Remove immutable fields to prevent tampering.
		unset($data['id']);
		unset($data['organisation']);
		unset($data['owner']);
		unset($data['created']);

		// Enforce consistency between sourceType and isLocal.
		if (($data['sourceType'] ?? null) !== null) {
			if (in_array($data['sourceType'], ['local', 'manual'], true) === true) {
				$data['isLocal'] = true;
			} elseif (in_array($data['sourceType'], ['github', 'gitlab', 'url'], true) === true) {
				$data['isLocal'] = false;
			}
		}

		try {
			return new JSONResponse(
				data: $this->configurationMapper->updateFromArray(id: $id, data: $data)
			);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => 'Failed to update configuration: ' . $e->getMessage()], statusCode: 400);
		}
	}//end update()

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
	 * Export a configuration
	 *
	 * @param int $id Configuration ID.
	 * @param bool $includeObjects Whether to include objects in the export.
	 *
	 * @return DataDownloadResponse|JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return DataDownloadResponse<200, 'application/json',
	 *     array<never, never>>|JSONResponse<400|403, array{error: string},
	 *     array<never, never>>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Toggle to include/exclude objects in export
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-object-data/tasks.md#task-14
	 */
	public function export(int $id, bool $includeObjects = false): JSONResponse|DataDownloadResponse {
		try {
			// Find the configuration.
			$configuration = $this->configurationMapper->find($id);

			// Export the configuration and its related data.
			$exportData = $this->configurationService->exportConfig(input: $configuration, includeObjects: $includeObjects);

			// Convert to JSON.
			$jsonContent = json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			if ($jsonContent === false) {
				throw new Exception('Failed to encode configuration data to JSON');
			}

			// Generate filename.
			$filename = sprintf(
				'configuration_%s_%s.json',
				$configuration->getTitle() ?? 'unknown',
				(new DateTime())->format('Y-m-d_His')
			);

			// Return as downloadable file.
			return new DataDownloadResponse(
				$jsonContent,
				$filename,
				'application/json'
			);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => 'Failed to export configuration: ' . $e->getMessage()], statusCode: 400);
		}//end try
	}//end export()

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
			// Initialize uploadedFiles array.
			$uploadedFiles = [];

			// Get the uploaded file from the request if a single file has been uploaded.
			$uploadedFile = $this->request->getUploadedFile(key: 'file');
			if (empty($uploadedFile) === false) {
				$uploadedFiles[] = $uploadedFile;
			}

			// Get the uploaded JSON data.
			$params = $this->request->getParams();
			$jsonData = $this->configurationService->getUploadedJson(data: $params, uploadedFiles: $uploadedFiles);
			if ($jsonData instanceof JSONResponse) {
				return $jsonData;
			}

			// Create a Configuration entity from the JSON data.
			// This is required for proper entity tracking in ImportHandler.
			$configuration = new Configuration();
			$configuration->setTitle($jsonData['info']['title'] ?? 'Imported Configuration');
			$configuration->setDescription($jsonData['info']['description'] ?? '');
			$configuration->setVersion($jsonData['info']['version'] ?? '1.0.0');
			$configuration->setSourceType('upload');
			$configuration->setApp($this->request->getParam('appId') ?? ($jsonData['x-openregister']['app'] ?? 'unknown'));
			$configuration->setOwner($this->request->getParam('owner') ?? $this->userId);
			$configuration->setCreated(new DateTime());
			$configuration->setUpdated(new DateTime());
			$configuration->setRegisters([]);
			$configuration->setSchemas([]);
			$configuration->setObjects([]);

			// Persist the configuration entity so it appears in the configurations list.
			$configuration = $this->configurationMapper->insert($configuration);

			// Import the data.
			$force = $this->request->getParam('force') === 'true' || $this->request->getParam('force') === true;
			$result = $this->configurationService->importFromJson(
				data: $jsonData,
				configuration: $configuration,
				owner: $this->request->getParam('owner'),
				appId: $this->request->getParam('appId'),
				version: $this->request->getParam('version'),
				force: $force
			);

			// Link the imported registers, schemas and objects to the configuration.
			//
			// `$result['objects']` IS NOT UNIFORMLY ENTITIES. ImportHandler
			// appends an ObjectEntity in two places and a bare id in two others
			// (`$result['objects'][] = $existingObject->getId()`), so a
			// descriptor that carries seed objects reached
			// `$obj->getId()` on an int and died with a TypeError. That is an
			// `Error`, not an `Exception`, so the catch below did not see it
			// either: the endpoint answered 500 with Nextcloud's HTML error page
			// instead of the JSON it documents. Measured on stackiq's register,
			// whose import was the only one of eighteen to fail this way.
			$registerIds = self::idsOf(items: $result['registers']);
			$schemaIds = self::idsOf(items: $result['schemas']);
			$objectIds = self::idsOf(items: $result['objects']);

			$configuration->setRegisters(array_values(array_unique($registerIds)));
			$configuration->setSchemas(array_values(array_unique($schemaIds)));
			$configuration->setObjects(array_values(array_unique($objectIds)));
			$this->configurationMapper->update($configuration);

			return new JSONResponse(
				data: [
					'message' => 'Import successful',
					'imported' => $result,
				]
			);
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
			if (is_object($item) === true && method_exists($item, 'getId') === true) {
				$ids[] = $item->getId();
				continue;
			}

			if (is_int($item) === true || is_string($item) === true) {
				$ids[] = $item;
			}
		}

		return $ids;
	}//end idsOf()
}//end class
