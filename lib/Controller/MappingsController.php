<?php

/**
 * MappingsController handles REST API endpoints for mapping management
 *
 * Controller for managing mapping operations in the OpenRegister app.
 * Provides endpoints for CRUD operations on mappings used for data transformation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\Controller\Support\MappingsControllerServices;
use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MappingMapperInterface;
use OCA\OpenRegister\Service\MappingServiceInterface;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * MappingsController handles REST API endpoints for mapping management
 *
 * Provides REST API endpoints for managing mappings including CRUD operations
 * and testing mappings with sample data.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/openapi-generation/spec.md
 */
class MappingsController extends Controller {
	/**
	 * App configuration
	 *
	 * @var IAppConfig
	 */
	private readonly IAppConfig $config;

	/**
	 * Mapping mapper for database operations
	 *
	 * @var MappingMapperInterface
	 */
	private readonly MappingMapperInterface $mappingMapper;

	/**
	 * Mapping service for executing mappings
	 *
	 * @var MappingServiceInterface
	 */
	private readonly MappingServiceInterface $mappingService;

	/**
	 * Organisation service for multi-tenancy
	 *
	 * @var OrganisationServiceInterface
	 */
	private readonly OrganisationServiceInterface $organisationService;

	/**
	 * Constructor
	 *
	 * Initializes controller with required dependencies for mapping operations.
	 *
	 * @param string $appName Application name
	 * @param IRequest $request HTTP request object
	 * @param MappingsControllerServices $services The mappings controller services.
	 * @param LoggerInterface $logger Logger for error tracking
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		MappingsControllerServices $services,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->config = $services->config;
		$this->mappingMapper = $services->mappingMapper;
		$this->mappingService = $services->mappingService;
		$this->organisationService = $services->organisationService;
	}//end __construct()

	/**
	 * Retrieves a list of all mappings
	 *
	 * Returns a JSON response containing an array of all mappings.
	 * Supports pagination and filtering.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with array of mappings
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
	 */
	public function index(): JSONResponse {
		// Get request parameters.
		$params = $this->request->getParams();

		// Retrieve mappings using mapper.
		$limit = $this->intParam(params: $params, key: '_limit');
		$mappings = $this->mappingMapper->findAll(
			limit: $limit,
			offset: $this->pageOffset(params: $params, limit: $limit)
		);

		// Serialize mappings to arrays.
		$mappingsArr = array_map(
			function (Mapping $mapping): array {
				return $mapping->jsonSerialize();
			},
			$mappings
		);

		return new JSONResponse(data: ['results' => $mappingsArr]);
	}//end index()

	/**
	 * A paging parameter as an integer, or null when absent.
	 *
	 * @param array<string, mixed> $params The request parameters
	 * @param string               $key    The parameter name
	 *
	 * @return int|null The value
	 */
	private function intParam(array $params, string $key): ?int {
		if (isset($params[$key]) === true) {
			return (int)$params[$key];
		}

		return null;
	}//end intParam()

	/**
	 * The offset to list from: `_page` converted to an offset when both it and the limit are given, else `_offset`.
	 *
	 * @param array<string, mixed> $params The request parameters
	 * @param int|null             $limit  The requested limit
	 *
	 * @return int|null The offset
	 */
	private function pageOffset(array $params, ?int $limit): ?int {
		$offset = $this->intParam(params: $params, key: '_offset');
		$page = $this->intParam(params: $params, key: '_page');

		// Convert page to offset if provided.
		if ($page !== null && $limit !== null) {
			$offset = ($page - 1) * $limit;
		}

		return $offset;
	}//end pageOffset()

	/**
	 * Retrieves a single mapping by ID
	 *
	 * @param int|string $id The ID, UUID, or slug of the mapping
	 *
	 * @return JSONResponse JSON response with mapping data
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
	 */
	public function show(int|string $id): JSONResponse {
		try {
			$mapping = $this->mappingMapper->find(id: $id);
			return new JSONResponse(data: $mapping->jsonSerialize());
		} catch (DoesNotExistException $exception) {
			return new JSONResponse(data: ['error' => 'Mapping not found'], statusCode: 404);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
		}
	}//end show()

	/**
	 * Creates a new mapping
	 *
	 * Creates a new mapping based on POST data.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with created mapping
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
	 */
	public function create(): JSONResponse {
		$data = $this->withoutInternalParams(data: $this->request->getParams());

		// Remove ID if present to ensure a new record is created.
		if (isset($data['id']) === true) {
			unset($data['id']);
		}

		try {
			// Create a new mapping from the data.
			$mapping = $this->mappingMapper->createFromArray(data: $data);

			return new JSONResponse(data: $mapping->jsonSerialize(), statusCode: 201);
		} catch (Exception $e) {
			return $this->creationFailed(exception: $e);
		}
	}//end create()

	/**
	 * The request parameters without the internal ones (starting with '_').
	 *
	 * @param array<string, mixed> $data The request parameters
	 *
	 * @return array<string, mixed> The mapping fields
	 */
	private function withoutInternalParams(array $data): array {
		foreach (array_keys($data) as $key) {
			if (str_starts_with($key, '_') === true) {
				unset($data[$key]);
			}
		}

		return $data;
	}//end withoutInternalParams()

	/**
	 * Log a failed creation and answer it.
	 *
	 * @param Exception $exception Why the creation failed
	 *
	 * @return JSONResponse 500 with the message
	 */
	private function creationFailed(Exception $exception): JSONResponse {
		$this->logger->error(
			message: '[MappingsController] Mapping creation failed',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'error_message' => $exception->getMessage(),
				'error_code' => $exception->getCode(),
			]
		);

		return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: 500);
	}//end creationFailed()

	/**
	 * Updates an existing mapping
	 *
	 * Updates a mapping based on its ID.
	 *
	 * @param int $id The ID of the mapping to update
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with updated mapping
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
	 */
	public function update(int $id): JSONResponse {
		$data = $this->withoutInternalParams(data: $this->request->getParams());

		// Remove immutable fields.
		unset($data['id']);
		unset($data['organisation']);
		unset($data['created']);

		try {
			// Update the mapping with the provided data.
			$updatedMapping = $this->mappingMapper->updateFromArray(id: $id, data: $data);

			return new JSONResponse(data: $updatedMapping->jsonSerialize());
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'Mapping not found'], statusCode: 404);
		} catch (Exception $e) {
			return $this->updateFailed(id: $id, exception: $e);
		}//end try
	}//end update()

	/**
	 * Log a failed update and answer it.
	 *
	 * @param int       $id        The mapping
	 * @param Exception $exception Why the update failed
	 *
	 * @return JSONResponse 500 with the message
	 */
	private function updateFailed(int $id, Exception $exception): JSONResponse {
		$this->logger->error(
			message: '[MappingsController] Mapping update failed',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'mapping_id' => $id,
				'error_message' => $exception->getMessage(),
				'error_code' => $exception->getCode(),
			]
		);

		return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: 500);
	}//end updateFailed()

	/**
	 * Deletes a mapping
	 *
	 * Deletes a mapping based on its ID.
	 *
	 * Partially update a mapping by ID.
	 *
	 * The route `mappings#patch` (`PATCH /api/mappings/{id}`) delegates to
	 * the same body as `update()` because Nextcloud routes PATCH and PUT to
	 * separate methods even when the handler is identical. Mirrors the same
	 * shape used on `ApplicationsController::patch`.
	 *
	 * @param int $id The ID of the mapping to patch.
	 *
	 * @return JSONResponse JSON response containing patched mapping.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
	 */
	public function patch(int $id): JSONResponse {
		return $this->update(id: $id);
	}//end patch()

	/**
	 * Delete a mapping by ID.
	 *
	 * @param int $id The ID of the mapping to delete
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Empty JSON response on success
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
	 */
	public function destroy(int $id): JSONResponse {
		try {
			$mapping = $this->mappingMapper->find(id: $id);
			$this->mappingMapper->delete(entity: $mapping);

			return new JSONResponse(data: []);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'Mapping not found'], statusCode: 404);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
		}
	}//end destroy()

	/**
	 * Tests a mapping with provided input data
	 *
	 * Tests a mapping configuration with sample input data to verify
	 * the mapping produces expected output.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt No per-object resource: stateless evaluation of a caller-supplied transient mapping over caller-supplied input;
	 *   nothing is loaded by id.
	 *
	 * @return JSONResponse JSON response with test results
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 *
	 * @spec openspec/specs/openapi-generation/spec.md#requirement-schema-authoring-sub-resources-and-meta-entity-operational-endpoints
	 */
	public function test(): JSONResponse {
		// Get all parameters from the request.
		$data = $this->request->getParams();

		// Validate required parameters.
		if (isset($data['inputObject']) === false || isset($data['mapping']) === false) {
			return new JSONResponse(
				data: ['error' => 'Both `inputObject` and `mapping` are required'],
				statusCode: 400
			);
		}

		// Create a new Mapping object and hydrate it with the provided mapping.
		$mappingObject = new Mapping();
		$mappingObject->hydrate(object: $data['mapping']);

		return $this->mappingTrial(mapping: $mappingObject, input: $data['inputObject']);
	}//end test()

	/**
	 * Run a mapping on an input object and answer the result.
	 *
	 * @param Mapping $mapping The hydrated, unsaved mapping
	 * @param mixed   $input   The input object as sent
	 *
	 * @return JSONResponse The result object, or a 400 naming why the mapping failed
	 */
	private function mappingTrial(Mapping $mapping, mixed $input): JSONResponse {
		try {
			$resultObject = $this->mappingService->executeMapping(
				mapping: $mapping,
				input: $input
			);
		} catch (Exception $e) {
			// If mapping fails, return an error response.
			return new JSONResponse(
				data: [
					'error' => 'Mapping error',
					'message' => $e->getMessage(),
				],
				statusCode: 400
			);
		}

		return new JSONResponse(
			data: [
				'resultObject' => $resultObject,
				'success' => true,
			]
		);
	}//end mappingTrial()
}//end class
