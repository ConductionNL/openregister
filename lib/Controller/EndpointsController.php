<?php

/**
 * OpenRegister Endpoints Controller
 *
 * Controller for handling endpoint management operations.
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
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Controller\Support\EndpointsControllerServices;
use OCA\OpenRegister\Db\Endpoint;
use OCA\OpenRegister\Db\EndpointLogMapperInterface;
use OCA\OpenRegister\Db\EndpointLogScope;
use OCA\OpenRegister\Db\EndpointMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Service\EndpointServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * EndpointsController handles endpoint management operations
 *
 * Provides REST API endpoints for managing external API endpoints configuration.
 * Supports CRUD operations, endpoint testing, and log management.
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
 * @link https://www.OpenRegister.app
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) One public method per endpoint route.
 *
 * @spec openspec/specs/openapi-generation/spec.md
 *
 * The resource-CRUD verbs (index/show/create/update/patch/destroy) are governed by task-1.
 * The test/logs/logStats/allLogs methods are a production-observability cross-cut (endpoint
 * call logging) and are intentionally NOT annotated here — they belong to the
 * production-observability capability.
 */
class EndpointsController extends Controller {

	/**
	 * Endpoint mapper for database operations
	 *
	 * Handles CRUD operations for endpoint entities in the database.
	 *
	 * @var EndpointMapperInterface Endpoint mapper instance
	 */
	private readonly EndpointMapperInterface $endpointMapper;

	/**
	 * Endpoint service for business logic
	 *
	 * Handles endpoint testing and execution logic.
	 *
	 * @var EndpointServiceInterface Endpoint service instance
	 */
	private readonly EndpointServiceInterface $endpointService;

	/**
	 * Endpoint log mapper for log operations
	 *
	 * Handles database operations for endpoint execution logs.
	 *
	 * @var EndpointLogMapperInterface Endpoint log mapper instance
	 */
	private readonly EndpointLogMapperInterface $endpointLogMapper;

	/**
	 * Logger for error tracking and debugging
	 *
	 * Used to log errors, warnings, and informational messages.
	 *
	 * @var LoggerInterface Logger instance
	 */
	private readonly LoggerInterface $logger;

	/**
	 * The session bundle, which answers the admin check.
	 *
	 * @var ControllerSession
	 */
	private readonly ControllerSession $session;

	/**
	 * Constructor
	 *
	 * Initializes controller with required dependencies for endpoint management.
	 * Calls parent constructor to set up base controller functionality.
	 *
	 * @param IRequest $request HTTP request object
	 * @param EndpointsControllerServices $services The controller's collaborators.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		EndpointsControllerServices $services,
	) {
		// Call parent constructor to initialize base controller.
		parent::__construct(appName: Application::APP_ID, request: $request);

		// Store dependencies for use in controller methods.
		$this->endpointMapper = $services->endpointMapper;
		$this->endpointLogMapper = $services->endpointLogMapper;
		$this->endpointService = $services->endpointService;
		$this->logger = $services->session->logger;
		$this->session = $services->session;
	}//end __construct()

	/**
	 * Determine whether the current user is a Nextcloud admin.
	 *
	 * @return bool True when the active session user belongs to the admin group.
	 */
	private function isCurrentUserAdmin(): bool {
		return $this->session->isAdmin();
	}//end isCurrentUserAdmin()

	/**
	 * List all endpoints
	 *
	 * Retrieves all configured endpoints from the database and returns them
	 * as a JSON response with total count. Used for endpoint management UI.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse JSON response containing list of endpoints
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<200|500,
	 *     array{error?: 'Failed to list endpoints',
	 *     results?: array<\OCA\OpenRegister\Db\Endpoint>, total?: int<0, max>},
	 *     array<never, never>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		try {
			$endpoints = $this->endpointMapper->findAll();
			return new JSONResponse(data: ['results' => $endpoints, 'total' => count($endpoints)], statusCode: 200);
		} catch (\Exception $e) {
			$context = ['file' => __FILE__, 'line' => __LINE__, 'trace' => $e->getTraceAsString()];
			$this->logger->error(message: '[EndpointsController] Error listing endpoints: ' . $e->getMessage(), context: $context);
			return $this->serverError(error: 'Failed to list endpoints');
		}//end try
	}//end index()

	/**
	 * Get a single endpoint
	 *
	 * Retrieves endpoint details by ID from the database.
	 * Returns 404 if endpoint doesn't exist, 500 on database errors.
	 *
	 * @param int $id Endpoint ID to retrieve
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse JSON response containing endpoint details
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<200, \OCA\OpenRegister\Db\Endpoint,
	 *     array<never, never>>|JSONResponse<404|500,
	 *     array{error: 'Endpoint not found'|'Failed to retrieve endpoint'},
	 *     array<never, never>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(int $id): JSONResponse {
		try {
			return new JSONResponse(data: $this->endpointMapper->find($id));
		} catch (DoesNotExistException $e) {
			return $this->endpointNotFound();
		} catch (\Exception $e) {
			$context = ['file' => __FILE__, 'line' => __LINE__, 'id' => $id, 'trace' => $e->getTraceAsString()];
			$this->logger->error(message: '[EndpointsController] Error retrieving endpoint: ' . $e->getMessage(), context: $context);
			return $this->serverError(error: 'Failed to retrieve endpoint');
		}//end try
	}//end show()

	/**
	 * Create a new endpoint
	 *
	 * Creates a new endpoint configuration from request data.
	 * Validates required fields (name and endpoint path) before creation.
	 * Returns 201 Created on success, 400 Bad Request on validation failure.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with created endpoint or error
	 *
	 * @psalm-return JSONResponse<201, \OCA\OpenRegister\Db\Endpoint,
	 *     array<never, never>>|JSONResponse<400|403|500, array{error: string},
	 *     array<never, never>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function create(): JSONResponse {
		// SECURITY (H7): endpoint writes are admin-only.
		if ($this->isCurrentUserAdmin() === false) {
			return $this->adminRequired();
		}

		try {
			return $this->createEndpoint(data: $this->request->getParams());
		} catch (\Exception $e) {
			$context = ['file' => __FILE__, 'line' => __LINE__, 'data' => $this->request->getParams(), 'trace' => $e->getTraceAsString()];
			$this->logger->error(message: '[EndpointsController] Error creating endpoint: ' . $e->getMessage(), context: $context);
			return $this->serverError(error: 'Failed to create endpoint: ' . $e->getMessage());
		}//end try
	}//end create()

	/**
	 * Update an existing endpoint
	 *
	 * Updates endpoint configuration with data from request.
	 * Removes ID from update data to prevent ID modification.
	 * Returns 404 if endpoint doesn't exist, 500 on database errors.
	 *
	 * @param int $id Endpoint ID to update
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with updated endpoint or error
	 *
	 * @psalm-return JSONResponse<200, \OCA\OpenRegister\Db\Endpoint,
	 *     array<never, never>>|JSONResponse<403|404|500, array{error: string},
	 *     array<never, never>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function update(int $id): JSONResponse {
		// SECURITY (H7): endpoint writes are admin-only.
		if ($this->isCurrentUserAdmin() === false) {
			return $this->adminRequired();
		}

		try {
			return $this->updateEndpoint(id: $id);
		} catch (DoesNotExistException $e) {
			return $this->endpointNotFound();
		} catch (\Exception $e) {
			$context = ['file' => __FILE__, 'line' => __LINE__, 'id' => $id, 'data' => $this->request->getParams(), 'trace' => $e->getTraceAsString()];
			$this->logger->error(message: '[EndpointsController] Error updating endpoint: ' . $e->getMessage(), context: $context);
			return $this->serverError(error: 'Failed to update endpoint: ' . $e->getMessage());
		}//end try
	}//end update()

	/**
	 * Delete an endpoint
	 *
	 * Deletes endpoint configuration from database by ID.
	 * Returns 204 No Content on success, 404 if endpoint doesn't exist.
	 *
	 * @param int $id Endpoint ID to delete
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse JSON response containing deletion result
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<204, null,
	 *     array<never, never>>|JSONResponse<404|500,
	 *     array{error: 'Endpoint not found'|'Failed to delete endpoint'},
	 *     array<never, never>>
	 */

	/**
	 * Partially update an endpoint by ID.
	 *
	 * The route `endpoints#patch` (`PATCH /api/endpoints/{id}`) delegates to
	 * the same body as `update()` because Nextcloud routes PATCH and PUT to
	 * separate methods even when the handler is identical. Mirrors the same
	 * shape used on `ApplicationsController::patch`.
	 *
	 * @param int $id The ID of the endpoint to patch.
	 *
	 * @return JSONResponse JSON response containing patched endpoint.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function patch(int $id): JSONResponse {
		return $this->update(id: $id);
	}//end patch()

	/**
	 * Delete an endpoint by ID.
	 *
	 * @param int $id The endpoint ID to delete.
	 *
	 * @return JSONResponse Empty response on success or error response on failure.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function destroy(int $id): JSONResponse {
		// SECURITY (H7): endpoint writes are admin-only.
		if ($this->isCurrentUserAdmin() === false) {
			return $this->adminRequired();
		}

		try {
			return $this->deleteEndpoint(id: $id);
		} catch (DoesNotExistException $e) {
			return $this->endpointNotFound();
		} catch (\Exception $e) {
			$context = ['file' => __FILE__, 'line' => __LINE__, 'id' => $id, 'trace' => $e->getTraceAsString()];
			$this->logger->error(message: '[EndpointsController] Error deleting endpoint: ' . $e->getMessage(), context: $context);
			return $this->serverError(error: 'Failed to delete endpoint');
		}//end try
	}//end destroy()

	/**
	 * Test an endpoint by executing it with test data
	 *
	 * Executes endpoint with optional test data to verify endpoint configuration.
	 * Returns execution result including status code and response data.
	 * Used for endpoint validation and debugging.
	 *
	 * @param int $id Endpoint ID to test
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-suppress InvalidReturnType
	 * @psalm-suppress InvalidReturnStatement
	 * @psalm-suppress InvalidTemplateParam The status code is the int echoed back from the
	 *     tested endpoint's response (`$result['statusCode']`), so it is genuinely the
	 *     full HTTP range — narrowing it would be a lie. The narrow-or-baseline
	 *     guidance from #1960 lands here on the "baseline" side; we use a focused
	 *     `@psalm-suppress` annotation to keep the noise scoped to this one method.
	 *
	 * @return JSONResponse JSON response with test result or error
	 *
	 * @psalm-return JSONResponse<int,
	 *     array{error?: string, success?: bool, message?: string,
	 *     statusCode?: int, response?: mixed},
	 *     array<never, never>>
	 *
	 * @spec openspec/specs/openapi-generation/spec.md#requirement-schema-authoring-sub-resources-and-meta-entity-operational-endpoints
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function test(int $id): JSONResponse {
		try {
			$endpoint = $this->endpointMapper->find($id);
			// Optional sample payload to run the endpoint with.
			$testData = $this->request->getParams()['data'] ?? [];
			return $this->testAnswer(result: $this->endpointService->testEndpoint(endpoint: $endpoint, testData: $testData));
		} catch (DoesNotExistException $e) {
			return $this->endpointNotFound();
		} catch (\Exception $e) {
			$context = ['file' => __FILE__, 'line' => __LINE__, 'id' => $id, 'trace' => $e->getTraceAsString()];
			$this->logger->error(message: '[EndpointsController] Error testing endpoint: ' . $e->getMessage(), context: $context);
			return $this->serverError(error: 'Failed to test endpoint: ' . $e->getMessage());
		}//end try
	}//end test()

	/**
	 * Get logs for a specific endpoint
	 *
	 * Retrieves execution logs for a specific endpoint with pagination support.
	 * Validates endpoint exists before retrieving logs.
	 * Returns paginated log entries with total count.
	 *
	 * @param int $id Endpoint ID to retrieve logs for
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse JSON response containing endpoint logs
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<200|404|500,
	 *     array{error?: 'Endpoint not found'|'Failed to retrieve endpoint logs',
	 *     results?: list<\OCA\OpenRegister\Db\EndpointLog>, total?: int<0, max>},
	 *     array<never, never>>
	 *
	 * @spec openspec/specs/production-observability/spec.md#requirement-per-entity-statistics-and-endpoint-delivery-log-api
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function logs(int $id): JSONResponse {
		try {
			// Throws DoesNotExistException when the endpoint does not exist.
			$this->endpointMapper->find($id);
			[$limit, $offset] = $this->page();
			$logs = $this->endpointLogMapper->findByEndpoint(endpointId: $id, limit: $limit, offset: $offset);
			return new JSONResponse(data: ['results' => $logs, 'total' => count($logs)], statusCode: 200);
		} catch (DoesNotExistException $e) {
			return $this->endpointNotFound();
		} catch (\Exception $e) {
			$context = ['file' => __FILE__, 'line' => __LINE__, 'id' => $id, 'trace' => $e->getTraceAsString()];
			$this->logger->error(message: '[EndpointsController] Error retrieving endpoint logs: ' . $e->getMessage(), context: $context);
			return $this->serverError(error: 'Failed to retrieve endpoint logs');
		}//end try
	}//end logs()

	/**
	 * Get statistics for a specific endpoint
	 *
	 * Retrieves aggregated statistics for endpoint execution logs.
	 * Includes metrics like total requests, success rate, average response time, etc.
	 * Validates endpoint exists before calculating statistics.
	 *
	 * @param int $id Endpoint ID to retrieve statistics for
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with endpoint log statistics
	 *
	 * @psalm-return JSONResponse<200|404|500,
	 *     array{error?: 'Endpoint not found'|
	 *     'Failed to retrieve endpoint log statistics', total?: int,
	 *     success?: int, failed?: int}, array<never, never>>
	 *
	 * @spec openspec/specs/production-observability/spec.md#requirement-per-entity-statistics-and-endpoint-delivery-log-api
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function logStats(int $id): JSONResponse {
		try {
			// Throws DoesNotExistException when the endpoint does not exist.
			$this->endpointMapper->find($id);
			return new JSONResponse(data: $this->endpointLogMapper->getStatistics($id));
		} catch (DoesNotExistException $e) {
			return $this->endpointNotFound();
		} catch (\Exception $e) {
			$context = ['file' => __FILE__, 'line' => __LINE__, 'id' => $id, 'trace' => $e->getTraceAsString()];
			$this->logger->error(message: '[EndpointsController] Error retrieving endpoint log statistics: ' . $e->getMessage(), context: $context);
			return $this->serverError(error: 'Failed to retrieve endpoint log statistics');
		}//end try
	}//end logStats()

	/**
	 * Get all endpoint logs with optional filtering
	 *
	 * Retrieves endpoint execution logs with optional filtering by endpoint ID.
	 * Supports pagination via limit and offset parameters.
	 * Returns logs for specific endpoint if endpoint_id provided, otherwise all logs.
	 * A non-admin only sees logs of endpoints visible to them (the organisation-scoped
	 * endpoint list); an endpoint_id outside that list yields an empty result.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with logs or error
	 *
	 * @psalm-return JSONResponse<200|500,
	 *     array{error?: string, results?: array<\OCA\OpenRegister\Db\EndpointLog>,
	 *     total?: int<0, max>},
	 *     array<never, never>>
	 *
	 * @spec openspec/specs/production-observability/spec.md#requirement-per-entity-statistics-and-endpoint-delivery-log-api
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function allLogs(): JSONResponse {
		try {
			$endpointId = $this->request->getParam('endpoint_id');
			if ($endpointId === null || $endpointId === '' || $endpointId === '0') {
				return $this->logPage(endpointId: null);
			}

			return $this->logPage(endpointId: (int)$endpointId);
		} catch (\Exception $e) {
			$context = ['file' => __FILE__, 'line' => __LINE__, 'trace' => $e->getTraceAsString()];
			$this->logger->error(message: '[EndpointsController] Error retrieving endpoint logs: ' . $e->getMessage(), context: $context);
			return $this->serverError(error: 'Failed to retrieve endpoint logs: ' . $e->getMessage());
		}//end try
	}//end allLogs()
	/**
	 * The answer for an endpoint that does not exist.
	 *
	 * @return JSONResponse A 404.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
	 */
	private function endpointNotFound(): JSONResponse {
		return new JSONResponse(data: ['error' => 'Endpoint not found'], statusCode: 404);
	}//end endpointNotFound()

	/**
	 * The answer for an action that failed.
	 *
	 * @param string $error The error text.
	 *
	 * @return JSONResponse A 500.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
	 */
	private function serverError(string $error): JSONResponse {
		return new JSONResponse(data: ['error' => $error], statusCode: 500);
	}//end serverError()
	/**
	 * The answer for a caller who is not an administrator.
	 *
	 * @return JSONResponse A 403.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
	 */
	private function adminRequired(): JSONResponse {
		return new JSONResponse(data: ['error' => 'Admin privileges required'], statusCode: 403);
	}//end adminRequired()

	/**
	 * Create an endpoint from request data that names it and its path.
	 *
	 * @param array<string, mixed> $data The request parameters.
	 *
	 * @return JSONResponse The created endpoint (201), or a 400 without a name or path.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
	 */
	private function createEndpoint(array $data): JSONResponse {
		if (empty($data['name']) === true || empty($data['endpoint']) === true) {
			return new JSONResponse(data: ['error' => 'Name and endpoint path are required'], statusCode: 400);
		}

		$endpoint = $this->endpointMapper->createFromArray($data);
		$context = ['file' => __FILE__, 'line' => __LINE__, 'id' => $endpoint->getId(), 'name' => $endpoint->getName(), 'path' => $endpoint->getEndpoint()];
		$this->logger->info(message: '[EndpointsController] Endpoint created', context: $context);
		return new JSONResponse(data: $endpoint, statusCode: 201);
	}//end createEndpoint()

	/**
	 * Update an endpoint from the request data; the id comes from the route, never the body.
	 *
	 * @param int $id The endpoint.
	 *
	 * @return JSONResponse The updated endpoint.
	 *
	 * @throws DoesNotExistException When no endpoint has this id; the caller answers 404.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
	 */
	private function updateEndpoint(int $id): JSONResponse {
		$data = $this->request->getParams();
		unset($data['id']);
		$endpoint = $this->endpointMapper->updateFromArray(id: $id, data: $data);
		$context = ['file' => __FILE__, 'line' => __LINE__, 'id' => $endpoint->getId(), 'name' => $endpoint->getName()];
		$this->logger->info(message: '[EndpointsController] Endpoint updated', context: $context);
		return new JSONResponse(data: $endpoint);
	}//end updateEndpoint()

	/**
	 * Delete an endpoint.
	 *
	 * @param int $id The endpoint.
	 *
	 * @return JSONResponse A 204.
	 *
	 * @throws DoesNotExistException When no endpoint has this id; the caller answers 404.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
	 */
	private function deleteEndpoint(int $id): JSONResponse {
		$endpoint = $this->endpointMapper->find($id);
		$this->endpointMapper->delete($endpoint);
		$context = ['file' => __FILE__, 'line' => __LINE__, 'id' => $endpoint->getId(), 'name' => $endpoint->getName()];
		$this->logger->info(message: '[EndpointsController] Endpoint deleted', context: $context);
		return new JSONResponse(data: null, statusCode: 204);
	}//end deleteEndpoint()

	/**
	 * The answer for a test run: its response on success, else its error with the status it got.
	 *
	 * @param array<string, mixed> $result The test run, as EndpointService::testEndpoint() reports it.
	 *
	 * @return JSONResponse The answer.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
	 */
	private function testAnswer(array $result): JSONResponse {
		$status = $result['statusCode'];
		if ($result['success'] === true) {
			$message = 'Test endpoint executed successfully';
			return new JSONResponse(data: ['success' => true, 'message' => $message, 'statusCode' => $status, 'response' => $result['response']]);
		}

		$message = $result['error'] ?? 'Test endpoint execution failed';
		return new JSONResponse(data: ['success' => false, 'message' => $message, 'statusCode' => $status], statusCode: $status);
	}//end testAnswer()

	/**
	 * The requested page of logs, with the limit (default 50) and offset (default 0) from the request.
	 *
	 * @return array{0: int, 1: int} The limit and offset.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
	 */
	private function page(): array {
		return [(int)($this->request->getParam('limit') ?? 50), (int)($this->request->getParam('offset') ?? 0)];
	}//end page()

	/**
	 * One page of logs with the total count, of one endpoint or of all, limited to visible endpoints for a non-admin.
	 *
	 * @param int|null $endpointId The endpoint, or null for all endpoints.
	 *
	 * @return JSONResponse The logs and their total.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-7
	 */
	private function logPage(?int $endpointId): JSONResponse {
		[$limit, $offset] = $this->page();
		if ($this->isCurrentUserAdmin() === false) {
			$visibleIds = array_map(static fn (Endpoint $endpoint): int => (int)$endpoint->getId(), $this->endpointMapper->findAll());
			[$logs, $total] = $this->endpointLogMapper->findPageWithin(
				scope: new EndpointLogScope(visibleIds: $visibleIds, endpointId: $endpointId),
				query: new ListQuery(limit: $limit, offset: $offset)
			);
			return new JSONResponse(data: ['results' => $logs, 'total' => $total], statusCode: 200);
		}

		if ($endpointId === null) {
			$logs = $this->endpointLogMapper->findAll(limit: $limit, offset: $offset);
			$total = count($this->endpointLogMapper->findAll(limit: null, offset: null));
			return new JSONResponse(data: ['results' => $logs, 'total' => $total], statusCode: 200);
		}

		$logs = $this->endpointLogMapper->findByEndpoint(endpointId: $endpointId, limit: $limit, offset: $offset);
		$total = count($this->endpointLogMapper->findByEndpoint(endpointId: $endpointId, limit: null, offset: null));
		return new JSONResponse(data: ['results' => $logs, 'total' => $total], statusCode: 200);
	}//end logPage()
}//end class
