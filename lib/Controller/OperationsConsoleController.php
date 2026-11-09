<?php

/**
 * The operations console's HTTP surface.
 *
 * Three reads over what the instance is doing: the panes with their counts,
 * the job pane's rows with the verbs each row allows, and the rules engine's
 * recent runs with the rules holding an error.
 *
 * ADMINISTRATORS ONLY, AND BY THE MIDDLEWARE. No method here carries
 * `#[NoAdminRequired]`, so Nextcloud rejects a non-administrator before the
 * controller is constructed. An in-body check behind `#[NoAdminRequired]`
 * would put the only barrier in a line a refactor can delete without the
 * pipeline noticing, which is the posture `AdminOnlyPostureTest` exists to
 * refuse.
 *
 * The acting verbs are deliberately absent. Pausing, resuming, retrying and
 * cancelling a job already have one home on `BulkJobsController`, with one
 * ownership rule; a second copy on this controller would be a second place to
 * get that rule wrong. The console calls the endpoints that exist.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Exception\JobRunRefusedException;
use OCA\OpenRegister\Service\OperationsConsoleServiceInterface;
use OCA\OpenRegister\Service\Operations\JobAlertServiceInterface;
use OCA\OpenRegister\Service\Operations\JobRunFilter;
use OCA\OpenRegister\Service\Operations\JobScheduleChange;
use OCA\OpenRegister\Service\Operations\OperationsJobsServiceInterface;
use OCA\OpenRegister\Controller\Support\OperationsActingRequest;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCA\OpenRegister\Controller\Support\OperationsConsoleControllerServices;

/**
 * OperationsConsoleController.
 *
 * @spec openspec/specs/operations-console/spec.md
 */
class OperationsConsoleController extends Controller {

	/**
	 * The acting administrator and the parameter readers.
	 *
	 * @var OperationsActingRequest
	 */
	private readonly OperationsActingRequest $acting;

	/**
	 * The read model.
	 *
	 * @var OperationsConsoleServiceInterface
	 */
	private readonly OperationsConsoleServiceInterface $console;

	/**
	 * The run history, run now and the schedule.
	 *
	 * @var OperationsJobsServiceInterface
	 */
	private readonly OperationsJobsServiceInterface $jobsService;

	/**
	 * The administered failure threshold.
	 *
	 * @var JobAlertServiceInterface
	 */
	private readonly JobAlertServiceInterface $alerts;

	/**
	 * Constructor.
	 *
	 * @param string                            $appName     Application name.
	 * @param IRequest                          $request     HTTP request.
	 * @param OperationsConsoleControllerServices $services The services, bundled.
	 * @param IUserSession                      $userSession Names the administrator acting.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		OperationsConsoleControllerServices $services,
		IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->console = $services->console;
		$this->jobsService = $services->jobsService;
		$this->alerts = $services->alerts;
		$this->acting = new OperationsActingRequest(request: $request, userSession: $userSession);
	}//end __construct()

	/**
	 * The console's panes over a window.
	 *
	 * @return JSONResponse The window and the panes.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		return new JSONResponse(
			data: $this->console->panes(windowHours: $this->acting->intParam(name: 'hours', fallback: OperationsConsoleServiceInterface::DEFAULT_WINDOW_HOURS))
		);
	}//end index()

	/**
	 * The job pane: the bulk jobs, and the inventory they sit in.
	 *
	 * @return JSONResponse The jobs, the registered inventory and the unobserved ones.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	#[NoCSRFRequired]
	public function jobs(): JSONResponse {
		$state = $this->request->getParam('state');

		// An empty filter is no filter, never a state called "". Narrowing to
		// a state nothing holds would answer an empty list to a caller who
		// believes they asked for everything.
		$wanted = null;

		if (is_string($state) === true && $state !== '') {
			$wanted = $state;
		}

		return new JSONResponse(
			data: $this->console->jobs(
				state: $wanted,
				limit: $this->acting->intParam(name: 'limit', fallback: 50)
			)
		);
	}//end jobs()

	/**
	 * The rules engine's recent runs, across every rule.
	 *
	 * @return JSONResponse The runs and the rules holding an error.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	#[NoCSRFRequired]
	public function ruleRuns(): JSONResponse {
		return new JSONResponse(
			data: $this->console->ruleRuns(
				windowHours: $this->acting->intParam(name: 'hours', fallback: OperationsConsoleServiceInterface::DEFAULT_WINDOW_HOURS),
				limit: $this->acting->intParam(name: 'limit', fallback: 50)
			)
		);
	}//end ruleRuns()

	/**
	 * The run history, filtered by job, outcome and period.
	 *
	 * @return JSONResponse The runs and how many there are.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	#[NoCSRFRequired]
	public function runs(): JSONResponse {
		// No window is "all of history", so a malformed one stays null rather
		// than falling back to a default.
		$windowHours = $this->acting->nullableIntParam(name: 'hours');

		return new JSONResponse(
			data: $this->jobsService->runs(
				filter: new JobRunFilter(
					jobClass: $this->acting->stringParam(name: 'job'),
					outcome: $this->acting->stringParam(name: 'outcome'),
					windowHours: $windowHours
				),
				limit: $this->acting->intParam(name: 'limit', fallback: 50),
				offset: $this->acting->intParam(name: 'offset', fallback: 0)
			)
		);
	}//end runs()

	/**
	 * Start a job by hand, once.
	 *
	 * @return JSONResponse The run that was started, or the refusal.
	 *
	 * @auth admin-only starting a job by hand declares no NoAdminRequired attribute, so the
	 *       middleware refuses a non-administrator before this controller is
	 *       built, and CSRF stays required on the write.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-a-run-is-started-again-from-the-console-once-req-aoc-002
	 */
	public function runNow(): JSONResponse {
		$job = $this->acting->stringParam(name: 'job');

		if ($job === null) {
			return $this->noJob(message: 'Name the job to start.');
		}

		try {
			return new JSONResponse(
				data: $this->jobsService->runNow(jobClass: $job, actor: $this->acting->actor()),
				statusCode: Http::STATUS_ACCEPTED
			);
		} catch (JobRunRefusedException $refusal) {
			return $this->refusedRun(refusal: $refusal);
		}
	}//end runNow()

	/**
	 * The 422 for a run the service refused to start.
	 *
	 * @param JobRunRefusedException $refusal Why it was refused.
	 *
	 * @return JSONResponse The refusal.
	 */
	private function refusedRun(JobRunRefusedException $refusal): JSONResponse {
		return new JSONResponse(
			[
				'error' => 'refused',
				'reason' => $refusal->getReason(),
				'message' => $refusal->getMessage(),
				'details' => $refusal->getDetails(),
			],
			Http::STATUS_UNPROCESSABLE_ENTITY
		);
	}//end refusedRun()

	/**
	 * The 400 for a request that names no job.
	 *
	 * @param string $message What the caller must name.
	 *
	 * @return JSONResponse The refusal.
	 */
	private function noJob(string $message): JSONResponse {
		return new JSONResponse(['error' => 'no-job', 'message' => $message], Http::STATUS_BAD_REQUEST);
	}//end noJob()

	/**
	 * One job's schedule, or the schedule after administering it.
	 *
	 * @return JSONResponse The schedule in force.
	 *
	 * @auth admin-only administering a schedule declares no NoAdminRequired attribute, so the
	 *       middleware refuses a non-administrator before this controller is
	 *       built, and CSRF stays required on the write.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 */
	public function schedule(): JSONResponse {
		$job = $this->acting->stringParam(name: 'job');

		if ($job === null) {
			return $this->noJob(message: 'Name the job whose schedule this is.');
		}

		if ($this->request->getMethod() === 'GET') {
			return new JSONResponse(data: $this->jobsService->schedule(jobClass: $job));
		}

		return new JSONResponse(data: $this->administerSchedule(jobClass: $job));
	}//end schedule()

	/**
	 * Administer one job's schedule from the request's settings.
	 *
	 * An absent or non-boolean `enabled` is no change, never "disabled".
	 *
	 * @param string $jobClass The job.
	 *
	 * @return array The schedule now in force.
	 */
	private function administerSchedule(string $jobClass): array {
		$enabled = $this->request->getParam('enabled');

		$wantedEnabled = null;
		if (is_bool($enabled) === true) {
			$wantedEnabled = $enabled;
		}

		return $this->jobsService->administerSchedule(
			jobClass: $jobClass,
			change: new JobScheduleChange(
				enabled: $wantedEnabled,
				intervalSeconds: $this->acting->nullableIntParam(name: 'intervalSeconds'),
				windowStartHour: $this->acting->nullableIntParam(name: 'windowStartHour'),
				windowEndHour: $this->acting->nullableIntParam(name: 'windowEndHour')
			)
		);
	}//end administerSchedule()

	/**
	 * The failure alerts, and the threshold in force.
	 *
	 * @return JSONResponse The alerts.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 */
	#[NoCSRFRequired]
	public function alerts(): JSONResponse {
		$inventory = $this->console->jobs(limit: 1)['registered'];
		$classes = array_map(static fn (array $job): string => (string)$job['class'], $inventory);

		return new JSONResponse(data: $this->jobsService->alerts(jobClasses: $classes));
	}//end alerts()

	/**
	 * Administer the failure threshold.
	 *
	 * @return JSONResponse The threshold now in force.
	 *
	 * @auth admin-only moving the failure threshold declares no NoAdminRequired attribute, so
	 *       the middleware refuses a non-administrator before this controller
	 *       is built, and CSRF stays required on the write.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 */
	public function administerAlerts(): JSONResponse {
		return new JSONResponse(
			data: $this->alerts->administer(
				threshold: $this->acting->nullableIntParam(name: 'threshold'),
				periodMinutes: $this->acting->nullableIntParam(name: 'periodMinutes')
			)
		);
	}//end administerAlerts()




}//end class
