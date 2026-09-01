<?php

/**
 * The task REST surface: the inbox and the lifecycle verbs.
 *
 * Auth posture: every route is `#[NoAdminRequired]` — tasks are for
 * everyone — and NONE of those attributes is the check. The actual
 * authorization on every verb is `TaskAuthorizationService`, evaluated
 * inside the service BEFORE any mutation, which is precisely the gap this
 * change closes over `POST /api/flow-runs/{uuid}/resume`
 * (`lib/Controller/FlowRunController.php:423-436`): there, knowing a run
 * uuid was the whole check. Here, no verb is reachable by uuid alone, and
 * even READS are visibility-checked. A task the caller may not see answers
 * 404 to every route, verbs included, so neither its existence nor its
 * state leaks through a 403 or a 409.
 *
 * CSRF, decided rather than inherited: every mutating verb carries
 * `#[NoCSRFRequired]`, matching `FlowRunController::resume()`. These routes
 * are driven by leaf apps and agents with Basic auth or app passwords, for
 * which Nextcloud issues no CSRF token; the browser-session guard is
 * Nextcloud's SameSite cookie middleware (`SameSiteCookieMiddleware`),
 * which refuses a cross-site request before the controller runs. The
 * authorization inside the service is what decides who may act; the
 * attribute only says which anti-forgery mechanism applies.
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
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskInboxCriteria;
use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Exception\TaskConflictException;
use OCA\OpenRegister\Exception\TaskValidationException;
use OCA\OpenRegister\Service\Task\TaskAuthorizationService;
use OCA\OpenRegister\Service\Task\TaskInboxService;
use OCA\OpenRegister\Service\Task\TaskService;
use OCA\OpenRegister\Service\Task\TaskTemporalProjection;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IURLGenerator;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * REST surface for the fleet-generic task.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) One route method per
 * lifecycle verb the spec names, plus the three reads. Folding verbs into a
 * mode parameter is how per-verb authorization rules get lost.
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) One collaborator per
 * concern, injected; the tenth builds the deep link the projections carry.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The controller mediates
 * between HTTP and the task services plus their three exception shapes;
 * that is the whole of its job.
 *
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */
class TaskController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param TaskService $tasks The authorized lifecycle.
	 * @param TaskInboxService $inbox The inbox queries.
	 * @param TaskAuthorizationService $authorization Read-visibility decisions.
	 * @param TaskTemporalProjection $temporal The one overdue derivation.
	 * @param IUserSession $userSession Names the acting identity.
	 * @param LoggerInterface|null $logger Where an unexpected failure's
	 *                                     detail goes, INSTEAD of the response.
	 * @param IGroupManager|null $groupManager Resolves the caller's groups
	 *                                         and admin status for inbox
	 *                                         visibility. Nullable so the
	 *                                         controller stays constructible
	 *                                         bare; absent means no groups
	 *                                         and not admin, which SCOPES
	 *                                         rather than widens.
	 * @param IURLGenerator|null $urlGenerator Builds the app link the open
	 *                                         route redirects into.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly TaskService $tasks,
		private readonly TaskInboxService $inbox,
		private readonly TaskAuthorizationService $authorization,
		private readonly TaskTemporalProjection $temporal,
		private readonly IUserSession $userSession,
		private readonly ?LoggerInterface $logger = null,
		private readonly ?IGroupManager $groupManager = null,
		private readonly ?IURLGenerator $urlGenerator = null,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * The deep link every projection carries: a page route that lands a
	 * person on the task's own surface.
	 *
	 * The notification actions, the VTODO `URL` and the route action in the
	 * task rules all resolve to THIS route, so there is one stable address
	 * for "open this task" however the task reached the person. It redirects
	 * into the OpenRegister app's task route; the form itself is
	 * `flow-task-forms`' surface, and lands under the same hash.
	 *
	 * @param string $uuid The task uuid.
	 *
	 * @return RedirectResponse Into the app.
	 *
	 * @no-admin-idor-exempt Reads nothing: the uuid is only interpolated into
	 *   a redirect to the app's task route, where the SPA performs the
	 *   visibility-checked read (TaskController::show, 404 for the invisible).
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-assigned-task-appears-in-the-assignees-own-calendar
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function open(string $uuid): RedirectResponse {
		$safeUuid = rawurlencode($uuid);
		$base = '/index.php/apps/openregister/';
		if ($this->urlGenerator !== null) {
			$base = $this->urlGenerator->linkToRoute('openregister.dashboard.page');
		}

		return new RedirectResponse(rtrim($base, '/') . '/#/flow-tasks/' . $safeUuid);
	}//end open()

	/**
	 * The inbox: what is waiting for me, with subject context and a total.
	 *
	 * @param string $scope assigned|pooled|watched|all.
	 * @param string|null $state Restrict to CMMN states (comma-separated).
	 * @param string|null $isTerminal 'true'|'false' to restrict on terminality.
	 * @param string|null $priority Restrict to one priority.
	 * @param string|null $objectUuid Restrict to tasks anchored to this object.
	 * @param string|null $overdue 'true' to restrict to derived-overdue tasks.
	 * @param string $sort dueAt|priority|created. A leading `-` inverts
	 *                     the order (`-dueAt`), so sort and direction travel
	 *                     as one parameter.
	 * @param int $limit Page size.
	 * @param int $offset Page offset.
	 *
	 * @return JSONResponse The page: results, total, limit, offset.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(
		string $scope = TaskInboxCriteria::SCOPE_ASSIGNED,
		?string $state = null,
		?string $isTerminal = null,
		?string $priority = null,
		?string $objectUuid = null,
		?string $overdue = null,
		string $sort = TaskInboxCriteria::SORT_DUE,
		int $limit = 25,
		int $offset = 0,
	): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return new JSONResponse(['error' => 'No session'], Http::STATUS_UNAUTHORIZED);
		}

		$states = [];
		if ($state !== null && trim($state) !== '') {
			$states = array_values(array_filter(array_map('trim', explode(',', $state))));
		}

		$terminalFilter = null;
		if ($isTerminal !== null) {
			$terminalFilter = filter_var($isTerminal, FILTER_VALIDATE_BOOLEAN);
		}

		// The derived-overdue filter takes its clock instant from the one
		// derivation class, so filter and projection cannot disagree.
		$overdueAt = null;
		if ($overdue !== null && filter_var($overdue, FILTER_VALIDATE_BOOLEAN) === true) {
			$overdueAt = $this->temporal->now();
		}

		$descending = str_starts_with($sort, '-');
		$sortKey = ltrim($sort, '-');

		$criteria = new TaskInboxCriteria(
			uid: $uid,
			groupIds: $this->groupIds(uid: $uid),
			isAdmin: $this->isAdmin(uid: $uid),
			scope: $scope,
			states: $states,
			isTerminal: $terminalFilter,
			priority: $priority,
			objectUuid: $objectUuid,
			overdueAt: $overdueAt,
			sort: $sortKey,
			sortDescending: $descending,
		);

		return new JSONResponse($this->inbox->inbox(criteria: $criteria, limit: $limit, offset: $offset));
	}//end index()

	/**
	 * One task, visibility-checked.
	 *
	 * @param string $uuid The task uuid.
	 *
	 * @return JSONResponse The task row; 404 when absent OR invisible.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(string $uuid): JSONResponse {
		try {
			$task = $this->tasks->get(uuid: $uuid);
		} catch (DoesNotExistException) {
			return new JSONResponse(['error' => 'No such task'], Http::STATUS_NOT_FOUND);
		}

		if ($this->authorization->mayRead(task: $task, uid: $this->uid()) === false) {
			// Not 403: a caller with no relationship to the task learns
			// nothing, not even that the uuid exists.
			return new JSONResponse(['error' => 'No such task'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(
			$this->inbox->row(task: $task, subjects: [], now: $this->temporal->now())
		);
	}//end show()

	/**
	 * A task's audit trail, visibility-checked.
	 *
	 * @param string $uuid The task uuid.
	 *
	 * @return JSONResponse The entries, oldest first.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-task-audit-is-append-only-and-names-the-performer-type
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function audit(string $uuid): JSONResponse {
		try {
			$task = $this->tasks->get(uuid: $uuid);
		} catch (DoesNotExistException) {
			return new JSONResponse(['error' => 'No such task'], Http::STATUS_NOT_FOUND);
		}

		if ($this->authorization->mayRead(task: $task, uid: $this->uid()) === false) {
			// Not 403: a caller with no relationship to the task learns
			// nothing, not even that the uuid exists.
			return new JSONResponse(['error' => 'No such task'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['results' => $this->tasks->auditTrail(uuid: $uuid)]);
	}//end audit()

	/**
	 * Create a task.
	 *
	 * @return JSONResponse The created task, or a named refusal.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function create(): JSONResponse {
		return $this->respondWith(
			verb: fn (): Task => $this->tasks->create(data: $this->body(), actor: $this->uid()),
			successStatus: Http::STATUS_CREATED
		);
	}//end create()

	/**
	 * Offer a task to a candidate pool.
	 *
	 * @param string $uuid The task uuid.
	 *
	 * @return JSONResponse The offered task, or a named refusal.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-performer-model-spans-people-groups-agents-and-workers
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function offer(string $uuid): JSONResponse {
		return $this->respondWith(
			verb: fn (): Task => $this->tasks->offer(uuid: $uuid, pool: $this->body(), actor: $this->uid()),
			uuid: $uuid
		);
	}//end offer()

	/**
	 * Claim a pooled task.
	 *
	 * @param string $uuid The task uuid.
	 *
	 * @return JSONResponse The claimed task; 409 for the race's loser.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function claim(string $uuid): JSONResponse {
		return $this->respondWith(
			verb: fn (): Task => $this->tasks->claim(uuid: $uuid, actor: $this->uid()),
			uuid: $uuid
		);
	}//end claim()

	/**
	 * Return a claimed task to its pool.
	 *
	 * @param string $uuid The task uuid.
	 *
	 * @return JSONResponse The pooled task, or a named refusal.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function unclaim(string $uuid): JSONResponse {
		return $this->respondWith(
			verb: fn (): Task => $this->tasks->unclaim(uuid: $uuid, actor: $this->uid()),
			uuid: $uuid
		);
	}//end unclaim()

	/**
	 * Assign a task directly.
	 *
	 * @param string $uuid The task uuid.
	 * @param string $assignee The performer reference.
	 *
	 * @return JSONResponse The assigned task, or a named refusal.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function assign(string $uuid, string $assignee = ''): JSONResponse {
		return $this->respondWith(
			verb: fn (): Task => $this->tasks->assign(uuid: $uuid, assignee: $assignee, actor: $this->uid()),
			uuid: $uuid
		);
	}//end assign()

	/**
	 * Reassign a task.
	 *
	 * @param string $uuid The task uuid.
	 * @param string $assignee The new performer reference.
	 *
	 * @return JSONResponse The reassigned task, or a named refusal.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function reassign(string $uuid, string $assignee = ''): JSONResponse {
		return $this->respondWith(
			verb: fn (): Task => $this->tasks->reassign(uuid: $uuid, assignee: $assignee, actor: $this->uid()),
			uuid: $uuid
		);
	}//end reassign()

	/**
	 * Delegate a task, with a mandate.
	 *
	 * @param string $uuid The task uuid.
	 * @param string $delegate The identity taking over.
	 * @param string $mandate The authority relied on.
	 *
	 * @return JSONResponse The delegated task, or a named refusal.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-performer-model-spans-people-groups-agents-and-workers
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function delegate(string $uuid, string $delegate = '', string $mandate = ''): JSONResponse {
		return $this->respondWith(
			verb: fn (): Task => $this->tasks->delegate(uuid: $uuid, delegate: $delegate, mandate: $mandate, actor: $this->uid()),
			uuid: $uuid
		);
	}//end delegate()

	/**
	 * Resolve a task.
	 *
	 * @param string $uuid The task uuid.
	 * @param string|null $resultText Free-text result.
	 * @param string|null $comment Completion comment.
	 *
	 * @return JSONResponse The resolved task, or a named refusal.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function resolve(string $uuid, ?string $resultText = null, ?string $comment = null): JSONResponse {
		return $this->respondWith(
			verb: fn (): Task => $this->tasks->resolve(uuid: $uuid, resultText: $resultText, comment: $comment, actor: $this->uid()),
			uuid: $uuid
		);
	}//end resolve()

	/**
	 * Complete a task with an explicit outcome.
	 *
	 * @param string $uuid The task uuid.
	 * @param string $outcome The completion outcome.
	 * @param string|null $resultText Free-text result.
	 * @param string|null $comment Completion comment — MANDATORY on a
	 *                             rejecting or returning outcome.
	 *
	 * @return JSONResponse The completed task, or a named refusal.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function complete(string $uuid, string $outcome = 'done', ?string $resultText = null, ?string $comment = null): JSONResponse {
		return $this->respondWith(
			verb: fn (): Task => $this->tasks->complete(
				uuid: $uuid,
				outcome: $outcome,
				resultText: $resultText,
				comment: $comment,
				actor: $this->uid()
			),
			uuid: $uuid
		);
	}//end complete()

	/**
	 * Cancel a task.
	 *
	 * @param string $uuid The task uuid.
	 * @param string|null $reason Why, recorded on task and audit.
	 *
	 * @return JSONResponse The terminated task, or a named refusal.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function cancel(string $uuid, ?string $reason = null): JSONResponse {
		return $this->respondWith(
			verb: fn (): Task => $this->tasks->cancel(uuid: $uuid, reason: $reason, actor: $this->uid()),
			uuid: $uuid
		);
	}//end cancel()

	/**
	 * Check or uncheck one checklist item, addressed by id.
	 *
	 * @param string $uuid The task uuid.
	 * @param string $itemId The checklist item id.
	 * @param mixed $checked The new checked value, read as a boolean
	 *                       ('true'/'false'/1/0 all parse).
	 *
	 * @return JSONResponse The task with the one item changed.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-a-templated-task-freezes-its-template-at-creation
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function checkItem(string $uuid, string $itemId, mixed $checked = 'true'): JSONResponse {
		$value = filter_var($checked, FILTER_VALIDATE_BOOLEAN);

		return $this->respondWith(
			verb: fn (): Task => $this->tasks->checkChecklistItem(uuid: $uuid, itemId: $itemId, checked: $value, actor: $this->uid()),
			uuid: $uuid
		);
	}//end checkItem()

	/**
	 * Run a verb and translate its refusals to HTTP, uniformly.
	 *
	 * One translation so no verb can drift: validation 400; denial 403 for
	 * a caller who may READ the task and 404 for one who may not (so a
	 * stranger cannot confirm a uuid by probing a verb); conflict 409 (the
	 * current state in the message, per the spec); absence 404; everything
	 * else a LOGGED 500 with a generic message, never the exception text,
	 * which for a database failure carries SQL and bound parameters.
	 *
	 * @param callable(): Task $verb The service call.
	 * @param int $successStatus The status of the happy path.
	 * @param string|null $uuid The task acted on, for the denial mapping.
	 *
	 * @return JSONResponse The task, or the refusal.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	private function respondWith(callable $verb, int $successStatus = Http::STATUS_OK, ?string $uuid = null): JSONResponse {
		try {
			$task = $verb();

			return new JSONResponse(
				$this->inbox->row(task: $task, subjects: [], now: $this->temporal->now()),
				$successStatus
			);
		} catch (TaskValidationException $refused) {
			return new JSONResponse(['error' => $refused->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (TaskAccessDeniedException $denied) {
			if ($uuid !== null && $this->mayReadUuid(uuid: $uuid) === false) {
				return new JSONResponse(['error' => 'No such task'], Http::STATUS_NOT_FOUND);
			}

			return new JSONResponse(['error' => $denied->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (TaskConflictException $conflict) {
			return new JSONResponse(['error' => $conflict->getMessage()], Http::STATUS_CONFLICT);
		} catch (DoesNotExistException) {
			return new JSONResponse(['error' => 'No such task'], Http::STATUS_NOT_FOUND);
		} catch (Throwable $failure) {
			$this->logger?->error(
				'[TaskController] Task operation failed: ' . $failure->getMessage(),
				['uuid' => $uuid, 'exception' => $failure]
			);

			return new JSONResponse(['error' => 'The task operation failed. The details are in the server log.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try
	}//end respondWith()

	/**
	 * Whether the caller may read the task with this uuid, fail-closed.
	 *
	 * @param string $uuid The task uuid.
	 *
	 * @return boolean False when absent, invisible or undeterminable.
	 */
	private function mayReadUuid(string $uuid): bool {
		try {
			return $this->authorization->mayRead(task: $this->tasks->get(uuid: $uuid), uid: $this->uid());
		} catch (Throwable) {
			return false;
		}
	}//end mayReadUuid()

	/**
	 * The acting identity, or null without a session.
	 *
	 * @return string|null The uid.
	 */
	private function uid(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		return $user->getUID();
	}//end uid()

	/**
	 * The caller's group ids — empty without a backend, which scopes.
	 *
	 * @param string $uid The caller.
	 *
	 * @return array<int, string> The group ids.
	 */
	private function groupIds(string $uid): array {
		$user = $this->userSession->getUser();
		if ($this->groupManager === null || $user === null || $user->getUID() !== $uid) {
			return [];
		}

		try {
			return $this->groupManager->getUserGroupIds($user);
		} catch (Throwable) {
			return [];
		}
	}//end groupIds()

	/**
	 * Whether the caller is an administrator — false without a backend.
	 *
	 * @param string $uid The caller.
	 *
	 * @return boolean True only when the backend affirms it.
	 */
	private function isAdmin(string $uid): bool {
		if ($this->groupManager === null) {
			return false;
		}

		try {
			return $this->groupManager->isAdmin($uid);
		} catch (Throwable) {
			return false;
		}
	}//end isAdmin()

	/**
	 * The request body, the way the framework already parsed it.
	 *
	 * @return array<string, mixed> The parameters.
	 */
	private function body(): array {
		$params = $this->request->getParams();
		// Route bookkeeping is not task data.
		unset($params['_route'], $params['uuid']);

		return $params;
	}//end body()
}//end class
