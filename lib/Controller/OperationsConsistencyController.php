<?php

/**
 * Checking the instance's own data, and repairing it as a separate act.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-the-instance-checks-its-own-data-and-repairs-it-as-a-separate-act-req-aoc-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Exception\ConsistencyCheckWouldWriteException;
use OCA\OpenRegister\Exception\RepairRefusedException;
use OCA\OpenRegister\Service\Operations\ConsistencyCheckService;
use OCA\OpenRegister\Service\Operations\ConsistencyRepairService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The consistency check, the repair plan and the repair.
 *
 * 🔴 CHECKING AND REPAIRING ARE TWO ACTS (D-5), and this controller exists to
 * keep them that way. The check is read-only and refuses outright if a probe
 * would write; the plan says what a repair would change without changing it;
 * only the third one writes, and only that one is a POST with CSRF. Three
 * verbs on one surface, kept apart from the console's read panes so that the
 * one that writes cannot pick up the others\' `#[NoCSRFRequired]` by being
 * next to them.
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-the-instance-checks-its-own-data-and-repairs-it-as-a-separate-act-req-aoc-005
 */
class OperationsConsistencyController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                   $appName     Application name.
	 * @param IRequest                 $request     HTTP request.
	 * @param ConsistencyCheckService  $check       The read-only consistency check.
	 * @param ConsistencyRepairService $repair      The repair, as a separate act.
	 * @param IUserSession             $userSession Names the administrator acting.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ConsistencyCheckService $check,
		private readonly ConsistencyRepairService $repair,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

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
}//end class
