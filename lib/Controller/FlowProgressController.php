<?php

/**
 * Workflow progress: how a flow's work is going, per flow and per step.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/tasks-progress-report/specs/flow-progress-report/spec.md#requirement-a-flow-reports-its-progress-per-step
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Service\Flow\FlowAccessInterface;
use OCA\OpenRegister\Service\Flow\FlowProgressPeriod;
use OCA\OpenRegister\Service\Flow\FlowProgressService;
use OCA\OpenRegister\Service\Flow\FlowServiceInterface;
use OCA\OpenRegister\Service\Task\TaskTemporalProjection;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * `GET /api/flows/{id}/progress` and `GET /api/flows/progress`.
 *
 * The same access as reading the flow with `GET /api/flows/{id}`: the
 * `flow.read` right and the flow in the caller's active organisation, with a
 * flow outside it answered 404 exactly like one that does not exist. Counts
 * and durations only.
 *
 * @spec openspec/changes/tasks-progress-report/specs/flow-progress-report/spec.md#requirement-the-report-respects-organisations-and-shows-no-content
 */
class FlowProgressController extends Controller {

	/**
	 * The right reading a flow takes, and so reading its progress.
	 *
	 * @var string
	 */
	private const READ_RIGHT = 'flow.read';

	/**
	 * The most flows one page of the comparison lists.
	 *
	 * @var int
	 */
	public const MAX_LIMIT = 50;

	/**
	 * Constructor.
	 *
	 * @param IRequest               $request  The request.
	 * @param FlowServiceInterface   $flows    Reads flows with the caller's organisation guard.
	 * @param FlowAccessInterface    $access   The session user and their rights.
	 * @param FlowProgressService    $progress Adds up runs, steps and tasks.
	 * @param TaskTemporalProjection $temporal The clock the window ends at by default.
	 */
	public function __construct(
		IRequest $request,
		private readonly FlowServiceInterface $flows,
		private readonly FlowAccessInterface $access,
		private readonly FlowProgressService $progress,
		private readonly TaskTemporalProjection $temporal,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * One flow's progress per step over a window.
	 *
	 * @param string $id The flow uuid.
	 *
	 * @return JSONResponse 200 with the report; 400 naming a bad window parameter;
	 *                      401 without a session; 403 without `flow.read`; 404 for a
	 *                      flow that does not exist or is outside the active organisation.
	 *
	 * @spec openspec/changes/tasks-progress-report/specs/flow-progress-report/spec.md#requirement-a-flow-reports-its-progress-per-step
	 * @spec openspec/changes/tasks-progress-report/specs/flow-progress-report/spec.md#requirement-the-report-respects-organisations-and-shows-no-content
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(string $id): JSONResponse {
		$denied = $this->denyUnlessMayRead();
		if ($denied !== null) {
			return $denied;
		}

		try {
			$period = $this->period();
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		try {
			$flow = $this->flows->find(uuid: $id);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'No such flow'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($this->progress->forFlow(flow: $flow, from: $period->from, to: $period->to));
	}//end show()

	/**
	 * Per-flow totals for a page of the caller's flows over a window.
	 *
	 * Reads `_page` (from 1) and `_limit` (at most 50, default 20).
	 *
	 * @return JSONResponse 200 with results, total, page, limit and the window;
	 *                      400 naming a bad window parameter; 401 without a session;
	 *                      403 without `flow.read`.
	 *
	 * @spec openspec/changes/tasks-progress-report/specs/flow-progress-report/spec.md#requirement-all-flows-can-be-compared-on-one-list
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		$denied = $this->denyUnlessMayRead();
		if ($denied !== null) {
			return $denied;
		}

		try {
			$period = $this->period();
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		$limit = min(self::MAX_LIMIT, max(1, (int)$this->request->getParam('_limit', 20)));
		$page = max(1, (int)$this->request->getParam('_page', 1));
		$flows = $this->flows->findAll(limit: $limit, offset: (($page - 1) * $limit));

		return new JSONResponse([
			'results' => $this->progress->forFlows(flows: $flows, from: $period->from, to: $period->to),
			'total' => $this->flows->count(),
			'page' => $page,
			'limit' => $limit,
			'window' => ['from' => $period->from->format(DATE_ATOM), 'to' => $period->to->format(DATE_ATOM)],
		]);
	}//end index()

	/**
	 * The request's window.
	 *
	 * @return FlowProgressPeriod The window.
	 *
	 * @throws InvalidArgumentException When a parameter does not parse, or the window is reversed or too long.
	 */
	private function period(): FlowProgressPeriod {
		return new FlowProgressPeriod(
			from: $this->stringParam(name: 'from'),
			to: $this->stringParam(name: 'to'),
			now: $this->temporal->now()
		);
	}//end period()

	/**
	 * A query parameter as a string, or null when it is absent or not a string.
	 *
	 * @param string $name The parameter.
	 *
	 * @return string|null The value.
	 */
	private function stringParam(string $name): ?string {
		$value = $this->request->getParam($name);
		if (is_string($value) === false) {
			return null;
		}

		return $value;
	}//end stringParam()

	/**
	 * 401 without a session, 403 without the right to read flows, null when allowed.
	 *
	 * @return JSONResponse|null The refusal, or null.
	 */
	private function denyUnlessMayRead(): ?JSONResponse {
		$user = $this->access->currentUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not signed in.'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->access->may(user: $user, action: self::READ_RIGHT) === true) {
			return null;
		}

		return new JSONResponse(
			['error' => sprintf('You do not have the "%s" right.', self::READ_RIGHT)],
			Http::STATUS_FORBIDDEN
		);
	}//end denyUnlessMayRead()
}//end class
