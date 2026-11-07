<?php

/**
 * TasksController
 *
 * REST controller for CalDAV task operations on OpenRegister objects.
 * Follows the FilesController pattern for sub-resource endpoints.
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
 *
 * @spec openspec/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use DateTime;
use Exception;
use OCA\OpenRegister\Controller\Support\TasksControllerServices;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\TaskInboxCriteria;
use OCA\OpenRegister\Exception\NoVtodoCalendarException;
use OCA\OpenRegister\Exception\TaskValidationException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Task\TaskInboxService;
use OCA\OpenRegister\Service\Task\TaskState;
use OCA\OpenRegister\Service\Task\TaskTemporalProjection;
use OCA\OpenRegister\Kernel\Contracts\ObjectCalendarTasksInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * TasksController handles task operations for objects in registers.
 *
 * Provides REST API endpoints for managing CalDAV tasks (VTODOs)
 * associated with OpenRegister objects.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The controller now serves
 * two stores: the CalDAV leaf for standalone VTODOs and the engine inbox for
 * the aggregate, plus the three refusal shapes each can produce.
 * @SuppressWarnings(PHPMD.StaticAccess) TaskState is the stateless published
 * status mapping.
 */
class TasksController extends Controller {

	/**
	 * Task service for CalDAV VTODO operations.
	 *
	 * @var ObjectCalendarTasksInterface
	 */
	private readonly ObjectCalendarTasksInterface $taskService;

	/**
	 * Object service for object validation.
	 *
	 * @var ObjectOperationsInterface
	 */
	private readonly ObjectOperationsInterface $objectService;

	/**
	 * The engine inbox the aggregate answers from;
	 *
	 * @var ?TaskInboxService
	 */
	private readonly ?TaskInboxService $inbox;

	/**
	 * Names the caller for the aggregate
	 *
	 * @var ?IUserSession
	 */
	private readonly ?IUserSession $userSession;

	/**
	 * The one overdue derivation's clock
	 *
	 * @var ?TaskTemporalProjection
	 */
	private readonly ?TaskTemporalProjection $temporal;

	/**
	 * Resolves the caller's groups and admin
	 *
	 * @var ?IGroupManager
	 */
	private readonly ?IGroupManager $groupManager;

	/**
	 * Constructor.
	 *
	 * @param string $appName Application name
	 * @param IRequest $request HTTP request object
	 * @param TasksControllerServices $services The tasks controller services.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		TasksControllerServices $services,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->inbox = $services->domain->inbox;
		$this->userSession = $services->platform->userSession;
		$this->temporal = $services->domain->temporal;
		$this->groupManager = $services->platform->groupManager;

		$this->taskService = $services->domain->taskService;
		$this->objectService = $services->domain->objectService;
	}//end __construct()

	/**
	 * The user-wide task aggregate: what the session user owes, from the
	 * engine inbox.
	 *
	 * Answered by TaskInboxService, never by walking calendars: visibility,
	 * filter, sort, page and total are the query's, so the total cannot
	 * reveal a task the caller may not see and a page cannot silently drop
	 * rows. The caller is resolved from the session, never from a request
	 * parameter, so no parameter can widen the read to another user.
	 *
	 * `assignee` is NO LONGER accepted as a filter: the aggregate is already
	 * scoped to the caller, and the free-text description prose it used to
	 * match no longer exists. `status` (legacy) and `state` both name a
	 * lifecycle state through the published TaskState mapping; an unmapped
	 * value is refused with 400 rather than silently ignored.
	 *
	 * Authorization: session-scoped (ADR-005 Rule 3), enforced in the inbox
	 * query's WHERE clause.
	 *
	 * @return JSONResponse The page: results, total, limit, offset
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @no-admin-idor-exempt Session-scoped list: TaskInboxService::inbox() narrows visibility to the
	 *   IUserSession UID inside the query; no request parameter names another user.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/object-interactions/spec.md#requirement-user-wide-task-aggregate-endpoint
	 */
	public function allUserTasks(): JSONResponse {
		$uid = $this->sessionUid();
		if ($uid === null) {
			return new JSONResponse(data: ['error' => 'No session'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$window = $this->pageWindow();
		$scope = (string)($this->request->getParam('scope') ?? TaskInboxCriteria::SCOPE_ALL);

		try {
			$states = $this->requestedStates();
		} catch (TaskValidationException $refused) {
			return new JSONResponse(data: ['error' => $refused->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return $this->inboxPage(uid: $uid, window: $window, scope: $scope, states: $states);
	}//end allUserTasks()

	/**
	 * The session user's uid when the aggregate can answer, else null.
	 *
	 * Null without an inbox, a session user or a non-blank uid: each is
	 * answered with the same 401 before any parameter is read.
	 *
	 * @return string|null The uid.
	 */
	private function sessionUid(): ?string {
		$uid = $this->userSession?->getUser()?->getUID();
		if ($this->inbox === null || $uid === null || trim($uid) === '') {
			return null;
		}

		return $uid;
	}//end sessionUid()

	/**
	 * The requested page window: limit capped at 200, offset.
	 *
	 * @return array{limit: int, offset: int} The window.
	 */
	private function pageWindow(): array {
		$limit = min((int)($this->request->getParam('_limit') ?? $this->request->getParam('limit') ?? 50), 200);
		$offset = (int)($this->request->getParam('_offset') ?? $this->request->getParam('offset') ?? 0);

		return ['limit' => $limit, 'offset' => $offset];
	}//end pageWindow()

	/**
	 * Answer one inbox page for the caller.
	 *
	 * @param string                         $uid    The caller.
	 * @param array{limit: int, offset: int} $window The page window.
	 * @param string                         $scope  The requested scope.
	 * @param array<int, string>             $states The state filter.
	 *
	 * @return JSONResponse The page, or 500 with the failure's message.
	 */
	private function inboxPage(string $uid, array $window, string $scope, array $states): JSONResponse {
		$overdueAt = $this->overdueAt();
		$sort = (string)($this->request->getParam('sort') ?? TaskInboxCriteria::SORT_DUE);

		try {
			$criteria = $this->criteria(uid: $uid, scope: $scope, states: $states, overdueAt: $overdueAt, sort: $sort);

			return new JSONResponse(
				data: $this->inbox->inbox(criteria: $criteria, limit: $window['limit'], offset: $window['offset'])
			);
		} catch (Throwable $failure) {
			return new JSONResponse(
				data: ['error' => $failure->getMessage()],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}//end inboxPage()

	/**
	 * The inbox query for the caller, with the caller's groups and admin fact.
	 *
	 * @param string             $uid       The caller.
	 * @param string             $scope     The requested scope.
	 * @param array<int, string> $states    The state filter.
	 * @param DateTime|null      $overdueAt The overdue cutoff, when filtered.
	 * @param string             $sort      The sort key, `-` prefixed for descending.
	 *
	 * @return TaskInboxCriteria The criteria.
	 */
	private function criteria(string $uid, string $scope, array $states, ?DateTime $overdueAt, string $sort): TaskInboxCriteria {
		return new TaskInboxCriteria(
			uid: $uid,
			groupIds: $this->groupIds(),
			isAdmin: $this->isAdmin(uid: $uid),
			scope: $scope,
			states: $states,
			isTerminal: $this->requestedTerminal(),
			overdueAt: $overdueAt,
			sort: ltrim($sort, '-'),
			sortDescending: str_starts_with($sort, '-'),
		);
	}//end criteria()

	/**
	 * The overdue cutoff when the request asks for overdue tasks only.
	 *
	 * @return DateTime|null The cutoff, or null for no overdue filter.
	 */
	private function overdueAt(): ?DateTime {
		$overdue = $this->request->getParam('overdue');
		if ($overdue !== null && filter_var($overdue, FILTER_VALIDATE_BOOLEAN) === true) {
			return ($this->temporal ?? new TaskTemporalProjection())->now();
		}

		return null;
	}//end overdueAt()

	/**
	 * The lifecycle states the request filters on, through the published mapping.
	 *
	 * @return array<int, string> Canonical CMMN states; empty means no state filter
	 *
	 * @throws TaskValidationException When a value is in no known vocabulary
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/object-interactions/spec.md#requirement-user-wide-task-aggregate-endpoint
	 */
	private function requestedStates(): array {
		$raw = ($this->request->getParam('state') ?? $this->request->getParam('status'));
		if (is_string($raw) === false || trim($raw) === '') {
			return [];
		}

		$states = [];
		foreach (array_filter(array_map('trim', explode(',', $raw))) as $value) {
			$states[] = TaskState::normalise(value: $value)['state'];
		}

		return array_values(array_unique($states));
	}//end requestedStates()

	/**
	 * The terminality filter, when the request names one.
	 *
	 * @return bool|null True/false to restrict; null for both
	 */
	private function requestedTerminal(): ?bool {
		$raw = $this->request->getParam('isTerminal');
		if ($raw === null) {
			return null;
		}

		return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
	}//end requestedTerminal()

	/**
	 * The caller's group ids, or none without a backend (which scopes).
	 *
	 * @return array<int, string> The group ids
	 */
	private function groupIds(): array {
		$user = $this->userSession?->getUser();
		if ($user === null) {
			return [];
		}

		try {
			return ($this->groupManager?->getUserGroupIds($user) ?? []);
		} catch (Throwable) {
			return [];
		}
	}//end groupIds()

	/**
	 * Whether the caller is an administrator; false without a backend.
	 *
	 * @param string $uid The caller
	 *
	 * @return bool True only when the backend affirms it
	 */
	private function isAdmin(string $uid): bool {
		try {
			return ($this->groupManager?->isAdmin($uid) === true);
		} catch (Throwable) {
			return false;
		}
	}//end isAdmin()

	/**
	 * List all tasks linked to a specific object.
	 *
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param string $id The ID of the object
	 *
	 * @return JSONResponse JSON response with tasks list
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function index(string $register, string $schema, string $id): JSONResponse {
		return $this->onObject(
			path: [$register, $schema, $id],
			action: fn (ObjectEntity $object): JSONResponse => $this->listing(
				tasks: $this->taskService->getTasksForObject($object->getUuid())
			),
			failure: $this->listFailure(...)
		);
	}//end index()

	/**
	 * Create a new task linked to a specific object.
	 *
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param string $id The ID of the object
	 *
	 * @return JSONResponse JSON response with the created task
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function create(string $register, string $schema, string $id): JSONResponse {
		return $this->onObject(
			path: [$register, $schema, $id],
			action: $this->createFor(...),
			failure: fn (Exception $e): JSONResponse => $this->failure(e: $e, status: Http::STATUS_BAD_REQUEST)
		);
	}//end create()

	/**
	 * Update an existing task.
	 *
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param string $id The ID of the object
	 * @param string $taskId The URI of the task to update
	 *
	 * @return JSONResponse JSON response with the updated task
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function update(string $register, string $schema, string $id, string $taskId): JSONResponse {
		return $this->onObject(
			path: [$register, $schema, $id],
			action: fn (ObjectEntity $object): JSONResponse => $this->updateFor(object: $object, taskId: $taskId),
			failure: $this->writeFailure(...)
		);
	}//end update()

	/**
	 * Delete a task.
	 *
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param string $id The ID of the object
	 * @param string $taskId The URI of the task to delete
	 *
	 * @return JSONResponse JSON response confirming deletion
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function destroy(string $register, string $schema, string $id, string $taskId): JSONResponse {
		return $this->onObject(
			path: [$register, $schema, $id],
			action: fn (ObjectEntity $object): JSONResponse => $this->destroyFor(object: $object, taskId: $taskId),
			failure: $this->writeFailure(...)
		);
	}//end destroy()

	/**
	 * Resolve the object, then run the action on it; the object guard runs first.
	 *
	 * An absent object (null or DoesNotExistException) is answered 404 before
	 * any task is touched; any other Exception goes to the verb's own mapping.
	 *
	 * @param array{0: string, 1: string, 2: string} $path    Register, schema and object id.
	 * @param callable                               $action  Receives the ObjectEntity.
	 * @param callable                               $failure Maps an Exception to the response.
	 *
	 * @return JSONResponse The response.
	 */
	private function onObject(array $path, callable $action, callable $failure): JSONResponse {
		try {
			$object = $this->validateObject(register: $path[0], schema: $path[1], id: $path[2]);
			if ($object === null) {
				return $this->objectNotFound();
			}

			return $action($object);
		} catch (DoesNotExistException $e) {
			return $this->objectNotFound();
		} catch (Exception $e) {
			return $failure($e);
		}
	}//end onObject()

	/**
	 * Create a task from the request on the resolved object.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return JSONResponse The created task, or 400 without a summary.
	 */
	private function createFor(ObjectEntity $object): JSONResponse {
		$data = $this->request->getParams();

		// Validate required fields.
		if (empty($data['summary']) === true) {
			return new JSONResponse(data: ['error' => 'Task summary is required'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		$task = $this->taskService->createTask(
			registerId: (int)$object->getRegister(),
			schemaId: (int)$object->getSchema(),
			objectUuid: $object->getUuid(),
			objectTitle: $object->getName() ?? $object->getUuid(),
			data: $data
		);

		return new JSONResponse(data: $task, statusCode: Http::STATUS_CREATED);
	}//end createFor()

	/**
	 * Update one of the object's tasks; the calendar comes from the request or the lookup.
	 *
	 * @param ObjectEntity $object The object.
	 * @param string       $taskId The task URI.
	 *
	 * @return JSONResponse The updated task, or 404.
	 */
	private function updateFor(ObjectEntity $object, string $taskId): JSONResponse {
		$data = $this->request->getParams();

		// The taskId from the URL is the task URI. We need the calendarId too.
		$calendarId = ($data['calendarId'] ?? $this->calendarOf(object: $object, taskId: $taskId));

		return $this->onOwnedTask(
			object: $object,
			taskId: $taskId,
			calendarId: $calendarId,
			operation: fn (): JSONResponse => new JSONResponse(data: $this->taskService->updateTask($calendarId, $taskId, $data))
		);
	}//end updateFor()

	/**
	 * Delete one of the object's tasks, always located through the lookup.
	 *
	 * @param ObjectEntity $object The object.
	 * @param string       $taskId The task URI.
	 *
	 * @return JSONResponse Confirmation, or 404.
	 */
	private function destroyFor(ObjectEntity $object, string $taskId): JSONResponse {
		$calendarId = $this->calendarOf(object: $object, taskId: $taskId);

		return $this->onOwnedTask(
			object: $object,
			taskId: $taskId,
			calendarId: $calendarId,
			operation: function () use ($calendarId, $taskId): JSONResponse {
				$this->taskService->deleteTask($calendarId, $taskId);
				return new JSONResponse(data: ['success' => true]);
			}
		);
	}//end destroyFor()

	/**
	 * Run the operation only once the task is proven to belong to the object.
	 *
	 * @param ObjectEntity $object     The object.
	 * @param string       $taskId     The task URI.
	 * @param mixed        $calendarId The task's calendar, or null when unknown.
	 * @param callable     $operation  The write.
	 *
	 * @return JSONResponse The write's response, or 404 'Task not found'.
	 */
	private function onOwnedTask(ObjectEntity $object, string $taskId, mixed $calendarId, callable $operation): JSONResponse {
		if ($calendarId === null) {
			return new JSONResponse(data: ['error' => 'Task not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		$this->taskService->requireTaskOf((string)$object->getUuid(), (string)$calendarId, $taskId);

		return $operation();
	}//end onOwnedTask()

	/**
	 * The calendar of the object's task with this URI, or null.
	 *
	 * @param ObjectEntity $object The object.
	 * @param string       $taskId The task URI.
	 *
	 * @return mixed The calendar id, or null.
	 */
	private function calendarOf(ObjectEntity $object, string $taskId): mixed {
		foreach ($this->taskService->getTasksForObject($object->getUuid()) as $existingTask) {
			if ($existingTask['id'] === $taskId) {
				return $existingTask['calendarId'];
			}
		}

		return null;
	}//end calendarOf()

	/**
	 * A task list with its count.
	 *
	 * @param array<int, mixed> $tasks The tasks.
	 *
	 * @return JSONResponse The listing.
	 */
	private function listing(array $tasks): JSONResponse {
		return new JSONResponse(data: ['results' => $tasks, 'total' => count($tasks)]);
	}//end listing()

	/**
	 * The listing's failure mapping: no VTODO calendar is an empty list, else 500.
	 *
	 * @param Exception $e The failure.
	 *
	 * @return JSONResponse The response.
	 */
	private function listFailure(Exception $e): JSONResponse {
		if ($e instanceof NoVtodoCalendarException) {
			// No VTODO calendar = no tasks; return empty for listing.
			return $this->listing(tasks: []);
		}

		return $this->failure(e: $e, status: Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end listFailure()

	/**
	 * The write verbs' failure mapping: 404 when the failure says so, else 400.
	 *
	 * @param Exception $e The failure.
	 *
	 * @return JSONResponse The response.
	 */
	private function writeFailure(Exception $e): JSONResponse {
		$status = Http::STATUS_BAD_REQUEST;
		if ($e->getCode() === 404) {
			$status = Http::STATUS_NOT_FOUND;
		}

		return $this->failure(e: $e, status: $status);
	}//end writeFailure()

	/**
	 * A failure answered with its message.
	 *
	 * @param Exception $e      The failure.
	 * @param int       $status The status.
	 *
	 * @return JSONResponse The response.
	 */
	private function failure(Exception $e, int $status): JSONResponse {
		return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: $status);
	}//end failure()

	/**
	 * The 404 an absent object gets.
	 *
	 * @return JSONResponse The refusal.
	 */
	private function objectNotFound(): JSONResponse {
		return new JSONResponse(data: ['error' => 'Object not found'], statusCode: Http::STATUS_NOT_FOUND);
	}//end objectNotFound()

	/**
	 * Validate that the object exists and return it.
	 *
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param string $id The object ID
	 *
	 * @return ObjectEntity|null The object or null
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-4
	 *
	 * @throws DoesNotExistException When no such object exists. Deliberately propagated rather
	 *                               than caught: every call site already wraps this helper and translates it to a 404.
	 *                               Swallowing it here would collapse "no such object" into the same null this method
	 *                               returns for other reasons, which the caller could no longer tell apart.
	 */
	private function validateObject(
		string $register,
		string $schema,
		string $id,
	): ?ObjectEntity {
		$this->objectService->setRegister($register);
		$this->objectService->setSchema($schema);
		$this->objectService->setObject($id);

		return $this->objectService->getObject();
	}//end validateObject()
}//end class
