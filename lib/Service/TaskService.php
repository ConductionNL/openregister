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

use DateTime;
use Exception;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\OpenRegister\Exception\NoVtodoCalendarException;
use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectCalendarTasksInterface;
use OCA\OpenRegister\Service\Task\TaskCalendarProjector;
use OCA\OpenRegister\Service\Task\TaskVtodoWriteBackGate;
use OCA\OpenRegister\Service\Task\VtodoReader;
use OCA\OpenRegister\Service\Task\VtodoCalendarLocator;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Reader;

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
class TaskService implements ObjectCalendarTasksInterface {

	/**
	 * CalDAV backend for calendar operations.
	 *
	 * @var CalDavBackend
	 */
	private readonly CalDavBackend $calDavBackend;

	/**
	 * User session for getting current user.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Logger for error reporting.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * URL generator (webroot-aware deep links).
	 *
	 * @var IURLGenerator
	 */
	private readonly IURLGenerator $urlGenerator;

	/**
	 * Calendar selection by uid, shared with the projector.
	 *
	 * @var VtodoCalendarLocator
	 */
	private readonly VtodoCalendarLocator $calendars;

	/**
	 * The write-back gate a PROJECTED VTODO's status change goes through.
	 * Nullable so the service stays constructible bare; without it a
	 * projected update is refused, never applied unchecked (fail closed).
	 *
	 * @var TaskVtodoWriteBackGate|null
	 */
	private readonly ?TaskVtodoWriteBackGate $gate;

	/**
	 * Reads VTODO components into task arrays.
	 *
	 * @var VtodoReader
	 */
	private readonly VtodoReader $vtodoReader;

	/**
	 * Constructor.
	 *
	 * @param CalDavBackend $calDavBackend CalDAV backend for VTODO operations
	 * @param IUserSession $userSession User session for current user context
	 * @param LoggerInterface $logger Logger for error reporting
	 * @param IURLGenerator $urlGenerator URL generator for deep links
	 * @param TaskVtodoWriteBackGate|null $gate The one path from a projected VTODO into the engine
	 *
	 * @return void
	 */
	public function __construct(
		CalDavBackend $calDavBackend,
		IUserSession $userSession,
		LoggerInterface $logger,
		IURLGenerator $urlGenerator,
		?TaskVtodoWriteBackGate $gate = null,
	) {
		$this->calDavBackend = $calDavBackend;
		$this->userSession = $userSession;
		$this->logger = $logger;
		$this->urlGenerator = $urlGenerator;
		$this->calendars = new VtodoCalendarLocator(calDavBackend: $calDavBackend);
		$this->gate = $gate;
		$this->vtodoReader = new VtodoReader();
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
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new Exception('No user logged in');
		}

		$allTasks = [];
		foreach ($this->calDavBackend->getCalendarsForUser('principals/users/' . $user->getUID()) as $calendar) {
			$allTasks = array_merge($allTasks, $this->vtodoCalendarTasks(calendar: $calendar, status: $status));
		}

		$allTasks = $this->sortedByDue(tasks: $allTasks);

		return ['results' => array_slice($allTasks, $offset, $limit), 'total' => count($allTasks)];
	}//end getAllUserTasks()

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
			$task = $this->readTask(calendarId: $calendarId, uri: $calendarObject['uri'], needle: 'VTODO');
			if ($task === null || ($status !== null && $task['status'] !== strtolower($status))) {
				continue;
			}

			$tasks[] = $task;
		}

		return $tasks;
	}//end calendarTasks()

	/**
	 * One calendar object as a task, or null when it is gone, empty, not a VTODO mentioning the needle, or unreadable.
	 *
	 * @param mixed  $calendarId The calendar id.
	 * @param string $uri        The calendar object URI.
	 * @param string $needle     Text the raw iCalendar data must contain.
	 *
	 * @return array|null The task.
	 */
	private function readTask(mixed $calendarId, string $uri, string $needle): ?array {
		$calendarData = ($this->calDavBackend->getCalendarObject($calendarId, $uri)['calendardata'] ?? null);
		if (empty($calendarData) === true || $this->mentionsAll(data: $calendarData, needles: [$needle, 'VTODO']) === false) {
			return null;
		}

		try {
			return $this->vtodoToArray(calendarData: $calendarData, calendarId: (string)$calendarId, uri: $uri);
		} catch (Exception $e) {
			$this->logger->warning('Failed to parse calendar object: ' . $e->getMessage(), ['uri' => $uri]);
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
		foreach ($needles as $needle) {
			if (strpos($data, $needle) === false) {
				return false;
			}
		}

		return true;
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
			$task = $this->readTask(calendarId: $calendarId, uri: $calendarObject['uri'], needle: $objectUuid);
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
		if (in_array($calendarUri, [null, ''], true) === true || in_array($taskUri, [null, ''], true) === true) {
			return null;
		}

		try {
			$base = $this->urlGenerator->linkToRoute('tasks.page.index');
		} catch (\Throwable $e) {
			return null;
		}

		return rtrim($base, '/') . '/#/calendars/' . rawurlencode($calendarUri) . '/tasks/' . rawurlencode($taskUri);
	}//end buildTaskDeepLink()

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
	public function createTask(
		int $registerId,
		int $schemaId,
		string $objectUuid,
		string $objectTitle,
		array $data,
	): ?array {
		if ($this->carriesEngineIdentity(data: $data) === true) {
			throw new TaskAccessDeniedException(
				message: 'An engine task cannot be created through the object task endpoint; only OpenRegister projects engine tasks into a calendar.'
			);
		}

		$calendar = $this->findUserCalendar();
		$calendarId = $calendar['id'];

		$uid = strtoupper(bin2hex(random_bytes(16)));
		$dtstamp = gmdate('Ymd\THis\Z');
		$summary = $this->escapeIcalText(text: $data['summary'] ?? 'Untitled task');
		$status = strtoupper($data['status'] ?? 'NEEDS-ACTION');
		$priority = (int)($data['priority'] ?? 0);

		// Build the VTODO lines.
		$lines = [];
		$lines[] = 'BEGIN:VCALENDAR';
		$lines[] = 'VERSION:2.0';
		$lines[] = 'PRODID:-//OpenRegister//Tasks//EN';
		$lines[] = 'BEGIN:VTODO';
		$lines[] = 'UID:' . $uid;
		$lines[] = 'DTSTAMP:' . $dtstamp;
		$lines[] = 'SUMMARY:' . $summary;

		if (empty($data['description']) === false) {
			$lines[] = 'DESCRIPTION:' . $this->escapeIcalText(text: $data['description']);
		}

		$lines[] = 'STATUS:' . $status;
		$lines[] = 'PRIORITY:' . $priority;

		if (empty($data['due']) === false) {
			$dueDate = new DateTime($data['due']);
			$lines[] = 'DUE:' . $dueDate->format('Ymd\THis\Z');
		}

		// X-OPENREGISTER linking properties.
		$lines[] = 'X-OPENREGISTER-REGISTER:' . $registerId;
		$lines[] = 'X-OPENREGISTER-SCHEMA:' . $schemaId;
		$lines[] = 'X-OPENREGISTER-OBJECT:' . $objectUuid;

		// Non-core schema fields (e.g. assignee) are round-tripped as a single
		// base64-encoded JSON blob so any field survives the VTODO projection
		// (object-source-providers) without iCal escaping concerns.
		if (empty($data['fields']) === false && is_array($data['fields']) === true) {
			$lines[] = 'X-OPENREGISTER-DATA:' . base64_encode((string)json_encode($data['fields']));
		}

		// RFC 9253 LINK property.
		$linkLabel = $this->escapeIcalText(text: $objectTitle);
		$linkUri = '/apps/openregister/api/objects/' . $registerId . '/' . $schemaId . '/' . $objectUuid;
		$lines[] = 'LINK;LINKREL="related";LABEL="' . $linkLabel . '";VALUE=URI:' . $linkUri;

		$lines[] = 'END:VTODO';
		$lines[] = 'END:VCALENDAR';

		$calendarData = implode("\r\n", $lines) . "\r\n";
		$uri = $uid . '.ics';

		$this->calDavBackend->createCalendarObject($calendarId, $uri, $calendarData);

		return $this->vtodoToArray(calendarData: $calendarData, calendarId: (string)$calendarId, uri: $uri);
	}//end createTask()

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
		$calendarIdInt = (int)$calendarId;
		$existing = $this->calDavBackend->getCalendarObject($calendarIdInt, $taskUri);

		if ($existing === null) {
			throw new Exception('Task not found');
		}

		if (TaskCalendarProjector::taskUuidOf(calendarData: (string)$existing['calendardata']) !== null) {
			return $this->updateProjectedTask(
				calendarId: $calendarIdInt,
				taskUri: $taskUri,
				existing: (string)$existing['calendardata'],
				data: $data
			);
		}

		$vcalendar = Reader::read($existing['calendardata']);
		$vtodo = $vcalendar->VTODO;

		if ($vtodo === null) {
			throw new Exception('Calendar object is not a VTODO');
		}

		// Update fields that are provided.
		if (isset($data['summary']) === true) {
			$vtodo->SUMMARY = $data['summary'];
		}

		if (isset($data['description']) === true) {
			$vtodo->DESCRIPTION = $data['description'];
		}

		if (isset($data['status']) === true) {
			$vtodo->STATUS = strtoupper($data['status']);

			// If completing, set COMPLETED timestamp.
			if (strtoupper($data['status']) === 'COMPLETED') {
				$vtodo->COMPLETED = gmdate('Ymd\THis\Z');
			}
		}

		if (isset($data['priority']) === true) {
			$vtodo->PRIORITY = (int)$data['priority'];
		}

		if (isset($data['due']) === true && empty($data['due']) === true) {
			unset($vtodo->DUE);
		} elseif (isset($data['due']) === true) {
			$vtodo->DUE = new DateTime($data['due']);
		}

		// Round-trip non-core schema fields (object-source-providers): replace the
		// X-OPENREGISTER-DATA blob so a projected object's non-core fields (e.g.
		// assignee, taskStatus) update faithfully. Linking props (REGISTER/SCHEMA/
		// OBJECT) are left untouched above so the link + scoping survive the update.
		if (isset($data['fields']) === true && is_array($data['fields']) === true) {
			unset($vtodo->{'X-OPENREGISTER-DATA'});
			if (empty($data['fields']) === false) {
				$vtodo->add('X-OPENREGISTER-DATA', base64_encode((string)json_encode($data['fields'])));
			}
		}

		// Update DTSTAMP.
		$vtodo->DTSTAMP = gmdate('Ymd\THis\Z');

		$calendarData = $vcalendar->serialize();
		$this->calDavBackend->updateCalendarObject($calendarIdInt, $taskUri, $calendarData);

		return $this->vtodoToArray(calendarData: $calendarData, calendarId: $calendarId, uri: $taskUri);
	}//end updateTask()

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
		try {
			$tasks = $this->getTasksForObject(objectUuid: $objectUuid);
		} catch (NoVtodoCalendarException $e) {
			$tasks = [];
		}

		foreach ($tasks as $task) {
			if ((string)$task['calendarId'] === $calendarId && $task['id'] === $taskUri) {
				return;
			}
		}

		throw new Exception('Task not found', 404);
	}//end requireTaskOf()

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
			$user = $this->userSession->getUser();
			if ($user === null) {
				throw new Exception('No user logged in');
			}

			$uid = $user->getUID();
		}

		return $this->calendars->forUser(uid: $uid);
	}//end findUserCalendar()

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
		$markers = [TaskCalendarProjector::PROP_TASK, 'taskUuid', 'engineTask'];
		foreach ($markers as $marker) {
			if (isset($data[$marker]) === true && trim((string)$data[$marker]) !== '') {
				return true;
			}
		}

		$fields = ($data['fields'] ?? null);
		if (is_array($fields) === false) {
			return false;
		}

		foreach ($markers as $marker) {
			if (isset($fields[$marker]) === true && trim((string)$fields[$marker]) !== '') {
				return true;
			}
		}

		return false;
	}//end carriesEngineIdentity()

	/**
	 * Route a projected VTODO's update through the write-back gate.
	 *
	 * Only `status` can name a verb; every other field is projection-owned
	 * and the stored document is the engine's rendering regardless.
	 *
	 * @param int $calendarId The calendar holding the VTODO
	 * @param string $taskUri The VTODO uri
	 * @param string $existing The stored document
	 * @param array $data The requested changes
	 *
	 * @return array|null The projected task as JSON-friendly array
	 *
	 * @throws TaskAccessDeniedException When no gate is available (fail closed) or the gate refuses
	 * @throws Exception When the gate refuses for any other reason
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/object-interactions/spec.md#requirement-task-status-mapping
	 */
	private function updateProjectedTask(int $calendarId, string $taskUri, string $existing, array $data): ?array {
		if ($this->gate === null) {
			throw new TaskAccessDeniedException(
				message: 'This calendar entry projects an engine task and the write-back gate is unavailable, so the change was not applied.'
			);
		}

		$requested = $existing;
		if (isset($data['status']) === true) {
			$vcalendar = Reader::read($existing);
			$vtodo = ($vcalendar->select('VTODO')[0] ?? null);
			if ($vtodo === null) {
				throw new Exception('Calendar object is not a VTODO');
			}

			$vtodo->remove('STATUS');
			$vtodo->add('STATUS', strtoupper((string)$data['status']));
			$requested = $vcalendar->serialize();
		}

		$actor = $this->userSession->getUser()?->getUID();
		$rendered = $this->gate->handleWrite(calendarData: $requested, actor: $actor);
		if ($rendered === null) {
			// An echo or a document that is not the engine's business: stored as is.
			return $this->vtodoToArray(calendarData: $existing, calendarId: (string)$calendarId, uri: $taskUri);
		}

		$this->calDavBackend->updateCalendarObject($calendarId, $taskUri, $rendered);

		return $this->vtodoToArray(calendarData: $rendered, calendarId: (string)$calendarId, uri: $taskUri);
	}//end updateProjectedTask()

	/**
	 * Parse a VTODO iCalendar string into a JSON-friendly array.
	 *
	 * @param string $calendarData The raw iCalendar string
	 * @param string $calendarId The calendar ID
	 * @param string $uri The calendar object URI
	 *
	 * @return array|null Task array or null if not a VTODO
	 */
	private function vtodoToArray(string $calendarData, string $calendarId, string $uri): ?array {
		$vcalendar = Reader::read($calendarData);
		$vtodo = $vcalendar->VTODO;

		if ($vtodo === null) {
			return null;
		}

		return $this->vtodoReader->taskArray(vtodo: $vtodo, calendarId: $calendarId, uri: $uri);
	}//end vtodoToArray()

	/**
	 * Escape text for use in iCalendar property values.
	 *
	 * @param string $text The text to escape
	 *
	 * @return string The escaped text
	 */
	private function escapeIcalText(string $text): string {
		$text = str_replace('\\', '\\\\', $text);
		$text = str_replace("\n", '\\n', $text);
		$text = str_replace(',', '\\,', $text);
		$text = str_replace(';', '\\;', $text);

		return $text;
	}//end escapeIcalText()
}//end class
