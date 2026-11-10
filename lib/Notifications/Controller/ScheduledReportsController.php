<?php

/**
 * OpenRegister Scheduled Reports Controller
 *
 * REST CRUD for ScheduledReport rows (owner-scoped, admin sees all) plus a
 * run-now action that queues immediate execution.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/scheduled-report-jobs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Controller;

use OCA\OpenRegister\AppInfo\Application;
use InvalidArgumentException;
use OCA\OpenRegister\Kernel\Contracts\ReportCaller;
use OCA\OpenRegister\Kernel\Contracts\ScheduledReportInterface;
use OCA\OpenRegister\Kernel\Contracts\ScheduledReportUpdate;
use OCA\OpenRegister\Notifications\Controller\Support\ScheduledReportsControllerServices;
use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Notifications\BackgroundJob\ScheduledReportRunNowJob;
use OCA\OpenRegister\Kernel\Contracts\ScheduledReportsInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;

/**
 * ScheduledReportsController handles CRUD + run-now for scheduled report exports.
 *
 * @spec openspec/specs/scheduled-report-jobs/spec.md
 */
class ScheduledReportsController extends Controller {
	/**
	 * Business logic.
	 *
	 * @var ScheduledReportsInterface
	 */
	private readonly ScheduledReportsInterface $service;

	/**
	 * Background job list (for run-now).
	 *
	 * @var IJobList
	 */
	private readonly IJobList $jobList;

	/**
	 * Session, group manager and logger.
	 *
	 * @var ControllerSession
	 */
	private readonly ControllerSession $session;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request Request.
	 * @param ScheduledReportsControllerServices $services The controller's services.
	 */
	public function __construct(
		IRequest $request,
		ScheduledReportsControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

		$this->service = $services->service;
		$this->jobList = $services->jobList;
		$this->session = $services->session;
	}//end __construct()

	/**
	 * Resolve the current user's uid, or null when anonymous.
	 *
	 * @return ?string
	 */
	private function resolveUserId(): ?string {
		$user = $this->session->userSession->getUser();
		if ($user === null) {
			return null;
		}

		return $user->getUID();
	}//end resolveUserId()

	/**
	 * Whether the current user is a Nextcloud administrator.
	 *
	 * @return bool
	 */
	private function isCurrentUserAdmin(): bool {
		$user = $this->session->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->session->groupManager->isAdmin($user->getUID());
	}//end isCurrentUserAdmin()

	/**
	 * Build a 401 response for anonymous callers.
	 *
	 * @return JSONResponse
	 */
	private function authRequiredResponse(): JSONResponse {
		return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
	}//end authRequiredResponse()

	/**
	 * List the current user's scheduled reports. Admins may pass `?all=true`
	 * to see every user's reports.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 *
	 * @SuppressWarnings(PHPMD.ElseExpression) Deliberate branch, not a
	 *     default-then-overwrite — see the inline comment: each arm must
	 *     run exactly one query, never both.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		$userId = $this->resolveUserId();
		if ($userId === null) {
			return $this->authRequiredResponse();
		}

		$wantsAll = filter_var($this->request->getParam('all', 'false'), FILTER_VALIDATE_BOOLEAN);

		// Deliberately branches (not "assign a default, then overwrite") so
		// a non-admin ?all=true request never triggers the all-rows query
		// path, and an admin ?all=true request never runs the owner-only
		// query it's about to discard.
		if ($wantsAll === true && $this->isCurrentUserAdmin() === true) {
			$reports = $this->service->findAllForAdmin();
		} else {
			$reports = $this->service->findForOwner(ownerUid: $userId);
		}

		$items = array_map(static fn ($r) => $r->jsonSerialize(), $reports);

		return new JSONResponse(data: ['results' => $items, 'total' => count($items)]);
	}//end index()

	/**
	 * Get a single scheduled report. Owner or admin only.
	 *
	 * @param int $id The scheduled report id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(int $id): JSONResponse {
		$report = $this->accessibleReport(id: $id);
		if ($report instanceof JSONResponse) {
			return $report;
		}

		return new JSONResponse(data: $report->jsonSerialize());
	}//end show()

	/**
	 * Create a scheduled report owned by the current user.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function create(): JSONResponse {
		$userId = $this->resolveUserId();
		if ($userId === null) {
			return $this->authRequiredResponse();
		}

		$data = $this->reportFields();

		try {
			$report = $this->service->create(data: $data, ownerUid: $userId);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 422);
		} catch (\Throwable $e) {
			$this->session->logger->error(
				message: '[ScheduledReportsController] Error creating scheduled report: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'trace' => $e->getTraceAsString()]
			);
			return new JSONResponse(data: ['error' => 'Failed to create scheduled report'], statusCode: 500);
		}

		return new JSONResponse(data: $report->jsonSerialize(), statusCode: 201);
	}//end create()

	/**
	 * Update an existing scheduled report. Owner or admin only.
	 *
	 * @param int $id The scheduled report id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function update(int $id): JSONResponse {
		$userId = $this->resolveUserId();
		if ($userId === null) {
			return $this->authRequiredResponse();
		}

		$data = $this->reportFields();

		try {
			$caller = new ReportCaller(uid: $userId, isAdmin: $this->isCurrentUserAdmin());
			$report = $this->service->applyUpdate(update: new ScheduledReportUpdate(id: $id, data: $data, caller: $caller));
		} catch (DoesNotExistException | \RuntimeException $e) {
			return $this->reportNotFound();
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 422);
		} catch (\Throwable $e) {
			$this->session->logger->error(
				message: '[ScheduledReportsController] Error updating scheduled report: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'id' => $id, 'trace' => $e->getTraceAsString()]
			);
			return new JSONResponse(data: ['error' => 'Failed to update scheduled report'], statusCode: 500);
		}

		return new JSONResponse(data: $report->jsonSerialize());
	}//end update()

	/**
	 * Delete a scheduled report. Owner or admin only.
	 *
	 * @param int $id The scheduled report id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function destroy(int $id): JSONResponse {
		$userId = $this->resolveUserId();
		if ($userId === null) {
			return $this->authRequiredResponse();
		}

		try {
			$this->service->deleteAs(id: $id, caller: new ReportCaller(uid: $userId, isAdmin: $this->isCurrentUserAdmin()));
		} catch (DoesNotExistException | \RuntimeException $e) {
			return $this->reportNotFound();
		} catch (\Throwable $e) {
			$this->session->logger->error(
				message: '[ScheduledReportsController] Error deleting scheduled report: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'id' => $id, 'trace' => $e->getTraceAsString()]
			);
			return new JSONResponse(data: ['error' => 'Failed to delete scheduled report'], statusCode: 500);
		}

		return new JSONResponse(data: null, statusCode: 204);
	}//end destroy()

	/**
	 * Queue an immediate execution of a scheduled report. Owner or admin only.
	 *
	 * Never runs the export inline in the request — always dispatches
	 * `ScheduledReportRunNowJob` via `IJobList::add()` and returns 202.
	 *
	 * @param int $id The scheduled report id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function runNow(int $id): JSONResponse {
		$report = $this->accessibleReport(id: $id);
		if ($report instanceof JSONResponse) {
			return $report;
		}

		$this->jobList->add(ScheduledReportRunNowJob::class, ['scheduledReportId' => $report->getId()]);

		$this->session->logger->info(
			message: '[ScheduledReportsController] Queued run-now for scheduled report',
			context: ['file' => __FILE__, 'line' => __LINE__, 'id' => $report->getId()]
		);

		return new JSONResponse(data: ['queued' => true], statusCode: 202);
	}//end runNow()

	/**
	 * The report a caller asked for, or the answer refusing it.
	 *
	 * Unknown and inaccessible reports answer the same 404.
	 *
	 * @param int $id The scheduled report id.
	 *
	 * @return ScheduledReportInterface|JSONResponse The report, or a 401/404 response.
	 */
	private function accessibleReport(int $id): ScheduledReportInterface|JSONResponse {
		$userId = $this->resolveUserId();
		if ($userId === null) {
			return $this->authRequiredResponse();
		}

		try {
			$report = $this->service->find(id: $id);
			$this->service->assertMayAccess(report: $report, caller: new ReportCaller(uid: $userId, isAdmin: $this->isCurrentUserAdmin()));
		} catch (DoesNotExistException | \RuntimeException $e) {
			return $this->reportNotFound();
		}

		return $report;
	}//end accessibleReport()

	/**
	 * The answer for a report that does not exist or is not the caller's.
	 *
	 * @return JSONResponse 404.
	 */
	private function reportNotFound(): JSONResponse {
		return new JSONResponse(data: ['error' => 'Scheduled report not found'], statusCode: 404);
	}//end reportNotFound()

	/**
	 * The request parameters a create or update writes: every key not starting with `_`.
	 *
	 * @return array<string, mixed> The fields.
	 */
	private function reportFields(): array {
		$data = $this->request->getParams();
		foreach (array_keys($data) as $key) {
			if (str_starts_with($key, '_') === true) {
				unset($data[$key]);
			}
		}

		return $data;
	}//end reportFields()
}//end class
