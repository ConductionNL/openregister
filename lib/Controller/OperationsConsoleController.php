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
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Exception\ConsistencyCheckWouldWriteException;
use OCA\OpenRegister\Exception\JobRunRefusedException;
use OCA\OpenRegister\Exception\RepairRefusedException;
use OCA\OpenRegister\Service\OperationsConsoleService;
use OCA\OpenRegister\Service\Operations\ConsistencyCheckService;
use OCA\OpenRegister\Service\Operations\ConsistencyRepairService;
use OCA\OpenRegister\Service\Operations\JobAlertService;
use OCA\OpenRegister\Service\Operations\MaintenanceModeService;
use OCA\OpenRegister\Service\Operations\OperationsJobsService;
use OCA\OpenRegister\Service\Operations\SupportBundleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * OperationsConsoleController.
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
 */
class OperationsConsoleController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                    $appName     Application name.
	 * @param IRequest                  $request     HTTP request.
	 * @param OperationsConsoleService  $console     The read model.
	 * @param OperationsJobsService     $jobsService The run history, run now and the schedule.
	 * @param ConsistencyCheckService   $check       The read-only consistency check.
	 * @param ConsistencyRepairService  $repair      The repair, as a separate act.
	 * @param MaintenanceModeService    $maintenance Maintenance mode.
	 * @param SupportBundleService      $bundle      The support bundle and the instance facts.
	 * @param JobAlertService           $alerts      The administered failure threshold.
	 * @param IUserSession              $userSession Names the administrator acting.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) One console, one
	 * controller: splitting it would put the operations surface behind two
	 * route prefixes for no reader's benefit.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly OperationsConsoleService $console,
		private readonly OperationsJobsService $jobsService,
		private readonly ConsistencyCheckService $check,
		private readonly ConsistencyRepairService $repair,
		private readonly MaintenanceModeService $maintenance,
		private readonly SupportBundleService $bundle,
		private readonly JobAlertService $alerts,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The console's panes over a window.
	 *
	 * @return JSONResponse The window and the panes.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		return new JSONResponse(
			data: $this->console->panes(windowHours: $this->intParam(name: 'hours', fallback: OperationsConsoleService::DEFAULT_WINDOW_HOURS))
		);
	}//end index()

	/**
	 * The job pane: the bulk jobs, and the inventory they sit in.
	 *
	 * @return JSONResponse The jobs, the registered inventory and the unobserved ones.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
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
				limit: $this->intParam(name: 'limit', fallback: 50)
			)
		);
	}//end jobs()

	/**
	 * The rules engine's recent runs, across every rule.
	 *
	 * @return JSONResponse The runs and the rules holding an error.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	#[NoCSRFRequired]
	public function ruleRuns(): JSONResponse {
		return new JSONResponse(
			data: $this->console->ruleRuns(
				windowHours: $this->intParam(name: 'hours', fallback: OperationsConsoleService::DEFAULT_WINDOW_HOURS),
				limit: $this->intParam(name: 'limit', fallback: 50)
			)
		);
	}//end ruleRuns()

	/**
	 * The run history, filtered by job, outcome and period.
	 *
	 * @return JSONResponse The runs and how many there are.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	#[NoCSRFRequired]
	public function runs(): JSONResponse {
		$hours = $this->request->getParam('hours');

		$windowHours = null;
		if (is_numeric($hours) === true) {
			$windowHours = (int)$hours;
		}

		return new JSONResponse(
			data: $this->jobsService->runs(
				jobClass: $this->stringParam(name: 'job'),
				outcome: $this->stringParam(name: 'outcome'),
				windowHours: $windowHours,
				limit: $this->intParam(name: 'limit', fallback: 50),
				offset: $this->intParam(name: 'offset', fallback: 0)
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
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-run-is-started-again-from-the-console-once-req-aoc-002
	 */
	public function runNow(): JSONResponse {
		$job = $this->stringParam(name: 'job');

		if ($job === null) {
			return new JSONResponse(
				['error' => 'no-job', 'message' => 'Name the job to start.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			return new JSONResponse(
				data: $this->jobsService->runNow(jobClass: $job, actor: $this->actor()),
				statusCode: Http::STATUS_ACCEPTED
			);
		} catch (JobRunRefusedException $refusal) {
			return new JSONResponse(
				[
					'error' => 'refused',
					'reason' => $refusal->getReason(),
					'message' => $refusal->getMessage(),
					'details' => $refusal->getDetails(),
				],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}
	}//end runNow()

	/**
	 * One job's schedule, or the schedule after administering it.
	 *
	 * @return JSONResponse The schedule in force.
	 *
	 * @auth admin-only administering a schedule declares no NoAdminRequired attribute, so the
	 *       middleware refuses a non-administrator before this controller is
	 *       built, and CSRF stays required on the write.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 */
	public function schedule(): JSONResponse {
		$job = $this->stringParam(name: 'job');

		if ($job === null) {
			return new JSONResponse(
				['error' => 'no-job', 'message' => 'Name the job whose schedule this is.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		if ($this->request->getMethod() === 'GET') {
			return new JSONResponse(data: $this->jobsService->schedule(jobClass: $job));
		}

		$enabled = $this->request->getParam('enabled');

		$wantedEnabled = null;
		if (is_bool($enabled) === true) {
			$wantedEnabled = $enabled;
		}

		return new JSONResponse(
			data: $this->jobsService->administerSchedule(
				jobClass: $job,
				enabled: $wantedEnabled,
				intervalSeconds: $this->nullableIntParam(name: 'intervalSeconds'),
				windowStartHour: $this->nullableIntParam(name: 'windowStartHour'),
				windowEndHour: $this->nullableIntParam(name: 'windowEndHour')
			)
		);
	}//end schedule()

	/**
	 * The failure alerts, and the threshold in force.
	 *
	 * @return JSONResponse The alerts.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
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
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 */
	public function administerAlerts(): JSONResponse {
		return new JSONResponse(
			data: $this->alerts->administer(
				threshold: $this->nullableIntParam(name: 'threshold'),
				periodMinutes: $this->nullableIntParam(name: 'periodMinutes')
			)
		);
	}//end administerAlerts()

	/**
	 * The read-only consistency check.
	 *
	 * @return JSONResponse The findings.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-the-instance-checks-its-own-data-and-repairs-it-as-a-separate-act-req-aoc-005
	 */
	#[NoCSRFRequired]
	public function consistency(): JSONResponse {
		try {
			return new JSONResponse(data: $this->check->check());
		} catch (ConsistencyCheckWouldWriteException $refusal) {
			return new JSONResponse(
				[
					'error' => 'would-write',
					'probe' => $refusal->getProbe(),
					'message' => $refusal->getMessage(),
				],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}//end consistency()

	/**
	 * What a repair would change, without changing it.
	 *
	 * @return JSONResponse The plan.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-the-instance-checks-its-own-data-and-repairs-it-as-a-separate-act-req-aoc-005
	 */
	#[NoCSRFRequired]
	public function repairPlan(): JSONResponse {
		return $this->repairing(apply: false);
	}//end repairPlan()

	/**
	 * Apply a repair, as this administrator.
	 *
	 * @return JSONResponse What was changed.
	 *
	 * @auth admin-only applying a repair declares no NoAdminRequired attribute, so the
	 *       middleware refuses a non-administrator before this controller is
	 *       built, and CSRF stays required on the write.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-the-instance-checks-its-own-data-and-repairs-it-as-a-separate-act-req-aoc-005
	 */
	public function repair(): JSONResponse {
		return $this->repairing(apply: true);
	}//end repair()

	/**
	 * Maintenance mode: read it, enter it or leave it.
	 *
	 * @return JSONResponse The mode in force.
	 *
	 * @auth admin-only entering or leaving maintenance declares no NoAdminRequired attribute,
	 *       so the middleware refuses a non-administrator before this
	 *       controller is built, and CSRF stays required on the write.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-maintenance-mode-closes-the-instance-without-locking-administration-out-req-aoc-006
	 */
	public function maintenance(): JSONResponse {
		$method = $this->request->getMethod();

		if ($method === 'GET') {
			return new JSONResponse(data: $this->maintenance->state());
		}

		if ($method === 'DELETE') {
			return new JSONResponse(data: $this->maintenance->leave(actor: $this->actor()));
		}

		return new JSONResponse(
			data: $this->maintenance->enter(
				actor: $this->actor(),
				message: $this->stringParam(name: 'message')
			)
		);
	}//end maintenance()

	/**
	 * The support bundle, redacted where it was built.
	 *
	 * @return JSONResponse The bundle.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-support-bundle-and-the-instances-own-facts-are-readable-req-aoc-007
	 */
	#[NoCSRFRequired]
	public function supportBundle(): JSONResponse {
		return new JSONResponse(data: $this->bundle->build());
	}//end supportBundle()

	/**
	 * The instance facts: version, build, dependencies and licence.
	 *
	 * @return JSONResponse The facts.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-support-bundle-and-the-instances-own-facts-are-readable-req-aoc-007
	 */
	#[NoCSRFRequired]
	public function facts(): JSONResponse {
		return new JSONResponse(data: $this->bundle->facts());
	}//end facts()

	/**
	 * The plan-or-apply half both repair endpoints share.
	 *
	 * @param bool $apply False to say what would change, true to change it.
	 *
	 * @return JSONResponse The plan, or what was changed.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The two endpoints are the
	 * two acts D-5 separates; this is their shared body, not a switch a caller
	 * reaches.
	 */
	private function repairing(bool $apply): JSONResponse {
		$slug = $this->stringParam(name: 'check');

		if ($slug === null) {
			return new JSONResponse(
				['error' => 'no-check', 'message' => 'Name the check whose finding this repairs.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			if ($apply === false) {
				return new JSONResponse(data: $this->repair->plan(slug: $slug));
			}

			return new JSONResponse(data: $this->repair->apply(slug: $slug, actor: $this->actor()));
		} catch (RepairRefusedException $refusal) {
			return new JSONResponse(
				[
					'error' => 'refused',
					'reason' => $refusal->getReason(),
					'message' => $refusal->getMessage(),
				],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}
	}//end repairing()

	/**
	 * The uid acting.
	 *
	 * Every write here is an administrator's act and is recorded as theirs, so
	 * an unresolvable session is an empty string the services refuse rather
	 * than a system identity the record would blame.
	 *
	 * @return string The uid, or the empty string.
	 */
	private function actor(): string {
		return (string)($this->userSession->getUser()?->getUID() ?? '');
	}//end actor()

	/**
	 * Read a non-empty string request parameter.
	 *
	 * @param string $name The parameter name.
	 *
	 * @return string|null The value, or null when absent or empty.
	 */
	private function stringParam(string $name): ?string {
		$value = $this->request->getParam($name);

		if (is_string($value) === false || $value === '') {
			return null;
		}

		return $value;
	}//end stringParam()

	/**
	 * Read a whole-number request parameter, or nothing.
	 *
	 * Distinct from {@see intParam()}: an absent setting must stay absent so
	 * administering one field does not silently reset the others.
	 *
	 * @param string $name The parameter name.
	 *
	 * @return int|null The value, or null when absent or malformed.
	 */
	private function nullableIntParam(string $name): ?int {
		$value = $this->request->getParam($name);

		if (is_numeric($value) === false) {
			return null;
		}

		return (int)$value;
	}//end nullableIntParam()

	/**
	 * Read a whole-number request parameter, or fall back.
	 *
	 * A parameter that is not a number falls back rather than reading as
	 * zero, because zero is a window this service would then bound to one
	 * hour and report as if it had been asked for.
	 *
	 * @param string $name     The parameter name.
	 * @param int    $fallback The value to use when it is absent or malformed.
	 *
	 * @return int The value.
	 */
	private function intParam(string $name, int $fallback): int {
		$value = $this->request->getParam($name);

		if (is_numeric($value) === false) {
			return $fallback;
		}

		return (int)$value;
	}//end intParam()
}//end class
