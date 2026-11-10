<?php

/**
 * TaskService
 *
 * Service that wraps CalDAV VTODO operations for linking tasks to OpenRegister objects.
 * Tasks are stored as standard VTODO items in the user's Nextcloud calendar with
 * X-OPENREGISTER-* properties for linking and an RFC 9253 LINK property.
 *
 * Two classes of VTODO pass through here, keyed on ONE property
 * (flow-task-inbox-projections, design D-7):
 *
 * - STANDALONE VTODOs carry no `X-OPENREGISTER-TASK`. The VTODO is their
 *   store and every method below behaves as it always did.
 * - PROJECTED VTODOs carry `X-OPENREGISTER-TASK`, the engine task uuid. The
 *   engine task row is their store; this service is a projection writer. A
 *   projected VTODO cannot be created here, a status change on one is a
 *   REQUEST through the write-back gate, and deleting one does not cancel
 *   the task.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/specs/object-interactions/spec.md
 * @spec openspec/changes/flow-task-inbox-projections/specs/object-interactions/spec.md#requirement-tasks-on-objects-via-caldav-vtodo
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use Exception;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Exception\NoVtodoCalendarException;
use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectCalendarTasksInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\Task\TaskCalendarProjector;
use OCA\OpenRegister\Service\Task\TaskVtodoWriteBackGate;
use OCA\OpenRegister\Service\Task\VtodoCalendarLocator;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * TaskService wraps CalDAV VTODO operations for OpenRegister objects.
 *
 * Provides methods to create, list, update, and delete CalDAV tasks (VTODOs)
 * that are linked to OpenRegister objects via X-OPENREGISTER-* properties.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Task orchestration requires coordination across multiple services
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The leaf now routes projected
 * VTODOs through the write-back gate beside its own CalDAV collaborators.
 *
 * @spec openspec/specs/object-interactions/spec.md
 */
class TaskService implements ObjectCalendarTasksInterface, UserTaskListInterface {

	/**
	 * Calendar selection by uid, shared with the projector.
	 *
	 * @var VtodoCalendarLocator
	 */
	private readonly VtodoCalendarLocator $calendars;

	/**
	 * Composes, edits and reads the VTODO documents this service stores.
	 *
	 * @var TaskVtodoDocument
	 */
	private readonly TaskVtodoDocument $documents;

	/**
	 * Constructor.
	 *
	 * @param CalDavBackend $calDavBackend CalDAV backend for VTODO operations
	 * @param IUserSession $userSession User session for current user context
	 * @param LoggerInterface $logger Logger for error reporting
	 * @param IURLGenerator $urlGenerator URL generator for deep links
	 * @param TaskVtodoWriteBackGate|null $gate The one path from a projected VTODO into the engine. Nullable so the
	 *                                          service stays constructible bare; without it a projected update is
	 *                                          refused, never applied unchecked (fail closed).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CalDavBackend $calDavBackend,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly IURLGenerator $urlGenerator,
		private readonly ?TaskVtodoWriteBackGate $gate = null,
	) {
		$this->calendars = new VtodoCalendarLocator(calDavBackend: $calDavBackend);
		$this->documents = new TaskVtodoDocument();
	}//end __construct()

	/**
	 * Get all tasks for the current user across all VTODO-supporting calendars.
	 *
	 * Returns all VTODOs (optionally filtered by status) from the user's calendars.
	 * Tasks with X-OPENREGISTER-* properties include linking metadata.
	 *
	 * This walks every calendar and filters in PHP, which is why it no longer
	 * backs `GET /api/tasks` (that aggregate answers from the engine inbox).
	 * It remains the read behind the `nc-task` virtual schema and the
	 * caldav-vtodo object source, both of which project the acting user's own
	 * calendar read-only. No assignee filter exists: an assignee was never
	 * carried on a VTODO as anything but description prose, and the prose is
	 * gone.
	 *
	 * @param string|null $status Optional status filter (e.g. 'needs-action', 'completed')
	 * @param int $limit Maximum number of tasks to return
	 * @param int $offset Number of tasks to skip
	 *
	 * @return array{results: array, total: int} Task results with total count
	 *
	 * @throws Exception If no user is logged in
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-the-projection-carries-a-real-assignee-not-prose
	 */
	public function getAllUserTasks(
		?string $status = null,
		int $limit = 50,
		int $offset = 0,
	): array {
		return $this->listUserTasks(status: $status, query: new ListQuery(limit: $limit, offset: $offset));
	}//end getAllUserTasks()

	/**
	 * One page of the session user's tasks across all VTODO calendars, soonest due first (see getAllUserTasks()).
	 *
	 * @param string|null $status Optional status filter (e.g. 'needs-action', 'completed')
	 * @param ListQuery   $query  The page: limit and offset (no offset is the first task).
	 *
	 * @return array{results: array, total: int} Task results with total count
	 *
	 * @throws Exception If no user is logged in
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-the-projection-carries-a-real-assignee-not-prose
	 */
	public function listUserTasks(?string $status, ListQuery $query): array {
		$allTasks = $this->userTasksByDue(status: $status);

		return ['results' => array_slice($allTasks, ($query->offset ?? 0), $query->limit), 'total' => count($allTasks)];
	}//end listUserTasks()

	/**
	 * Every task of the session user's VTODO calendars, optionally only those with a status, soonest due first.
	 *
	 * @param string|null $status Status filter, compared case-insensitively.
	 *
	 * @return list<array> The tasks.
	 *
	 * @throws Exception If no user is logged in
	 */
	private function userTasksByDue(?string $status): array {
		$allTasks = [];
		foreach ($this->calDavBackend->getCalendarsForUser('principals/users/' . $this->sessionUid()) as $calendar) {
			$allTasks = array_merge($allTasks, $this->vtodoCalendarTasks(calendar: $calendar, status: $status));
		}

		return $this->sortedByDue(tasks: $allTasks);
	}//end userTasksByDue()

	/**
	 * The tasks of a calendar row, or none when the calendar does not support VTODO.
	 *
	 * @param array       $calendar The calendar row.
	 * @param string|null $status   Status filter, compared case-insensitively.
	 *
	 * @return list<array> The tasks.
	 */
	private function vtodoCalendarTasks(array $calendar, ?string $status): array {
		$components = $calendar['{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set'];
		if ($this->calendarSupportsVtodo(components: $components) === false) {
			return [];
		}

		return $this->calendarTasks(calendarId: $calendar['id'], status: $status);
	}//end vtodoCalendarTasks()

	/**
	 * Tasks sorted by due date, soonest first and undated last.
	 *
	 * @param list<array> $tasks The tasks.
	 *
	 * @return list<array> The sorted tasks.
	 */
	private function sortedByDue(array $tasks): array {
		usort(
			array: $tasks,
			callback: fn ($a, $b) => strcmp(($a['due'] ?? '9999-12-31'), ($b['due'] ?? '9999-12-31'))
		);

		return $tasks;
	}//end sortedByDue()

	/**
	 * The tasks of one calendar, optionally only those with a status.
	 *
	 * @param mixed       $calendarId The calendar id.
	 * @param string|null $status     Status filter, compared case-insensitively.
	 *
	 * @return list<array> The tasks.
	 */
	private function calendarTasks(mixed $calendarId, ?string $status): array {
		$tasks = [];
		foreach ($this->calDavBackend->getCalendarObjects($calendarId) as $calendarObject) {
			$task = $this->readTask(at: new TaskLocation(calendarId: $calendarId, uri: $calendarObject['uri']), needle: 'VTODO');
			if ($task !== null && $this->hasStatus(task: $task, status: $status) === true) {
				$tasks[] = $task;
			}
		}

		return $tasks;
	}//end calendarTasks()

	/**
	 * Whether a task has the status filtered on; no filter admits every task.
	 *
	 * @param array       $task   The task.
	 * @param string|null $status Status filter, compared case-insensitively.
	 *
	 * @return bool
	 */
	private function hasStatus(array $task, ?string $status): bool {
		return $status === null || $task['status'] === strtolower($status);
	}//end hasStatus()

	/**
	 * One calendar object as a task, or null when it is gone, empty, not a VTODO mentioning the needle, or unreadable.
	 *
	 * @param TaskLocation $at     The calendar object.
	 * @param string       $needle Text the raw iCalendar data must contain.
	 *
	 * @return array|null The task.
	 */
	private function readTask(TaskLocation $at, string $needle): ?array {
		$calendarData = ($this->calDavBackend->getCalendarObject($at->calendarId, $at->uri)['calendardata'] ?? null);
		if (empty($calendarData) === true || $this->mentionsAll(data: $calendarData, needles: [$needle, 'VTODO']) === false) {
			return null;
		}

		try {
			return $this->vtodoToArray(calendarData: $calendarData, at: $at);
		} catch (Exception $e) {
			$this->logger->warning('Failed to parse calendar object: ' . $e->getMessage(), ['uri' => $at->uri]);
			return null;
		}
	}//end readTask()

	/**
	 * Whether a string contains every needle.
	 *
	 * @param string       $data    The haystack.
	 * @param list<string> $needles The needles.
	 *
	 * @return bool
	 */
	private function mentionsAll(string $data, array $needles): bool {
		$missing = array_filter($needles, static fn (string $needle): bool => str_contains($data, $needle) === false);

		return $missing === [];
	}//end mentionsAll()

	/**
	 * Check whether a calendar supports VTODO components.
	 *
	 * @param mixed $components The supported-calendar-component-set value.
	 *
	 * @return bool True if the calendar supports VTODO.
	 */
	private function calendarSupportsVtodo(mixed $components): bool {
		return $this->calendars->supportsVtodo(components: $components);
	}//end calendarSupportsVtodo()

	/**
	 * Get all tasks linked to a specific OpenRegister object.
	 *
	 * Loads all VTODOs from the user's calendars and filters by
	 * X-OPENREGISTER-OBJECT matching the given object UUID.
	 *
	 * @param string $objectUuid The UUID of the OpenRegister object
	 *
	 * @return array Array of task arrays in JSON-friendly format
	 *
	 * @throws Exception If no user is logged in or no calendar found
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function getTasksForObject(string $objectUuid): array {
		$calendar = $this->findUserCalendar();
		$calendarId = $calendar['id'];

		$tasks = [];
		foreach ($this->calDavBackend->getCalendarObjects($calendarId) as $calendarObject) {
			$task = $this->readTask(at: new TaskLocation(calendarId: $calendarId, uri: $calendarObject['uri']), needle: $objectUuid);
			if ($task !== null && $task['objectUuid'] === $objectUuid) {
				$tasks[] = $this->withTaskDeepLink(task: $task, calendarUri: ($calendar['uri'] ?? null));
			}
		}

		return $tasks;
	}//end getTasksForObject()

	/**
	 * Add the Tasks-app deep link to a task, when one resolves.
	 *
	 * @param array       $task        The parsed task.
	 * @param string|null $calendarUri The CalDAV calendar URI owning the task.
	 *
	 * @return array The task.
	 */
	private function withTaskDeepLink(array $task, ?string $calendarUri): array {
		$deepLink = $this->buildTaskDeepLink(calendarUri: $calendarUri, taskUri: ($task['id'] ?? null));
		if ($deepLink !== null) {
			$task['url'] = $deepLink;
		}

		return $task;
	}//end withTaskDeepLink()

	/**
	 * Build a webroot-aware deep-link to a specific task in the Tasks app.
	 *
	 * The Tasks app uses hash routing:
	 * `/apps/tasks/#/calendars/{calendarUri}/tasks/{taskUri}`.
	 *
	 * Returns null (record gets no `url`) when either URI is missing, rather
	 * than emitting a broken link.
	 *
	 * @param string|null $calendarUri The CalDAV calendar URI owning the task.
	 * @param string|null $taskUri The VTODO object URI (.ics).
	 *
	 * @return string|null The deep-link URL, or null when not resolvable.
	 */
	private function buildTaskDeepLink(?string $calendarUri, ?string $taskUri): ?string {
		$base = $this->deepLinkBase(uris: [$calendarUri, $taskUri]);
		if ($base === null) {
			return null;
		}

		return rtrim($base, '/') . '/#/calendars/' . rawurlencode($calendarUri) . '/tasks/' . rawurlencode($taskUri);
	}//end buildTaskDeepLink()

	/**
	 * The Tasks app's base URL, or null when a URI of the link is missing or empty.
	 *
	 * @param list<string|null> $uris The calendar URI and the task URI.
	 *
	 * @return string|null The base URL.
	 */
	private function deepLinkBase(array $uris): ?string {
		if (in_array(null, $uris, true) === true || in_array('', $uris, true) === true) {
			return null;
		}

		return $this->tasksAppBase();
	}//end deepLinkBase()

	/**
	 * The Tasks app's webroot-aware base URL, or null when its route does not resolve.
	 *
	 * @return string|null The base URL.
	 */
	private function tasksAppBase(): ?string {
		try {
			return $this->urlGenerator->linkToRoute('tasks.page.index');
		} catch (\Throwable $e) {
			return null;
		}
	}//end tasksAppBase()

	/**
	 * Create a new CalDAV task linked to an OpenRegister object.
	 *
	 * Builds a VCALENDAR/VTODO string with X-OPENREGISTER-* properties and
	 * an RFC 9253 LINK property, then stores it in the user's calendar.
	 *
	 * @param int $registerId The register ID to link
	 * @param int $schemaId The schema ID to link
	 * @param string $objectUuid The object UUID to link
	 * @param string $objectTitle The object title for the LINK label
	 * @param array $data Task data: summary, description, priority, due, status
	 *
	 * A payload that carries an engine task identity is REFUSED: a projected
	 * VTODO is created only by the projection, so a VTODO carrying
	 * `X-OPENREGISTER-TASK` always corresponds to a task the engine
	 * authorized into existence.
	 *
	 * @return array|null The created task in JSON-friendly format, or null if the calendar data was not a VTODO
	 *
	 * @throws Exception If no user is logged in or no calendar found
	 * @throws TaskAccessDeniedException If the payload attempts to set an engine task identity
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 * @spec openspec/changes/flow-task-inbox-projections/specs/object-interactions/spec.md#requirement-tasks-on-objects-via-caldav-vtodo
	 */
	public function createTask(int $registerId, int $schemaId, string $objectUuid, string $objectTitle, array $data): ?array {
		$ref = new ObjectRef(register: $registerId, schema: $schemaId, id: $objectUuid);
		return $this->createObjectTask(ref: $ref, draft: new LinkedTaskDraft(objectTitle: $objectTitle, data: $data));
	}//end createTask()

	/**
	 * Create a new CalDAV task linked to an OpenRegister object (see createTask()).
	 *
	 * A payload that carries an engine task identity is REFUSED before anything is read or stored.
	 *
	 * @param ObjectRef       $ref   The object to link: its register id, schema id and uuid.
	 * @param LinkedTaskDraft $draft The object title for the LINK label, and the task data: summary, description, priority, due, status
	 *
	 * @return array|null The created task in JSON-friendly format, or null if the calendar data was not a VTODO
	 *
	 * @throws Exception If no user is logged in or no calendar found
	 * @throws TaskAccessDeniedException If the payload attempts to set an engine task identity
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/object-interactions/spec.md#requirement-tasks-on-objects-via-caldav-vtodo
	 */
	public function createObjectTask(ObjectRef $ref, LinkedTaskDraft $draft): ?array {
		if ($this->carriesEngineIdentity(data: $draft->data) === true) {
			throw new TaskAccessDeniedException(
				message: 'An engine task cannot be created through the object task endpoint; only OpenRegister projects engine tasks into a calendar.'
			);
		}

		$calendarId = $this->findUserCalendar()['id'];
		$link = new ObjectRef(register: (int)$ref->register, schema: (int)$ref->schema, id: $ref->id);
		$document = $this->documents->composeFor(ref: $link, draft: $draft);

		$this->calDavBackend->createCalendarObject($calendarId, $document['uri'], $document['calendarData']);

		return $this->vtodoToArray(calendarData: $document['calendarData'], at: new TaskLocation(calendarId: $calendarId, uri: $document['uri']));
	}//end createObjectTask()

	/**
	 * Update an existing CalDAV task.
	 *
	 * Loads the existing VTODO, applies changes, and saves it back.
	 *
	 * For a PROJECTED VTODO (one carrying `X-OPENREGISTER-TASK`) the VTODO
	 * is not the store: the update is handed to the write-back gate as a
	 * REQUEST against the engine task, authorized like any other caller, and
	 * what is stored afterwards is the engine's own rendering. Without a
	 * gate the update is refused, never applied unchecked.
	 *
	 * @param string $calendarId The calendar ID containing the task
	 * @param string $taskUri The URI of the task to update
	 * @param array $data Fields to update: summary, description, priority, due, status
	 *
	 * @return array|null The updated task in JSON-friendly format, or null if calendar data was not a VTODO
	 *
	 * @throws Exception If the task is not found or update fails
	 * @throws TaskAccessDeniedException When a projected VTODO's change is refused
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-10
	 * @spec openspec/changes/flow-task-inbox-projections/specs/object-interactions/spec.md#requirement-task-compatibility-with-nextcloud-tasks-app
	 */
	public function updateTask(string $calendarId, string $taskUri, array $data): ?array {
		return $this->changeTask(at: new TaskLocation(calendarId: $calendarId, uri: $taskUri), data: $data);
	}//end updateTask()

	/**
	 * Update an existing CalDAV task (see updateTask()).
	 *
	 * @param TaskLocation $at   The calendar and URI of the task to update
	 * @param array        $data Fields to update: summary, description, priority, due, status
	 *
	 * @return array|null The updated task in JSON-friendly format, or null if calendar data was not a VTODO
	 *
	 * @throws Exception If the task is not found or update fails
	 * @throws TaskAccessDeniedException When a projected VTODO's change is refused
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/object-interactions/spec.md#requirement-task-compatibility-with-nextcloud-tasks-app
	 */
	public function changeTask(TaskLocation $at, array $data): ?array {
		$stored = $this->storedCalendarData(at: $at);
		if (TaskCalendarProjector::taskUuidOf(calendarData: (string)$stored) !== null) {
			$projected = new TaskLocation(calendarId: (int)$at->calendarId, uri: $at->uri);
			return $this->updateProjectedTask(task: new StoredTask(at: $projected, calendarData: (string)$stored), data: $data);
		}

		$calendarData = $this->documents->withChanges(existing: $stored, data: $data);
		$this->calDavBackend->updateCalendarObject((int)$at->calendarId, $at->uri, $calendarData);

		return $this->vtodoToArray(calendarData: $calendarData, at: $at);
	}//end changeTask()

	/**
	 * The stored iCalendar data of a task.
	 *
	 * @param TaskLocation $at The calendar and URI of the task
	 *
	 * @return mixed The stored calendar data.
	 *
	 * @throws Exception If the task is not found
	 */
	private function storedCalendarData(TaskLocation $at): mixed {
		$existing = $this->calDavBackend->getCalendarObject((int)$at->calendarId, $at->uri);
		if ($existing === null) {
			throw new Exception('Task not found');
		}

		return $existing['calendardata'];
	}//end storedCalendarData()

	/**
	 * Refuse a task that is not one of the caller's tasks on the given object.
	 *
	 * The tasks of an object are those in the caller's own calendar that carry
	 * the object's uuid, so this answers for the calendar and the link at once.
	 *
	 * @param string $objectUuid The object the caller addressed.
	 * @param string $calendarId The calendar the caller named.
	 * @param string $taskUri    The task the caller named.
	 *
	 * @return void
	 *
	 * @throws Exception With code 404 when it is not.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function requireTaskOf(string $objectUuid, string $calendarId, string $taskUri): void {
		$this->requireTaskAt(objectUuid: $objectUuid, at: new TaskLocation(calendarId: $calendarId, uri: $taskUri));
	}//end requireTaskOf()

	/**
	 * Refuse a task that is not one of the caller's tasks on the given object (see requireTaskOf()).
	 *
	 * @param string       $objectUuid The object the caller addressed.
	 * @param TaskLocation $at         The calendar and task the caller named.
	 *
	 * @return void
	 *
	 * @throws Exception With code 404 when it is not.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function requireTaskAt(string $objectUuid, TaskLocation $at): void {
		try {
			$tasks = $this->getTasksForObject(objectUuid: $objectUuid);
		} catch (NoVtodoCalendarException $e) {
			$tasks = [];
		}

		foreach ($tasks as $task) {
			if ((string)$task['calendarId'] === (string)$at->calendarId && $task['id'] === $at->uri) {
				return;
			}
		}

		throw new Exception('Task not found', 404);
	}//end requireTaskAt()

	/**
	 * Delete a CalDAV task.
	 *
	 * Deleting a PROJECTED VTODO deletes the calendar entry and nothing else:
	 * the engine task keeps its state, and the projection is restored on the
	 * next reconciliation, because a task is not cancelled by removing the
	 * reminder of it. Nothing here reaches the engine.
	 *
	 * @param string $calendarId The calendar ID containing the task
	 * @param string $taskUri The URI of the task to delete
	 *
	 * @return void
	 *
	 * @throws Exception If the task is not found or deletion fails
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-10
	 * @spec openspec/changes/flow-task-inbox-projections/specs/object-interactions/spec.md#requirement-tasks-on-objects-via-caldav-vtodo
	 */
	public function deleteTask(string $calendarId, string $taskUri): void {
		$calendarIdInt = (int)$calendarId;
		$existing = $this->calDavBackend->getCalendarObject($calendarIdInt, $taskUri);

		if ($existing === null) {
			throw new Exception('Task not found');
		}

		$this->calDavBackend->deleteCalendarObject($calendarIdInt, $taskUri);
	}//end deleteTask()

	/**
	 * Find a user's first VTODO-supporting calendar.
	 *
	 * Standalone tasks resolve the SESSION user (the default); a projection
	 * passes the ASSIGNEE's uid, because the reminder belongs in the calendar
	 * of whoever owes the work, not of whoever triggered the transition.
	 *
	 * @param string|null $uid The calendar owner; null means the session user
	 *
	 * @return array{id: int, uri: string} Calendar data with 'id' and 'uri' keys
	 *
	 * @throws Exception If no user is logged in and none was named
	 * @throws NoVtodoCalendarException If the user has no suitable calendar
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/object-interactions/spec.md#requirement-calendar-selection-for-tasks
	 */
	public function findUserCalendar(?string $uid = null): array {
		if ($uid === null || trim($uid) === '') {
			$uid = $this->sessionUid();
		}

		return $this->calendars->forUser(uid: $uid);
	}//end findUserCalendar()

	/**
	 * The uid of the session user.
	 *
	 * @return string The uid.
	 *
	 * @throws Exception If no user is logged in
	 */
	private function sessionUid(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new Exception('No user logged in');
		}

		return $user->getUID();
	}//end sessionUid()

	/**
	 * Whether a create payload attempts to set an engine task identity.
	 *
	 * @param array $data The create payload
	 *
	 * @return bool True when `X-OPENREGISTER-TASK` (or its camel-cased spelling) appears at any level the writer reads
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/object-interactions/spec.md#requirement-tasks-on-objects-via-caldav-vtodo
	 */
	private function carriesEngineIdentity(array $data): bool {
		if ($this->namesEngineTask(level: $data) === true) {
			return true;
		}

		$fields = ($data['fields'] ?? null);

		return is_array($fields) === true && $this->namesEngineTask(level: $fields) === true;
	}//end carriesEngineIdentity()

	/**
	 * Whether one level of a payload sets a non-blank engine task marker.
	 *
	 * @param array $level The payload level: the payload itself or its `fields`.
	 *
	 * @return bool True when `X-OPENREGISTER-TASK`, `taskUuid` or `engineTask` is set and not blank
	 */
	private function namesEngineTask(array $level): bool {
		foreach ([TaskCalendarProjector::PROP_TASK, 'taskUuid', 'engineTask'] as $marker) {
			if (isset($level[$marker]) === true && trim((string)$level[$marker]) !== '') {
				return true;
			}
		}

		return false;
	}//end namesEngineTask()

	/**
	 * Route a projected VTODO's update through the write-back gate.
	 *
	 * Only `status` can name a verb; every other field is projection-owned
	 * and the stored document is the engine's rendering regardless.
	 *
	 * @param StoredTask $task The stored document and where it is
	 * @param array      $data The requested changes
	 *
	 * @return array|null The projected task as JSON-friendly array
	 *
	 * @throws TaskAccessDeniedException When no gate is available (fail closed) or the gate refuses
	 * @throws Exception When the gate refuses for any other reason
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/object-interactions/spec.md#requirement-task-status-mapping
	 */
	private function updateProjectedTask(StoredTask $task, array $data): ?array {
		if ($this->gate === null) {
			throw new TaskAccessDeniedException(
				message: 'This calendar entry projects an engine task and the write-back gate is unavailable, so the change was not applied.'
			);
		}

		$requested = $this->requestedDocument(existing: $task->calendarData, data: $data);
		$rendered = $this->gate->handleWrite(calendarData: $requested, actor: $this->userSession->getUser()?->getUID());
		if ($rendered === null) {
			// An echo or a document that is not the engine's business: stored as is.
			return $this->vtodoToArray(calendarData: $task->calendarData, at: $task->at);
		}

		$this->calDavBackend->updateCalendarObject($task->at->calendarId, $task->at->uri, $rendered);

		return $this->vtodoToArray(calendarData: $rendered, at: $task->at);
	}//end updateProjectedTask()

	/**
	 * The document a projected update requests: the stored one, with the requested status when one is given.
	 *
	 * @param string $existing The stored document
	 * @param array  $data     The requested changes
	 *
	 * @return string The requested document
	 *
	 * @throws Exception When a status is requested on a document that holds no VTODO
	 */
	private function requestedDocument(string $existing, array $data): string {
		if (isset($data['status']) === false) {
			return $existing;
		}

		return $this->documents->withStatus(existing: $existing, status: (string)$data['status']);
	}//end requestedDocument()

	/**
	 * Parse a VTODO iCalendar string into a JSON-friendly array.
	 *
	 * @param string       $calendarData The raw iCalendar string
	 * @param TaskLocation $at           The calendar and URI of the calendar object
	 *
	 * @return array|null Task array or null if not a VTODO
	 */
	private function vtodoToArray(string $calendarData, TaskLocation $at): ?array {
		return $this->documents->taskOf(calendarData: $calendarData, at: $at);
	}//end vtodoToArray()
}//end class
