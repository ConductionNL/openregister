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

use Exception;
use OCA\OpenRegister\Db\TaskInboxCriteria;
use OCA\OpenRegister\Exception\NoVtodoCalendarException;
use OCA\OpenRegister\Exception\TaskValidationException;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Task\TaskInboxService;
use OCA\OpenRegister\Service\Task\TaskState;
use OCA\OpenRegister\Service\Task\TaskTemporalProjection;
use OCA\OpenRegister\Service\TaskService;
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
 */
class TasksController extends Controller {

	/**
	 * Task service for CalDAV VTODO operations.
	 *
	 * @var TaskService
	 */
	private readonly TaskService $taskService;

	/**
	 * Object service for object validation.
	 *
	 * @var ObjectService
	 */
	private readonly ObjectService $objectService;

	/**
	 * Constructor.
	 *
	 * @param string $appName Application name
	 * @param IRequest $request HTTP request object
	 * @param TaskService $taskService Task service for VTODO operations
	 * @param ObjectService $objectService Object service for object validation
	 * @param TaskInboxService|null $inbox The engine inbox the aggregate answers from;
	 *                                     without it the aggregate refuses (401), never
	 *                                     falls back to walking calendars
	 * @param IUserSession|null $userSession Names the caller for the aggregate
	 * @param TaskTemporalProjection|null $temporal The one overdue derivation's clock
	 * @param IGroupManager|null $groupManager Resolves the caller's groups and admin
	 *                                         status; absent means no groups and not
	 *                                         admin, which scopes rather than widens
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		TaskService $taskService,
		ObjectService $objectService,
		private readonly ?TaskInboxService $inbox = null,
		private readonly ?IUserSession $userSession = null,
		private readonly ?TaskTemporalProjection $temporal = null,
		private readonly ?IGroupManager $groupManager = null,
	) {
		parent::__construct(appName: $appName, request: $request);

		$this->taskService = $taskService;
		$this->objectService = $objectService;
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
		$uid = $this->userSession?->getUser()?->getUID();
		if ($this->inbox === null || $uid === null || trim($uid) === '') {
			return new JSONResponse(data: ['error' => 'No session'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$limit = min((int)($this->request->getParam('_limit') ?? $this->request->getParam('limit') ?? 50), 200);
		$offset = (int)($this->request->getParam('_offset') ?? $this->request->getParam('offset') ?? 0);
		$scope = (string)($this->request->getParam('scope') ?? TaskInboxCriteria::SCOPE_ALL);

		try {
			$states = $this->requestedStates();
		} catch (TaskValidationException $refused) {
			return new JSONResponse(data: ['error' => $refused->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		$overdueAt = null;
		$overdue = $this->request->getParam('overdue');
		if ($overdue !== null && filter_var($overdue, FILTER_VALIDATE_BOOLEAN) === true) {
			$overdueAt = ($this->temporal ?? new TaskTemporalProjection())->now();
		}

		$sort = (string)($this->request->getParam('sort') ?? TaskInboxCriteria::SORT_DUE);

		try {
			$criteria = new TaskInboxCriteria(
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

			return new JSONResponse(data: $this->inbox->inbox(criteria: $criteria, limit: $limit, offset: $offset));
		} catch (Throwable $failure) {
			return new JSONResponse(
				data: ['error' => $failure->getMessage()],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end allUserTasks()

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
		if ($this->groupManager === null || $user === null) {
			return [];
		}

		try {
			return $this->groupManager->getUserGroupIds($user);
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
	public function index(
		string $register,
		string $schema,
		string $id,
	): JSONResponse {
		try {
			$object = $this->validateObject(register: $register, schema: $schema, id: $id);
			if ($object === null) {
				return new JSONResponse(
					data: ['error' => 'Object not found'],
					statusCode: 404
				);
			}

			$tasks = $this->taskService->getTasksForObject($object->getUuid());

			return new JSONResponse(data: ['results' => $tasks, 'total' => count($tasks)]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
		} catch (NoVtodoCalendarException $e) {
			// No VTODO calendar = no tasks; return empty for listing.
			return new JSONResponse(data: ['results' => [], 'total' => 0]);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
		}//end try
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
	public function create(
		string $register,
		string $schema,
		string $id,
	): JSONResponse {
		try {
			$object = $this->validateObject(register: $register, schema: $schema, id: $id);
			if ($object === null) {
				return new JSONResponse(
					data: ['error' => 'Object not found'],
					statusCode: 404
				);
			}

			$data = $this->request->getParams();

			// Validate required fields.
			if (empty($data['summary']) === true) {
				return new JSONResponse(
					data: ['error' => 'Task summary is required'],
					statusCode: 400
				);
			}

			$task = $this->taskService->createTask(
				registerId: (int)$object->getRegister(),
				schemaId: (int)$object->getSchema(),
				objectUuid: $object->getUuid(),
				objectTitle: $object->getName() ?? $object->getUuid(),
				data: $data
			);

			return new JSONResponse(data: $task, statusCode: 201);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
		}//end try
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
	public function update(
		string $register,
		string $schema,
		string $id,
		string $taskId,
	): JSONResponse {
		try {
			$object = $this->validateObject(register: $register, schema: $schema, id: $id);
			if ($object === null) {
				return new JSONResponse(
					data: ['error' => 'Object not found'],
					statusCode: 404
				);
			}

			$data = $this->request->getParams();

			// The taskId from the URL is the task URI. We need the calendarId too.
			$calendarId = $data['calendarId'] ?? null;
			if ($calendarId === null) {
				// Try to find the task in the user's calendar to get the calendarId.
				$tasks = $this->taskService->getTasksForObject($object->getUuid());
				foreach ($tasks as $existingTask) {
					if ($existingTask['id'] === $taskId) {
						$calendarId = $existingTask['calendarId'];
						break;
					}
				}
			}

			if ($calendarId === null) {
				return new JSONResponse(
					data: ['error' => 'Task not found'],
					statusCode: 404
				);
			}

			$task = $this->taskService->updateTask($calendarId, $taskId, $data);

			return new JSONResponse(data: $task);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
		}//end try
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
	public function destroy(
		string $register,
		string $schema,
		string $id,
		string $taskId,
	): JSONResponse {
		try {
			$object = $this->validateObject(register: $register, schema: $schema, id: $id);
			if ($object === null) {
				return new JSONResponse(
					data: ['error' => 'Object not found'],
					statusCode: 404
				);
			}

			// Find the task to get its calendarId.
			$tasks = $this->taskService->getTasksForObject($object->getUuid());
			$calendarId = null;
			foreach ($tasks as $existingTask) {
				if ($existingTask['id'] === $taskId) {
					$calendarId = $existingTask['calendarId'];
					break;
				}
			}

			if ($calendarId === null) {
				return new JSONResponse(
					data: ['error' => 'Task not found'],
					statusCode: 404
				);
			}

			$this->taskService->deleteTask($calendarId, $taskId);

			return new JSONResponse(data: ['success' => true]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
		}//end try
	}//end destroy()

	/**
	 * Validate that the object exists and return it.
	 *
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param string $id The object ID
	 *
	 * @return \OCA\OpenRegister\Db\ObjectEntity|null The object or null
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
	): ?\OCA\OpenRegister\Db\ObjectEntity {
		$this->objectService->setSchema($schema);
		$this->objectService->setRegister($register);
		$this->objectService->setObject($id);

		return $this->objectService->getObject();
	}//end validateObject()
}//end class
