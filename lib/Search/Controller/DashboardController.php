<?php

/**
 * OpenConnector Dashboard Controller
 *
 * This file contains the controller for handling dashboard related operations
 * in the OpenRegister application.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

namespace OCA\OpenRegister\Search\Controller;

use DateTime;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Search\Service\ActivityWindow;
use OCA\OpenRegister\Search\Service\DashboardScope;
use OCA\OpenRegister\Search\Service\DashboardService;
use OCA\OpenRegister\Search\Controller\Support\DashboardControllerServices;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

/**
 * DashboardController handles dashboard related operations
 *
 * Controller for handling dashboard related operations in the application.
 * Provides functionality to display the dashboard page and retrieve dashboard data
 * including registers, schemas, and statistics.
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
 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-graphql-rt-dash/tasks.md#task-5
 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-graphql-rt-dash/tasks.md#task-6
 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-graphql-rt-dash/tasks.md#task-7
 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-graphql-rt-dash/tasks.md#task-8
 *
 * @psalm-suppress UnusedClass
 */
class DashboardController extends Controller {

	/**
	 * The dashboard service instance
	 *
	 * Handles business logic for dashboard data retrieval and aggregation.
	 *
	 * @var DashboardService Dashboard service instance
	 */
	private readonly DashboardService $dashboardService;

	/**
	 * Session user, group manager (admin checks) and logger.
	 *
	 * @var ControllerSession
	 */
	private readonly ControllerSession $session;

	/**
	 * Constructor.
	 *
	 * @param IRequest                    $request  The current request.
	 * @param DashboardControllerServices $services The dashboard API services.
	 */
	public function __construct(IRequest $request, DashboardControllerServices $services) {
		parent::__construct(appName: Application::APP_ID, request: $request);

		$this->dashboardService = $services->dashboardService;
		$this->session          = $services->session;
	}//end __construct()

	/**
	 * Check whether the currently authenticated user is a Nextcloud administrator.
	 *
	 * The dashboard analytics aggregate object counts, storage sizes and audit
	 * activity across ALL registers/schemas (tenant-wide, no per-register
	 * scoping). Returning them to a non-admin leaks cross-tenant data, so these
	 * endpoints are admin-only — consistent with the SearchTrail/AuditTrail
	 * analytics posture.
	 *
	 * @return bool True if a user is signed in and belongs to the admin group.
	 */
	private function isCurrentUserAdmin(): bool {
		return $this->session->isAdmin();
	}//end isCurrentUserAdmin()

	/**
	 * Returns the template of the dashboard page
	 *
	 * Renders the dashboard page template with Content Security Policy configured
	 * to allow API connections. Returns error template if rendering fails.
	 *
	 * @return TemplateResponse The rendered template response (or error template on failure)
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return TemplateResponse<200, array<never, never>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-graphql-rt-dash/tasks.md#task-5
	 */
	public function page(): TemplateResponse {
		try {
			// Create template response for dashboard page.
			$response = new TemplateResponse(
				appName: $this->appName,
				templateName: 'index',
				params: []
			);

			// Configure Content Security Policy to allow API connections.
			// This is necessary for the frontend to make API calls.
			$csp = new ContentSecurityPolicy();
			$csp->addAllowedConnectDomain('*');
			$response->setContentSecurityPolicy($csp);

			// Return successful template response.
			return $response;
		} catch (\Exception $e) {
			// Return error template if rendering fails.
			return new TemplateResponse(
				appName: $this->appName,
				templateName: 'error',
				params: ['error' => $e->getMessage()],
				renderAs: '500'
			);
		}//end try
	}//end page()

	/**
	 * Serve the SPA for deep links (Vue history mode). Delegates to {@see page()}.
	 *
	 * THIS IS THE FIX FOR #133. This app registered exactly one frontend route —
	 * `dashboard#page` at `/` — and nothing that served the SPA shell for a deep
	 * sub-path like /registers or /schemas. A bookmark or full-page load to one
	 * of those therefore never reached the SPA, the router resolved the base
	 * path instead, and the grouped index pages rendered empty (no Add button,
	 * no list). The app was moved back to hash routing to work around it; with
	 * this route the workaround is no longer needed.
	 *
	 * ⚠️ Probing an enumerated path does NOT prove a catch-all exists — probe a
	 * nonsense one. /apps/openregister/zzz-nonsense returned 404 before this and
	 * 401 after.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return TemplateResponse
	 *
	 * @spec exclude Vue history-mode fallback — delegates to page(); pure framework plumbing, no domain logic.
	 */
	public function catchAll(): TemplateResponse {
		return $this->page();
	}//end catchAll()

	/**
	 * Retrieves dashboard data including registers with their schemas
	 *
	 * Returns JSON response containing dashboard data with registers and schemas.
	 * Supports optional filtering by registerId and schemaId query parameters.
	 * Removes pagination and routing parameters before processing.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse The JSON response containing registers with schemas
	 *
	 * @psalm-return JSONResponse<
	 *     200|403|500,
	 *     array{
	 *         error?: string,
	 *         registers?: list<array{
	 *             id: 'orphaned'|'totals'|int,
	 *             uuid?: null|string,
	 *             slug?: null|string,
	 *             title: null|string,
	 *             version?: null|string,
	 *             description: null|string,
	 *             schemas: list<array{
	 *                 id: int,
	 *                 uuid: null|string,
	 *                 slug: null|string,
	 *                 title: null|string,
	 *                 version: null|string,
	 *                 description: null|string,
	 *                 summary: null|string,
	 *                 icon: null|string,
	 *                 source: null|string,
	 *                 uri: null|string,
	 *                 properties: array,
	 *                 required: array,
	 *                 allOf: array|null,
	 *                 anyOf: array|null,
	 *                 oneOf: array|null,
	 *                 archive: array|null,
	 *                 authorization: array|null,
	 *                 configuration: array|null|string,
	 *                 groups: array<string, list<string>>|null,
	 *                 hardValidation: bool,
	 *                 immutable: bool,
	 *                 maxDepth: int,
	 *                 searchable: bool,
	 *                 application: null|string,
	 *                 organisation: null|string,
	 *                 owner: null|string,
	 *                 created: null|string,
	 *                 updated: null|string,
	 *                 deleted: null|string,
	 *                 published: null|string,
	 *                 depublished: null|string,
	 *                 stats: array{
	 *                     objects: array{
	 *                         total: int,
	 *                         size: int,
	 *                         invalid: int,
	 *                         deleted: int,
	 *                         locked: int,
	 *                         published: int
	 *                     },
	 *                     logs: array{total: int, size: int},
	 *                     files: array{total: int, size: int},
	 *                     webhookLogs: array{total: int, size: int}
	 *                 }
	 *             }>,
	 *             source?: null|string,
	 *             tablePrefix?: null|string,
	 *             folder?: null|string,
	 *             updated?: null|string,
	 *             created?: null|string,
	 *             owner?: null|string,
	 *             application?: null|string,
	 *             organisation?: null|string,
	 *             authorization?: array|null,
	 *             groups?: array<string, list<string>>,
	 *             configuration?: array|null,
	 *             quota?: array{
	 *                 storage: null,
	 *                 bandwidth: null,
	 *                 requests: null,
	 *                 users: null,
	 *                 groups: null
	 *             },
	 *             usage?: array{
	 *                 storage: 0,
	 *                 bandwidth: 0,
	 *                 requests: 0,
	 *                 users: 0,
	 *                 groups: int<0, max>
	 *             },
	 *             deleted?: null|string,
	 *             published?: null|string,
	 *             depublished?: null|string,
	 *             stats: array{
	 *                 objects: array{
	 *                     total: int,
	 *                     size: int,
	 *                     invalid: int,
	 *                     deleted: int,
	 *                     locked: int,
	 *                     published: int
	 *                 },
	 *                 logs: array{total: int|mixed, size: int|mixed},
	 *                 files: array{total: int, size: int},
	 *                 webhookLogs?: array{total: int, size: int}
	 *             }
	 *         }>
	 *     },
	 *     array<never, never>
	 * >
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-graphql-rt-dash/tasks.md#task-6
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		if ($this->isCurrentUserAdmin() === false) {
			return new JSONResponse(['error' => 'Admin privileges required'], 403);
		}

		try {
			// Get all request parameters.
			$params = $this->request->getParams();

			// Remove pagination and routing parameters that shouldn't be passed to service.
			// These are handled by the controller, not the business logic layer.
			unset($params['id'], $params['_route'], $params['limit'], $params['offset'], $params['page']);

			// Retrieve registers with schemas from dashboard service.
			// Optional filtering by registerId and schemaId if provided in query parameters.
			$registers = $this->dashboardService->getRegistersWithSchemas(
				registerId: $params['registerId'] ?? null,
				schemaId: $params['schemaId'] ?? null
			);

			// Return successful response with registers data.
			return new JSONResponse(data: ['registers' => $registers]);
		} catch (\Exception $e) {
			// Return error response if dashboard data retrieval fails.
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
		}//end try
	}//end index()

	/**
	 * Calculate sizes for objects and logs
	 *
	 * Calculates storage sizes and statistics for objects and logs.
	 * Supports optional filtering by registerId and schemaId to calculate
	 * sizes for specific subsets of data.
	 *
	 * @param int|null $registerId Optional register ID to filter calculations by
	 * @param int|null $schemaId Optional schema ID to filter calculations by
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with calculation results
	 *
	 * @psalm-return JSONResponse<200|403|500,
	 *     array{error: string}|array{status: 'error'|'success', message?: string, timestamp: string,
	 *     scope?: array{register: array{id: int, title: null|string}|null,
	 *     schema: array{id: int, title: null|string}|null},
	 *     results?: array{objects: array, logs: array,
	 *     total: array{processed: mixed, failed: mixed}},
	 *     summary?: array{total_processed: mixed, total_failed: mixed,
	 *     success_rate: float}}, array<never, never>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-graphql-rt-dash/tasks.md#task-7
	 */
	public function calculate(?int $registerId = null, ?int $schemaId = null): JSONResponse {
		if ($this->isCurrentUserAdmin() === false) {
			return new JSONResponse(['error' => 'Admin privileges required'], 403);
		}

		try {
			// Calculate sizes and statistics using dashboard service.
			// Service handles aggregation of object and log sizes.
			$result = $this->dashboardService->sizes()->calculate(registerId: $registerId, schemaId: $schemaId);

			// Return successful response with calculation results.
			return new JSONResponse(data: $result);
		} catch (\Exception $e) {
			// Return error response with timestamp for debugging.
			return new JSONResponse(
				data: [
					'status' => 'error',
					'message' => $e->getMessage(),
					'timestamp' => (new DateTime('now'))->format('c'),
				],
				statusCode: 500
			);
		}
	}//end calculate()

	/**
	 * Get chart data for audit trail actions
	 *
	 * @param string|null $from Start date (Y-m-d format)
	 * @param string|null $till End date (Y-m-d format)
	 * @param int|null $registerId Optional register ID
	 * @param int|null $schemaId Optional schema ID
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with chart data or error
	 *
	 * @psalm-return JSONResponse<200|403|500,
	 *     array{error?: string, labels?: list<array-key>,
	 *     series?: list<array{data: list<int>, name: string}>},
	 *     array<never, never>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-graphql-rt-dash/tasks.md#task-8
	 */
	public function getAuditTrailActionChart(
		?string $from = null,
		?string $till = null,
		?int $registerId = null,
		?int $schemaId = null,
	): JSONResponse {
		return $this->adminRead(
			read: fn (): array => $this->dashboardService->auditReads()->getAuditTrailActionChartData(
				period: (new DashboardScope(registerId: $registerId, schemaId: $schemaId))
					->during(from: self::dateOrNull(value: $from), till: self::dateOrNull(value: $till))
			)
		);
	}//end getAuditTrailActionChart()

	/**
	 * Get chart data for objects by register
	 *
	 * @param int|null $registerId Optional register ID
	 * @param int|null $schemaId Optional schema ID
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with chart data or error
	 *
	 * @psalm-return JSONResponse<200|403|500,
	 *     array{error?: string, labels?: array<'Unknown'|mixed>, series?: array<int>},
	 *     array<never, never>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-graphql-rt-dash/tasks.md#task-8
	 */
	public function getObjectsByRegisterChart(?int $registerId = null, ?int $schemaId = null): JSONResponse {
		if ($this->isCurrentUserAdmin() === false) {
			return new JSONResponse(['error' => 'Admin privileges required'], 403);
		}

		try {
			$data = $this->dashboardService->getObjectsByRegisterChartData(registerId: $registerId, schemaId: $schemaId);
			return new JSONResponse(data: $data);
		} catch (\Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
		}
	}//end getObjectsByRegisterChart()

	/**
	 * Get chart data for objects by schema
	 *
	 * @param int|null $registerId Optional register ID
	 * @param int|null $schemaId Optional schema ID
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with chart data or error
	 *
	 * @psalm-return JSONResponse<200|403|500,
	 *     array{error?: string, labels?: array<'Unknown'|mixed>, series?: array<int>},
	 *     array<never, never>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-graphql-rt-dash/tasks.md#task-8
	 */
	public function getObjectsBySchemaChart(?int $registerId = null, ?int $schemaId = null): JSONResponse {
		if ($this->isCurrentUserAdmin() === false) {
			return new JSONResponse(['error' => 'Admin privileges required'], 403);
		}

		try {
			$data = $this->dashboardService->getObjectsBySchemaChartData(registerId: $registerId, schemaId: $schemaId);
			return new JSONResponse(data: $data);
		} catch (\Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
		}
	}//end getObjectsBySchemaChart()

	/**
	 * Get chart data for objects by size distribution
	 *
	 * @param int|null $registerId Optional register ID
	 * @param int|null $schemaId Optional schema ID
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with chart data or error
	 *
	 * @psalm-return JSONResponse<200|403|500,
	 *     array{error?: string,
	 *     labels?: list<'0-1 KB'|'1-10 KB'|'10-100 KB'|'100 KB-1 MB'|'> 1 MB'>,
	 *     series?: list<int>},
	 *     array<never, never>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-graphql-rt-dash/tasks.md#task-8
	 */
	public function getObjectsBySizeChart(?int $registerId = null, ?int $schemaId = null): JSONResponse {
		if ($this->isCurrentUserAdmin() === false) {
			return new JSONResponse(['error' => 'Admin privileges required'], 403);
		}

		try {
			$data = $this->dashboardService->getObjectsBySizeChartData(registerId: $registerId, schemaId: $schemaId);
			return new JSONResponse(data: $data);
		} catch (\Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
		}
	}//end getObjectsBySizeChart()

	/**
	 * Get audit trail statistics for the dashboard sidebar
	 *
	 * @param int|null $registerId Optional register ID to filter by
	 * @param int|null $schemaId Optional schema ID to filter by
	 * @param int|null $hours Optional number of hours to look back for recent activity (default: 24)
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with statistics or error
	 *
	 * @psalm-return JSONResponse<200|403|500,
	 *     array{error?: string, total?: int, creates?: int,
	 *     updates?: int, deletes?: int, reads?: int},
	 *     array<never, never>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-graphql-rt-dash/tasks.md#task-8
	 */
	public function getAuditTrailStatistics(?int $registerId = null, ?int $schemaId = null, ?int $hours = 24): JSONResponse {
		return $this->auditStatistics(scope: new DashboardScope(registerId: $registerId, schemaId: $schemaId), hours: $hours);
	}//end getAuditTrailStatistics()

	/**
	 * The audit trail statistics of a scope, for an administrator.
	 *
	 * @param DashboardScope $scope The register/schema scope.
	 * @param int|null       $hours The look-back hours for recent activity.
	 *
	 * @return JSONResponse The statistics, 403 for anyone else, or 500 with the logged failure.
	 */
	private function auditStatistics(DashboardScope $scope, ?int $hours): JSONResponse {
		return $this->adminRead(
			read: fn (): array => $this->dashboardService->auditReads()->getAuditTrailStatistics(scope: $scope, hours: $hours)
		);
	}//end auditStatistics()

	/**
	 * Get action distribution data for audit trails
	 *
	 * @param int|null $registerId Optional register ID to filter by
	 * @param int|null $schemaId Optional schema ID to filter by
	 * @param int|null $hours Optional number of hours to look back (default: 24)
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with action distribution or error
	 *
	 * @psalm-return JSONResponse<200|403|500,
	 *     array{error?: string, actions?: list<array{count: int, name: mixed}>},
	 *     array<never, never>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-graphql-rt-dash/tasks.md#task-8
	 */
	public function getAuditTrailActionDistribution(?int $registerId = null, ?int $schemaId = null, ?int $hours = 24): JSONResponse {
		return $this->auditDistribution(scope: new DashboardScope(registerId: $registerId, schemaId: $schemaId), hours: $hours);
	}//end getAuditTrailActionDistribution()

	/**
	 * The audit action distribution of a scope, for an administrator.
	 *
	 * @param DashboardScope $scope The register/schema scope.
	 * @param int|null       $hours The look-back hours.
	 *
	 * @return JSONResponse The distribution, 403 for anyone else, or 500 with the logged failure.
	 */
	private function auditDistribution(DashboardScope $scope, ?int $hours): JSONResponse {
		return $this->adminRead(
			read: fn (): array => $this->dashboardService->auditReads()->getAuditTrailActionDistribution(scope: $scope, hours: $hours)
		);
	}//end auditDistribution()

	/**
	 * Get most active objects based on audit trail activity
	 *
	 * @param int|null $registerId Optional register ID to filter by
	 * @param int|null $schemaId Optional schema ID to filter by
	 * @param int|null $limit Optional limit for number of results (default: 10)
	 * @param int|null $hours Optional number of hours to look back (default: 24)
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with most active objects or error
	 *
	 * @psalm-return JSONResponse<200|403|500,
	 *     array{error?: string, objects?: list<array{count: int, id: mixed, name: string}>},
	 *     array<never, never>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-graphql-rt-dash/tasks.md#task-8
	 */
	public function getMostActiveObjects(
		?int $registerId = null,
		?int $schemaId = null,
		?int $limit = 10,
		?int $hours = 24,
	): JSONResponse {
		$scope = new DashboardScope(registerId: $registerId, schemaId: $schemaId);
		return $this->mostActive(window: $scope->mostActive(limit: $limit, hours: $hours));
	}//end getMostActiveObjects()
	/**
	 * The objects with the most audit activity in a window, for an administrator.
	 *
	 * @param ActivityWindow $window The register/schema scope, the limit and the look-back hours.
	 *
	 * @return JSONResponse The objects, 403 for anyone else, or 500 with the logged failure.
	 */
	private function mostActive(ActivityWindow $window): JSONResponse {
		if ($this->isCurrentUserAdmin() === false) {
			return new JSONResponse(['error' => 'Admin privileges required'], 403);
		}

		try {
			$data = $this->dashboardService->auditReads()->getMostActiveObjects(window: $window);
			return new JSONResponse(data: $data);
		} catch (\Exception $e) {
			$this->session->logger->error(
				message: '[DashboardController] Error retrieving most active objects: ' . $e->getMessage(),
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'register_id' => $window->scope->registerId,
					'schema_id' => $window->scope->schemaId,
					'limit' => $window->limit,
					'hours' => $window->hours,
					'trace' => $e->getTraceAsString(),
				]
			);

			return new JSONResponse(
				data: [
					'error' => 'Failed to retrieve most active objects: ' . $e->getMessage(),
				],
				statusCode: 500
			);
		}//end try
	}//end mostActive()

	/**
	 * Answer an analytics read for an administrator: 403 for anyone else, 500 with the message when it fails.
	 *
	 * The admin check runs before the read, so nothing is computed for a non-admin.
	 *
	 * @param \Closure(): array<string, mixed> $read The read.
	 *
	 * @return JSONResponse The read's data, or the refusal or failure.
	 */
	private function adminRead(\Closure $read): JSONResponse {
		if ($this->isCurrentUserAdmin() === false) {
			return new JSONResponse(['error' => 'Admin privileges required'], 403);
		}

		try {
			return new JSONResponse(data: $read());
		} catch (\Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
		}
	}//end adminRead()

	/**
	 * A date from a query parameter, or null when there is none.
	 *
	 * @param string|null $value The Y-m-d parameter.
	 *
	 * @return DateTime|null
	 *
	 * @throws \Exception When the value is not a date.
	 */
	private static function dateOrNull(?string $value): ?DateTime {
		if ($value === null) {
			return null;
		}

		return new DateTime($value);
	}//end dateOrNull()
}//end class
