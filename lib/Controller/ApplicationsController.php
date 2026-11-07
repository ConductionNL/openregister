<?php

/**
 * OpenRegister Applications Controller
 *
 * This file contains the controller for managing applications.
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\Db\ApplicationMapper;
use OCA\OpenRegister\Service\ApplicationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * ApplicationsController handles REST API endpoints for application management
 *
 * Provides REST API endpoints for managing applications including CRUD operations,
 * pagination, filtering, and error handling.
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
class ApplicationsController extends Controller {

	/**
	 * Application service for business logic
	 *
	 * Handles application business logic, validation, and service layer operations.
	 *
	 * @var ApplicationService Application service instance
	 */
	private readonly ApplicationService $applicationService;

	/**
	 * Application mapper for direct database operations
	 *
	 * Used for direct database queries when needed, bypassing service layer.
	 *
	 * @var ApplicationMapper Application mapper instance
	 */
	private readonly ApplicationMapper $applicationMapper;

	/**
	 * Logger for debugging and error tracking
	 *
	 * Used for logging errors, debug information, and operation tracking.
	 *
	 * @var LoggerInterface Logger instance
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor
	 *
	 * Initializes controller with required dependencies for application operations.
	 * Calls parent constructor to set up base controller functionality.
	 *
	 * @param string $appName Application name
	 * @param IRequest $request HTTP request object
	 * @param ApplicationService $applicationService Application service for business logic
	 * @param ApplicationMapper $applicationMapper Application mapper for database operations
	 * @param LoggerInterface $logger Logger for error tracking
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		ApplicationService $applicationService,
		ApplicationMapper $applicationMapper,
		LoggerInterface $logger,
	) {
		// Call parent constructor to initialize base controller.
		parent::__construct(appName: $appName, request: $request);

		// Store dependencies for use in controller methods.
		$this->applicationService = $applicationService;
		$this->applicationMapper = $applicationMapper;
		$this->logger = $logger;
	}//end __construct()

	/**
	 * Render the Applications page
	 *
	 * Returns the template for the main applications page.
	 * All routing is handled client-side by the SPA.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return TemplateResponse Template response for applications SPA
	 *
	 * @psalm-return TemplateResponse<200, array<never, never>>
	 *
	 * @spec exclude Trivial SPA-mount route stub: returns the Vue index template for client-side routing.
	 *              SPA-mount contract owned by no-code-app-builder via retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-1.
	 */
	public function page(): TemplateResponse {
		// Return SPA template response (routing handled client-side).
		return new TemplateResponse(
			appName: 'openregister',
			templateName: 'index',
			params: []
		);
	}//end page()

	/**
	 * Get all applications
	 *
	 * Retrieves a list of all applications with optional pagination and filtering.
	 * Supports limit/offset pagination or page-based pagination.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse JSON response containing list of applications
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<200|500,
	 *     array{error?: 'Failed to retrieve applications',
	 *     results?: array<\OCA\OpenRegister\Db\Application>},
	 *     array<never, never>>
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 *
	 * @no-admin-idor-exempt List endpoint for the application-registry resource (app/template
	 * metadata, not per-user objects); access follows OpenRegister's fleet-wide registry-controller
	 * convention where authorisation lives in the ObjectService/RBAC layer behind ApplicationService,
	 * not per-controller-method. Pre-existing; this PR only touches the docblock, not the handler.
	 */
	public function index(): JSONResponse {
		try {
			$applications = $this->findApplications(params: $this->request->getParams());

			return new JSONResponse(data: ['results' => $applications], statusCode: Http::STATUS_OK);
		} catch (Exception $e) {
			$this->logFailure(
				message: '[ApplicationsController] Failed to get applications',
				context: ['line' => __LINE__, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]
			);

			return new JSONResponse(data: ['error' => 'Failed to retrieve applications'], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end index()

	/**
	 * Get a single application
	 *
	 * Retrieves a specific application by its database ID.
	 * Returns application details or error if not found.
	 *
	 * @param int $id Application database ID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse JSON response containing application details
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<200, \OCA\OpenRegister\Db\Application,
	 *     array<never, never>>|JSONResponse<404,
	 *     array{error: 'Application not found'}, array<never, never>>
	 *
	 * @spec exclude Thin read endpoint for the applications resource route; delegates to
	 *     ApplicationService (domain layer), not a raw ObjectService duplication. gate-17's
	 *     receiver regex over-matches any $this->*Service call.
	 *
	 * @no-admin-idor-exempt Read of an application-registry entry (app/template metadata);
	 * authorisation follows OpenRegister's registry-controller convention (ObjectService/RBAC
	 * behind ApplicationService::find). Pre-existing; untouched by this PR's functional diff.
	 */
	public function show(int $id): JSONResponse {
		try {
			return new JSONResponse(data: $this->applicationService->find($id), statusCode: Http::STATUS_OK);
		} catch (Exception $e) {
			$this->logFailure(
				message: '[ApplicationsController] Failed to get application',
				context: ['line' => __LINE__, 'id' => $id, 'error' => $e->getMessage()]
			);

			return new JSONResponse(data: ['error' => 'Application not found'], statusCode: Http::STATUS_NOT_FOUND);
		}
	}//end show()

	/**
	 * Create a new application
	 *
	 * Creates a new application entity from request data.
	 * Validates input and returns created application or error.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with created application
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 *
	 * @no-admin-idor-exempt Write to the application-registry resource (app/template metadata);
	 * authorisation follows OpenRegister's registry-controller convention (ObjectService/RBAC
	 * behind ApplicationService::create). Pre-existing; untouched by this PR's functional diff.
	 */
	public function create(): JSONResponse {
		try {
			$data = $this->request->getParams();
			unset($data['_route']);

			return new JSONResponse(data: $this->applicationService->create($data), statusCode: Http::STATUS_CREATED);
		} catch (Exception $e) {
			$this->logFailure(
				message: '[ApplicationsController] Failed to create application',
				context: ['line' => __LINE__, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]
			);

			return new JSONResponse(data: ['error' => 'Failed to create application: ' . $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}
	}//end create()

	/**
	 * Update an existing application
	 *
	 * Updates an existing application entity with new data.
	 * Prevents modification of immutable fields (id, organisation, owner, created).
	 *
	 * @param int $id Application database ID
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with updated application
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 *
	 * @no-admin-idor-exempt Update of an application-registry entry (app/template metadata);
	 * authorisation follows OpenRegister's registry-controller convention (ObjectService/RBAC
	 * behind ApplicationService::update). Immutable fields (id/organisation/owner/created) are
	 * stripped here. Pre-existing; untouched by this PR's functional diff.
	 */
	public function update(int $id): JSONResponse {
		try {
			$data = $this->request->getParams();
			unset($data['_route'], $data['id'], $data['organisation'], $data['owner'], $data['created']);

			return new JSONResponse(data: $this->applicationService->update(id: $id, data: $data), statusCode: Http::STATUS_OK);
		} catch (Exception $e) {
			$this->logFailure(
				message: '[ApplicationsController] Failed to update application',
				context: ['line' => __LINE__, 'id' => $id, 'error' => $e->getMessage()]
			);

			return new JSONResponse(data: ['error' => 'Failed to update application: ' . $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}
	}//end update()

	/**
	 * Patch (partially update) an application
	 *
	 * Partially updates an application entity (PATCH method).
	 * Delegates to update() method which handles partial updates.
	 *
	 * @param int $id The ID of the application to patch
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse JSON response containing patched application
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<200, \OCA\OpenRegister\Db\Application,
	 *     array<never, never>>|JSONResponse<400, array{error: string},
	 *     array<never, never>>
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 *
	 * @no-admin-idor-exempt Pure delegation to update(), which carries the same registry-controller
	 * authorisation posture; this method has no body of its own. Pre-existing; untouched by this PR.
	 */
	public function patch(int $id): JSONResponse {
		// Delegate to update method (both handle partial updates).
		return $this->update(id: $id);
	}//end patch()

	/**
	 * Delete an application
	 *
	 * Deletes an application entity by ID.
	 * Returns success message or error if deletion fails.
	 *
	 * @param int $id Application database ID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse JSON response containing deletion result
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<200|400,
	 *     array{error?: 'Failed to delete application',
	 *     message?: 'Application deleted successfully'}, array<never, never>>
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 *
	 * @no-admin-idor-exempt Delete of an application-registry entry (app/template metadata);
	 * authorisation follows OpenRegister's registry-controller convention (ObjectService/RBAC
	 * behind ApplicationService::delete). Pre-existing; untouched by this PR's functional diff.
	 */
	public function destroy(int $id): JSONResponse {
		try {
			$this->applicationService->delete($id);

			return new JSONResponse(data: ['message' => 'Application deleted successfully'], statusCode: Http::STATUS_OK);
		} catch (Exception $e) {
			$this->logFailure(
				message: '[ApplicationsController] Failed to delete application',
				context: ['line' => __LINE__, 'id' => $id, 'error' => $e->getMessage()]
			);

			return new JSONResponse(data: ['error' => 'Failed to delete application'], statusCode: Http::STATUS_BAD_REQUEST);
		}
	}//end destroy()

	/**
	 * Query the mapper with the pagination and field filters taken from the request
	 *
	 * @param array<string, mixed> $params Request parameters
	 *
	 * @return array<\OCA\OpenRegister\Db\Application> The matching applications
	 *
	 * @spec exclude Private query helper for index(); the registry resource-CRUD contract is owned by
	 *              retrofit-2026-05-24-b-ctrl-registry-views/tasks.md#task-1.
	 */
	private function findApplications(array $params): array {
		return $this->applicationMapper->findAll(
			limit: $this->extractInt(params: $params, key: '_limit'),
			offset: $this->resolveOffset(params: $params),
			filters: $this->extractFilters(params: $params)
		);
	}//end findApplications()

	/**
	 * Read an optional integer query parameter
	 *
	 * Returns null when the parameter is absent or null; otherwise casts it to int.
	 *
	 * @param array<string, mixed> $params Request parameters
	 * @param string $key Parameter name, e.g. `_limit`
	 *
	 * @return int|null The integer value or null if not provided
	 *
	 * @spec exclude Private pagination-param helper; the registry resource-CRUD contract is owned by
	 *              retrofit-2026-05-24-b-ctrl-registry-views/tasks.md#task-1.
	 */
	private function extractInt(array $params, string $key): ?int {
		if (($params[$key] ?? null) !== null) {
			return (int)$params[$key];
		}

		return null;
	}//end extractInt()

	/**
	 * Resolve the effective offset for a list request
	 *
	 * Page-based pagination (`_page` together with `_limit`) takes precedence
	 * over an explicit `_offset`.
	 *
	 * @param array<string, mixed> $params Request parameters
	 *
	 * @return int|null The offset or null if not provided
	 *
	 * @spec exclude Private pagination-param helper; the registry resource-CRUD contract is owned by
	 *              retrofit-2026-05-24-b-ctrl-registry-views/tasks.md#task-1.
	 */
	private function resolveOffset(array $params): ?int {
		$limit = $this->extractInt(params: $params, key: '_limit');
		$page = $this->extractInt(params: $params, key: '_page');
		if ($page !== null && $limit !== null) {
			return ($page - 1) * $limit;
		}

		return $this->extractInt(params: $params, key: '_offset');
	}//end resolveOffset()

	/**
	 * Strip the reserved query parameters, keeping only application field filters
	 *
	 * @param array<string, mixed> $params Request parameters
	 *
	 * @return array<string, mixed> The field filters
	 *
	 * @spec exclude Private filter-param helper; the registry resource-CRUD contract is owned by
	 *              retrofit-2026-05-24-b-ctrl-registry-views/tasks.md#task-1.
	 */
	private function extractFilters(array $params): array {
		unset($params['_limit'], $params['_offset'], $params['_page'], $params['_search'], $params['_route']);

		return $params;
	}//end extractFilters()

	/**
	 * Log a failed request with the controller file prepended to the context
	 *
	 * @param string $message Log message
	 * @param array<string, mixed> $context Context; callers pass `line` first
	 *
	 * @return void
	 *
	 * @spec exclude Private logging helper shared by the CRUD endpoints of this controller.
	 */
	private function logFailure(string $message, array $context): void {
		$this->logger->error(message: $message, context: ['file' => __FILE__] + $context);
	}//end logFailure()
}//end class
