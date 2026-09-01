<?php

/**
 * OpenRegister Audit Query Controller
 *
 * Admin-only v2 API surface for querying and exporting audit-entry objects
 * across every app/register/schema in the instance.
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
 *
 * @spec openspec/changes/public-audit-query-endpoint/tasks.md#task-2.1
 * @spec openspec/changes/public-audit-query-endpoint/tasks.md#task-2.2
 * @spec openspec/changes/public-audit-query-endpoint/tasks.md#task-3.1
 * @spec openspec/changes/public-audit-query-endpoint/tasks.md#task-3.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use DateTime;
use OCA\OpenRegister\Service\AuditQueryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Handles GET /api/v2/audit and GET /api/v2/audit/export.
 *
 * Admin-only: neither action carries `@NoAdminRequired`, so Nextcloud's
 * framework-level default (admin required) applies; `requireAdmin()` is a
 * defence-in-depth body-level check mirroring {@see AuditTrailController},
 * this app's existing admin-gated audit surface.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/public-audit-query-endpoint/tasks.md#task-2.1
 */
class AuditQueryController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request object.
	 * @param AuditQueryService $auditQueryService Cross-schema audit-entry query service.
	 * @param IUserSession $userSession Active user session for the admin gate.
	 * @param IGroupManager $groupManager Group manager for the admin gate.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AuditQueryService $auditQueryService,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Gate every action on this controller on NC-admin membership.
	 *
	 * @return JSONResponse|null 401 when anonymous, 403 when non-admin, null when allowed.
	 */
	private function requireAdmin(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				data: ['error' => 'Authentication required'],
				statusCode: 401
			);
		}

		if ($this->groupManager->isAdmin($user->getUID()) === false) {
			return new JSONResponse(
				data: ['error' => 'Forbidden: this endpoint is admin-only'],
				statusCode: 403
			);
		}

		return null;
	}//end requireAdmin()

	/**
	 * Extract the audit-query filters from the request.
	 *
	 * @return array<string, mixed>
	 */
	private function extractFilters(): array {
		$filters = [];
		foreach (['registerId', 'schemaId', 'objectId', 'app', 'timestampStart', 'timestampEnd', 'sort'] as $key) {
			$value = $this->request->getParam($key);
			if ($value !== null && $value !== '') {
				$filters[$key] = $value;
			}
		}

		return $filters;
	}//end extractFilters()

	/**
	 * `GET /api/v2/audit` — query audit-entry objects across all apps/schemas.
	 *
	 * @return JSONResponse Response shaped `{entries, total, limit, offset}`.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/public-audit-query-endpoint/tasks.md#task-2.1
	 */
	public function query(): JSONResponse {
		$denial = $this->requireAdmin();
		if ($denial !== null) {
			return $denial;
		}

		$filters = $this->extractFilters();
		$limit = (int)$this->request->getParam('limit', '50');
		$offset = (int)$this->request->getParam('offset', '0');

		$result = $this->auditQueryService->query(filters: $filters, limit: $limit, offset: $offset);

		return new JSONResponse(data: $result);
	}//end query()

	/**
	 * `GET /api/v2/audit/export` — export the same query as CSV (default) or JSON.
	 *
	 * @return JSONResponse|DataDownloadResponse CSV download, or JSON body when `?format=json`.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/public-audit-query-endpoint/tasks.md#task-2.1
	 * @spec openspec/changes/public-audit-query-endpoint/tasks.md#task-3.1
	 * @spec openspec/changes/public-audit-query-endpoint/tasks.md#task-3.2
	 */
	public function export(): JSONResponse|DataDownloadResponse {
		$denial = $this->requireAdmin();
		if ($denial !== null) {
			return $denial;
		}

		$filters = $this->extractFilters();
		$format = (string)$this->request->getParam('format', 'csv');
		// Export defaults to the maximum page size (still clamped by the
		// service to [1, 200]) so a single call carries as much of the
		// filtered result set as the clamp allows.
		$limit = (int)$this->request->getParam('limit', '200');
		$offset = (int)$this->request->getParam('offset', '0');

		$result = $this->auditQueryService->query(filters: $filters, limit: $limit, offset: $offset);
		$entries = $result['entries'];

		if (strtolower($format) === 'json') {
			return new JSONResponse(
				data: [
					'entries' => $entries,
					'total' => $result['total'],
					'limit' => $result['limit'],
					'offset' => $result['offset'],
				]
			);
		}

		$csv = $this->buildCsvFromAuditEntries(entries: $entries);
		$filename = sprintf('audit-export_%s.csv', (new DateTime())->format('Y-m-d_His'));

		return new DataDownloadResponse($csv, $filename, 'text/csv');
	}//end export()

	/**
	 * Flatten audit entries into a CSV string.
	 *
	 * Columns: id, registerId, schemaId, objectId, data (JSON), created, userId.
	 *
	 * @param array<int, array<string, mixed>> $entries Entries as returned by AuditQueryService::query().
	 *
	 * @return string The CSV document (including header row).
	 *
	 * @spec openspec/changes/public-audit-query-endpoint/tasks.md#task-3.1
	 */
	private function buildCsvFromAuditEntries(array $entries): string {
		$handle = fopen('php://temp', 'r+');

		fputcsv($handle, ['id', 'registerId', 'schemaId', 'objectId', 'data', 'created', 'userId']);

		foreach ($entries as $entry) {
			fputcsv(
				$handle,
				[
					($entry['id'] ?? ''),
					($entry['registerId'] ?? ''),
					($entry['schemaId'] ?? ''),
					($entry['objectId'] ?? ''),
					json_encode($entry['data'] ?? []),
					($entry['created'] ?? ''),
					($entry['userId'] ?? ''),
				]
			);
		}

		rewind($handle);
		$csv = stream_get_contents($handle);
		fclose($handle);

		if ($csv === false) {
			return '';
		}

		return $csv;
	}//end buildCsvFromAuditEntries()
}//end class
