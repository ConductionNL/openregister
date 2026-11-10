<?php

/**
 * Renders an engine task as a VTODO in the ASSIGNEE's calendar.
 *
 * A projection, never a store (flow-task-inbox-projections, design D-2): the
 * VTODO is a pure function of the task row, written into the assignee's
 * first VTODO-capable calendar, and rebuilt rather than merged. What it
 * carries is the property table from design D-5: SUMMARY from the display
 * title, DUE from `due_at`, PRIORITY through the published mapping, STATUS
 * through the published mapping, `URL` deep-linking to the task, and the two
 * identities that make write-back addressable: `X-OPENREGISTER-TASK` (the
 * task uuid) and `X-OPENREGISTER-TASK-ASSIGNEE` (a uid, never prose).
 *
 * Idempotent and non-reflexive (D-2 rule 6): the engine-owned content is
 * hashed onto the task's projection state BEFORE the calendar write, so a
 * write observed on the calendar that hashes the same is this projector's
 * own echo.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-assigned-task-appears-in-the-assignees-own-calendar
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use DateTime;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskProjectionState;
use OCA\OpenRegister\Exception\NoVtodoCalendarException;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;
use Throwable;

/**
 * The CalDAV projection writer.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The projector bridges the
 * task store, the projection state, the calendar backend and iCalendar; that
 * is the whole of its job.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The sum of the sync
 * contract's rules (design D-2), each a small branch; splitting the class
 * would put half the contract out of sight of the other half.
 * @SuppressWarnings(PHPMD.StaticAccess) TaskVtodoStatusMapping is the
 * stateless published mapping; Reader is VObject's parser entry point.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-assigned-task-appears-in-the-assignees-own-calendar
 */
class TaskCalendarProjector {

	/**
	 * The property carrying the task identity: what makes a VTODO projected.
	 */
	public const PROP_TASK = 'X-OPENREGISTER-TASK';

	/**
	 * The property carrying the assignee as a uid.
	 */
	public const PROP_ASSIGNEE = 'X-OPENREGISTER-TASK-ASSIGNEE';

	/**
	 * The object uri prefix; the task uuid follows.
	 */
	private const URI_PREFIX = 'openregister-task-';

	/**
	 * The route a projected VTODO's `URL` resolves to.
	 */
	public const OPEN_ROUTE = 'openregister.task.open';

	/**
	 * Constructor.
	 *
	 * @param TaskCalendarProjectorServices $services The calendar store, the calendar
	 *                                                locator, the projection state and the logger.
	 * @param TaskVtodoRenderer $renderer Renders the VTODO document.
	 */
	public function __construct(
		private readonly TaskCalendarProjectorServices $services,
		private readonly TaskVtodoRenderer $renderer,
	) {

	}//end __construct()

	/**
	 * Project a task after a transition.
	 *
	 * Removes the previous assignee's copy when the task changed hands,
	 * skips (logging, naming the task) when there is no individual assignee
	 * or the assignee has no VTODO-capable calendar, and otherwise renders
	 * into the assignee's calendar, writing nothing when the rendered content
	 * is unchanged.
	 *
	 * @param Task $task The committed task.
	 * @param string|null $previousAssignee Who held it before, when anyone.
	 *
	 * @return void
	 *
	 * @throws Throwable A calendar backend failure, for the caller to isolate.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-assigned-task-appears-in-the-assignees-own-calendar
	 */
	public function project(Task $task, ?string $previousAssignee = null): void {
		$this->sync(run: new TaskProjectionRun(task: $task, previousAssignee: $previousAssignee, verifyStore: false));
	}//end project()

	/**
	 * Make the calendar match the task, reading what is actually there.
	 *
	 * Unlike {@see project()}, this trusts nothing: a VTODO deleted outright
	 * is recreated with the same content, and one edited is overwritten
	 * with the engine's rendering.
	 *
	 * @param Task $task The task to reconcile.
	 *
	 * @return void
	 *
	 * @throws Throwable A calendar backend failure, for the caller to isolate.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-truth-flows-one-way-and-the-one-path-back-is-a-gate
	 */
	public function reconcile(Task $task): void {
		$state = $this->services->states->findForTask(taskUuid: (string)$task->getUuid());
		$this->sync(run: new TaskProjectionRun(task: $task, previousAssignee: $state?->getAssignee(), verifyStore: true));
	}//end reconcile()

	/**
	 * Render the VTODO for a task.
	 *
	 * @param Task $task The task.
	 *
	 * @return string The VCALENDAR document.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-assigned-task-appears-in-the-assignees-own-calendar
	 */
	public function render(Task $task): string {
		return $this->renderer->render(task: $task);
	}//end render()

	/**
	 * The task uuid a VTODO document carries, or null for a standalone one.
	 *
	 * Identity is carried, never inferred: no heuristic on summary, due date
	 * or calendar (design D-2 rule 5).
	 *
	 * @param string $calendarData The VCALENDAR document.
	 *
	 * @return string|null The task uuid, or null when the VTODO is not a projection.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-ticking-off-the-vtodo-completes-the-engine-task-through-authorization
	 */
	public static function taskUuidOf(string $calendarData): ?string {
		$fields = self::engineFields(calendarData: $calendarData);
		if ($fields === null) {
			return null;
		}

		$uuid = trim((string)($fields['task'] ?? ''));
		if ($uuid === '') {
			return null;
		}

		return $uuid;
	}//end taskUuidOf()

	/**
	 * The engine-owned fields of a VTODO document, or null when it is no VTODO.
	 *
	 * @param string $calendarData The VCALENDAR document.
	 *
	 * @return array{task: string, status: string, summary: string, description: string,
	 *     due: string, priority: string, assignee: string}|null The fields.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-projection-is-idempotent-and-does-not-feed-itself
	 */
	public static function engineFields(string $calendarData): ?array {
		$vtodo = self::firstVtodo(calendarData: $calendarData);
		if ($vtodo === null) {
			return null;
		}

		return [
			'task' => self::value(component: $vtodo, name: self::PROP_TASK),
			'status' => strtoupper(trim(self::value(component: $vtodo, name: 'STATUS'))),
			'summary' => self::value(component: $vtodo, name: 'SUMMARY'),
			'description' => self::value(component: $vtodo, name: 'DESCRIPTION'),
			'due' => self::dueOf(vtodo: $vtodo),
			'priority' => self::value(component: $vtodo, name: 'PRIORITY'),
			'assignee' => self::value(component: $vtodo, name: self::PROP_ASSIGNEE),
		];
	}//end engineFields()

	/**
	 * The first VTODO of a VCALENDAR document, or null when there is none.
	 *
	 * @param string $calendarData The document.
	 *
	 * @return Component|null The VTODO.
	 */
	private static function firstVtodo(string $calendarData): ?Component {
		try {
			$document = Reader::read($calendarData);
		} catch (Throwable) {
			return null;
		}

		if (($document instanceof VCalendar) === false) {
			return null;
		}

		$vtodo = ($document->select('VTODO')[0] ?? null);
		if (($vtodo instanceof Component) === false) {
			return null;
		}

		return $vtodo;
	}//end firstVtodo()

	/**
	 * A VTODO's DUE as a unix timestamp, its raw value when unparseable, or ''.
	 *
	 * @param Component $vtodo The VTODO.
	 *
	 * @return string The due value.
	 */
	private static function dueOf(Component $vtodo): string {
		$dueProperty = ($vtodo->select('DUE')[0] ?? null);
		if ($dueProperty === null) {
			return '';
		}

		try {
			return (string)$dueProperty->getDateTime()->getTimestamp();
		} catch (Throwable) {
			return (string)$dueProperty;
		}
	}//end dueOf()

	/**
	 * One property's string value on a component, or '' when absent.
	 *
	 * @param Component $component The component.
	 * @param string $name The property name.
	 *
	 * @return string The value.
	 */
	private static function value(Component $component, string $name): string {
		$property = ($component->select($name)[0] ?? null);
		if ($property === null) {
			return '';
		}

		return (string)$property;
	}//end value()

	/**
	 * The hash of a document's engine-owned content.
	 *
	 * DTSTAMP, COMPLETED and client-added properties are deliberately not
	 * part of it: two renders of one unchanged task hash equal, and a
	 * client's re-serialisation of an untouched projection hashes equal too.
	 *
	 * @param string $calendarData The VCALENDAR document.
	 *
	 * @return string|null The hash, or null when the document is no VTODO.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-projection-is-idempotent-and-does-not-feed-itself
	 */
	public static function contentHash(string $calendarData): ?string {
		$fields = self::engineFields(calendarData: $calendarData);
		if ($fields === null) {
			return null;
		}

		return hash('sha256', implode("\x1f", $fields));
	}//end contentHash()

	/**
	 * Whether a calendar write is this projector's own echo.
	 *
	 * @param string $taskUuid The task the write names.
	 * @param string $calendarData The written document.
	 *
	 * @return bool True when its engine-owned content is exactly what was last rendered.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-projection-is-idempotent-and-does-not-feed-itself
	 */
	public function isEcho(string $taskUuid, string $calendarData): bool {
		$state = $this->services->states->findForTask(taskUuid: $taskUuid);
		if ($state === null || (string)$state->getRenderedHash() === '') {
			return false;
		}

		return self::contentHash(calendarData: $calendarData) === $state->getRenderedHash();
	}//end isEcho()

	/**
	 * Remove a task's projection, wherever it is.
	 *
	 * @param string $taskUuid The task uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-assigned-task-appears-in-the-assignees-own-calendar
	 */
	public function remove(string $taskUuid): void {
		$state = $this->services->states->findForTask(taskUuid: $taskUuid);
		if ($state === null) {
			return;
		}

		$this->removeState(state: $state);
	}//end remove()

	/**
	 * The shared body of project() and reconcile().
	 *
	 * One rule of the sync contract (design D-2) per step: a reassignment
	 * removes the previous copy, an unassigned task is skipped, a terminal
	 * task never projected stays out, a missing calendar is skipped, and the
	 * rest is {@see write()}.
	 *
	 * @param TaskProjectionRun $run The task, its previous assignee, and whether
	 *                              to verify the stored object even when the
	 *                              recorded hash matches.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-assigned-task-appears-in-the-assignees-own-calendar
	 */
	private function sync(TaskProjectionRun $run): void {
		$assignee = $this->projectableAssignee(task: $run->task);
		$run->state = $this->currentState(task: $run->task, assignee: $assignee);
		if ($assignee === null) {
			$this->noteUnassigned(task: $run->task, previousAssignee: $run->previousAssignee);

			return;
		}

		$calendarId = $this->targetCalendar(task: $run->task, state: $run->state);
		if ($calendarId !== null) {
			$this->write(run: $run, write: $this->prepare(task: $run->task, calendarId: $calendarId));
		}
	}//end sync()

	/**
	 * The task's projection state, after a reassignment removed the previous
	 * holder's copy, whoever it was.
	 *
	 * @param Task $task The task.
	 * @param string|null $assignee Who the task is projected for now.
	 *
	 * @return TaskProjectionState|null The state, or null when there is none (any more).
	 */
	private function currentState(Task $task, ?string $assignee): ?TaskProjectionState {
		$state = $this->services->states->findForTask(taskUuid: (string)$task->getUuid());
		if ($state === null || self::isHeldBy(state: $state, assignee: $assignee) === true) {
			return $state;
		}

		$this->removeState(state: $state);

		return null;
	}//end currentState()

	/**
	 * Whether a projection belongs to the given assignee.
	 *
	 * @param TaskProjectionState $state The projection state.
	 * @param string|null $assignee The assignee, or null for nobody.
	 *
	 * @return bool True when the state names that assignee.
	 */
	private static function isHeldBy(TaskProjectionState $state, ?string $assignee): bool {
		return $assignee !== null && (string)$state->getAssignee() === $assignee;
	}//end isHeldBy()

	/**
	 * Log that a task is projected into no calendar, unless it simply ended unassigned.
	 *
	 * @param Task $task The task.
	 * @param string|null $previousAssignee Who held it before, when anyone.
	 *
	 * @return void
	 */
	private function noteUnassigned(Task $task, ?string $previousAssignee): void {
		if ($previousAssignee !== null || $task->isInTerminalState() === false) {
			$this->services->logger->info(
				'[TaskCalendarProjector] Task has no individual assignee; not projected into any calendar.',
				['task' => (string)$task->getUuid(), 'state' => $task->getState()]
			);
		}
	}//end noteUnassigned()

	/**
	 * The calendar an assigned task is projected into, or null when it is not.
	 *
	 * A terminal task that was never projected does not become a calendar
	 * entry now: there is nothing outstanding to show.
	 *
	 * @param Task $task The task, assigned to an individual.
	 * @param TaskProjectionState|null $state The current projection, if any.
	 *
	 * @return int|null The calendar id.
	 */
	private function targetCalendar(Task $task, ?TaskProjectionState $state): ?int {
		if ($state === null && $task->isInTerminalState() === true) {
			return null;
		}

		return $this->calendarIdFor(task: $task, assignee: (string)$this->projectableAssignee(task: $task));
	}//end targetCalendar()

	/**
	 * The assignee's VTODO-capable calendar id, or null (logged) when there is none.
	 *
	 * @param Task $task The task.
	 * @param string $assignee The assignee uid.
	 *
	 * @return int|null The calendar id.
	 */
	private function calendarIdFor(Task $task, string $assignee): ?int {
		try {
			return $this->services->calendars->forUser(uid: $assignee)['id'];
		} catch (NoVtodoCalendarException $missing) {
			$this->services->logger->info(
				'[TaskCalendarProjector] Assignee has no VTODO-capable calendar; projection skipped, task unaffected: ' . $missing->getMessage(),
				['task' => (string)$task->getUuid(), 'assignee' => $assignee, 'surface' => TaskProjectionState::SURFACE_CALDAV]
			);

			return null;
		}
	}//end calendarIdFor()

	/**
	 * Render a task for one calendar.
	 *
	 * @param Task $task The task.
	 * @param int $calendarId The target calendar.
	 *
	 * @return TaskCalendarWrite The rendered write.
	 */
	private function prepare(Task $task, int $calendarId): TaskCalendarWrite {
		$rendered = $this->render(task: $task);

		return new TaskCalendarWrite(
			task: $task,
			calendarId: $calendarId,
			rendered: $rendered,
			hash: (string)self::contentHash(calendarData: $rendered)
		);
	}//end prepare()

	/**
	 * Write a rendered projection unless the state row says it is already there.
	 *
	 * Idempotent: when nothing the engine owns changed, nothing is written.
	 * With the run's verifyStore the state row is not trusted; {@see writeUnlessHeld()}
	 * reads the calendar's own copy.
	 *
	 * @param TaskProjectionRun $run The run (current state, verify flag).
	 * @param TaskCalendarWrite $write The prepared write.
	 *
	 * @return void
	 */
	private function write(TaskProjectionRun $run, TaskCalendarWrite $write): void {
		$state = $this->inSameCalendar(state: $run->state, calendarId: $write->calendarId);
		if ($run->verifyStore === false && self::isUnchanged(state: $state, hash: $write->hash) === true) {
			return;
		}

		$this->writeUnlessHeld(write: $write, state: $state);
	}//end write()

	/**
	 * Write a rendered projection unless the calendar's copy already holds it.
	 *
	 * @param TaskCalendarWrite $write The rendered write.
	 * @param TaskProjectionState|null $state The task's projection state.
	 *
	 * @return void
	 */
	private function writeUnlessHeld(TaskCalendarWrite $write, ?TaskProjectionState $state): void {
		$existing = $this->existingObject(state: $state, calendarId: $write->calendarId);
		if (self::isUnchanged(state: $state, hash: $write->hash) === true && self::holds(existing: $existing, hash: $write->hash) === true) {
			return;
		}

		$saved = $this->saveState(write: $write, state: $state);
		if ($existing !== null) {
			$this->services->calDavBackend->updateCalendarObject($write->calendarId, (string)$saved->getObjectUri(), $write->rendered);

			return;
		}

		$this->createObject(write: $write, state: $saved);
	}//end writeUnlessHeld()

	/**
	 * Whether the state row records exactly this rendering.
	 *
	 * @param TaskProjectionState|null $state The task's projection state.
	 * @param string $hash The rendering's content hash.
	 *
	 * @return bool True when it does.
	 */
	private static function isUnchanged(?TaskProjectionState $state, string $hash): bool {
		return $state !== null && $state->getRenderedHash() === $hash;
	}//end isUnchanged()

	/**
	 * The state, or null once its copy in another calendar is removed.
	 *
	 * @param TaskProjectionState|null $state The task's projection state.
	 * @param int $calendarId The calendar the task belongs in now.
	 *
	 * @return TaskProjectionState|null The state.
	 */
	private function inSameCalendar(?TaskProjectionState $state, int $calendarId): ?TaskProjectionState {
		if ($state !== null && (int)$state->getCalendarId() !== $calendarId) {
			$this->removeState(state: $state);

			return null;
		}

		return $state;
	}//end inSameCalendar()

	/**
	 * The calendar object a state row points at, or null.
	 *
	 * @param TaskProjectionState|null $state The task's projection state.
	 * @param int $calendarId The calendar.
	 *
	 * @return array<string, mixed>|null The calendar object.
	 */
	private function existingObject(?TaskProjectionState $state, int $calendarId): ?array {
		if ($state === null) {
			return null;
		}

		return $this->services->calDavBackend->getCalendarObject($calendarId, (string)$state->getObjectUri());
	}//end existingObject()

	/**
	 * Whether a calendar object's engine-owned content hashes as given.
	 *
	 * @param array<string, mixed>|null $existing The calendar object.
	 * @param string $hash The expected hash.
	 *
	 * @return bool True when it does.
	 */
	private static function holds(?array $existing, string $hash): bool {
		return $existing !== null && self::contentHash(calendarData: (string)($existing['calendardata'] ?? '')) === $hash;
	}//end holds()

	/**
	 * Record what is about to be written, BEFORE the write, so the write's
	 * own event reads as an echo.
	 *
	 * @param TaskCalendarWrite $write The rendered write.
	 * @param TaskProjectionState|null $state The existing state row, or null for a new one.
	 *
	 * @return TaskProjectionState The saved state.
	 */
	private function saveState(TaskCalendarWrite $write, ?TaskProjectionState $state): TaskProjectionState {
		$uuid = (string)$write->task->getUuid();
		$uri = ($state?->getObjectUri() ?? (self::URI_PREFIX . $uuid . '.ics'));
		$state ??= new TaskProjectionState();
		$state->setTaskUuid($uuid);
		$state->setSurface(TaskProjectionState::SURFACE_CALDAV);
		$state->setAssignee($this->projectableAssignee(task: $write->task));
		$state->setCalendarId($write->calendarId);
		$state->setObjectUri($uri);
		$state->setRenderedHash($write->hash);
		$state->setRenderedAt(new DateTime());

		return $this->services->states->save(state: $state);
	}//end saveState()

	/**
	 * Create the VTODO, falling back to a fresh uri when the first is taken
	 * (a client-deleted projection lingers in the calendar trash under its
	 * old uri).
	 *
	 * @param TaskCalendarWrite $write The rendered write.
	 * @param TaskProjectionState $state The saved state row (updated when the uri changes).
	 *
	 * @return void
	 *
	 * @throws Throwable When the second attempt fails too.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-truth-flows-one-way-and-the-one-path-back-is-a-gate
	 */
	private function createObject(TaskCalendarWrite $write, TaskProjectionState $state): void {
		if ($this->tryCreate(write: $write, state: $state) === true) {
			return;
		}

		$fresh = self::URI_PREFIX . (string)$state->getTaskUuid() . '-' . time() . '.ics';
		$this->services->calDavBackend->createCalendarObject($write->calendarId, $fresh, $write->rendered);
		$state->setObjectUri($fresh);
		$this->services->states->save(state: $state);
	}//end createObject()

	/**
	 * Create the VTODO under the state row's uri.
	 *
	 * @param TaskCalendarWrite $write The rendered write.
	 * @param TaskProjectionState $state The saved state row.
	 *
	 * @return bool True when created; false (logged) when the uri is taken.
	 */
	private function tryCreate(TaskCalendarWrite $write, TaskProjectionState $state): bool {
		$uri = (string)$state->getObjectUri();
		try {
			$this->services->calDavBackend->createCalendarObject($write->calendarId, $uri, $write->rendered);

			return true;
		} catch (Throwable $firstAttempt) {
			$this->services->logger->debug(
				'[TaskCalendarProjector] Create under the stable uri failed, retrying under a fresh one: ' . $firstAttempt->getMessage(),
				['task' => $state->getTaskUuid(), 'uri' => $uri]
			);

			return false;
		}
	}//end tryCreate()

	/**
	 * Delete the VTODO a state row points at, then the row.
	 *
	 * A VTODO already gone is not an error: the goal is its absence.
	 *
	 * @param TaskProjectionState $state The row.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-assigned-task-appears-in-the-assignees-own-calendar
	 */
	private function removeState(TaskProjectionState $state): void {
		$calendarId = $state->getCalendarId();
		if ($calendarId !== null && (string)$state->getObjectUri() !== '') {
			$this->deleteObject(state: $state, calendarId: $calendarId);
		}

		$this->services->states->delete(entity: $state);
	}//end removeState()

	/**
	 * Delete the VTODO a state row points at, logging rather than throwing on failure.
	 *
	 * @param TaskProjectionState $state The row.
	 * @param int $calendarId The calendar it names.
	 *
	 * @return void
	 */
	private function deleteObject(TaskProjectionState $state, int $calendarId): void {
		$uri = (string)$state->getObjectUri();
		try {
			if ($this->services->calDavBackend->getCalendarObject($calendarId, $uri) !== null) {
				$this->services->calDavBackend->deleteCalendarObject($calendarId, $uri);
			}
		} catch (Throwable $failure) {
			$this->services->logger->warning(
				'[TaskCalendarProjector] Could not remove a stale projection: ' . $failure->getMessage(),
				['task' => $state->getTaskUuid(), 'calendar' => $calendarId, 'uri' => $uri]
			);
		}
	}//end deleteObject()

	/**
	 * The uid a task is projected for: an individual assignee, or nobody.
	 *
	 * A pooled task has no such person yet; projecting it into an arbitrary
	 * member's calendar would assert an assignment the engine has not made.
	 *
	 * @param Task $task The task.
	 *
	 * @return string|null The assignee uid, or null.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-assigned-task-appears-in-the-assignees-own-calendar
	 */
	private function projectableAssignee(Task $task): ?string {
		if ((string)$task->getPerformerType() !== Task::PERFORMER_USER) {
			return null;
		}

		$assignee = trim((string)$task->getAssignee());
		if ($assignee === '') {
			return null;
		}

		return $assignee;
	}//end projectableAssignee()
}//end class
