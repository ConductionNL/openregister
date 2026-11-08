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
 * @spec openspec/specs/operations-console/spec.md#requirement-the-instance-checks-its-own-data-and-repairs-it-as-a-separate-act-req-aoc-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Exception\ConsistencyCheckWouldWriteException;
use OCA\OpenRegister\Exception\RepairRefusedException;
use OCA\OpenRegister\Service\Operations\ConsistencyCheckServiceInterface;
use OCA\OpenRegister\Service\Operations\ConsistencyRepairServiceInterface;
use OCA\OpenRegister\Controller\Support\OperationsActingRequest;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCA\OpenRegister\Controller\Support\OperationsConsistencyControllerServices;

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
 * @spec openspec/specs/operations-console/spec.md#requirement-the-instance-checks-its-own-data-and-repairs-it-as-a-separate-act-req-aoc-005
 */
class OperationsConsistencyController extends Controller {

	/**
	 * The acting administrator and the parameter readers.
	 *
	 * @var OperationsActingRequest
	 */
	private readonly OperationsActingRequest $acting;

	/**
	 * The read-only consistency check.
	 *
	 * @var ConsistencyCheckServiceInterface
	 */
	private readonly ConsistencyCheckServiceInterface $check;

	/**
	 * The repair, as a separate act.
	 *
	 * @var ConsistencyRepairServiceInterface
	 */
	private readonly ConsistencyRepairServiceInterface $repair;

	/**
	 * Constructor.
	 *
	 * @param string                   $appName     Application name.
	 * @param IRequest                 $request     HTTP request.
	 * @param OperationsConsistencyControllerServices $services The services, bundled.
	 * @param IUserSession             $userSession Names the administrator acting.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		OperationsConsistencyControllerServices $services,
		IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->check = $services->check;
		$this->repair = $services->repair;
		$this->acting = new OperationsActingRequest(request: $request, userSession: $userSession);
	}//end __construct()

	/**
	 * The read-only consistency check.
	 *
	 * @return JSONResponse The findings.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-the-instance-checks-its-own-data-and-repairs-it-as-a-separate-act-req-aoc-005
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
	 * @spec openspec/specs/operations-console/spec.md#requirement-the-instance-checks-its-own-data-and-repairs-it-as-a-separate-act-req-aoc-005
	 */
	#[NoCSRFRequired]
	public function repairPlan(): JSONResponse {
		return $this->repairing(act: fn (string $slug): array => $this->repair->plan(slug: $slug));
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
	 * @spec openspec/specs/operations-console/spec.md#requirement-the-instance-checks-its-own-data-and-repairs-it-as-a-separate-act-req-aoc-005
	 */
	public function repair(): JSONResponse {
		return $this->repairing(act: fn (string $slug): array => $this->repair->apply(slug: $slug, actor: $this->acting->actor()));
	}//end repair()

	/**
	 * The plan-or-apply half both repair endpoints share.
	 *
	 * @param callable $act The act on the named check: (string $slug): array —
	 *                      the plan, or the applied repair.
	 *
	 * @return JSONResponse The plan, or what was changed.
	 */
	private function repairing(callable $act): JSONResponse {
		$slug = $this->acting->stringParam(name: 'check');

		if ($slug === null) {
			return new JSONResponse(
				['error' => 'no-check', 'message' => 'Name the check whose finding this repairs.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			return new JSONResponse(data: $act($slug));
		} catch (RepairRefusedException $refusal) {
			return $this->refusedRepair(refusal: $refusal);
		}
	}//end repairing()

	/**
	 * The 422 for a repair the service refused.
	 *
	 * @param RepairRefusedException $refusal Why it was refused.
	 *
	 * @return JSONResponse The refusal.
	 */
	private function refusedRepair(RepairRefusedException $refusal): JSONResponse {
		return new JSONResponse(
			[
				'error' => 'refused',
				'reason' => $refusal->getReason(),
				'message' => $refusal->getMessage(),
			],
			Http::STATUS_UNPROCESSABLE_ENTITY
		);
	}//end refusedRepair()


}//end class
