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
use DateTimeInterface;
use DateTimeZone;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskProjectionState;
use OCA\OpenRegister\Db\TaskProjectionStateMapper;
use OCA\OpenRegister\Exception\NoVtodoCalendarException;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
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
	 * @param CalDavBackend $calDavBackend The calendar store.
	 * @param VtodoCalendarLocator $calendars Calendar selection by uid.
	 * @param TaskProjectionStateMapper $states The per-task projection state.
	 * @param TaskInboxService $inbox Display titles, synthesized where needed.
	 * @param IURLGenerator $urlGenerator Builds the deep link.
	 * @param LoggerInterface $logger Names skipped and failed tasks.
	 */
	public function __construct(
		private readonly CalDavBackend $calDavBackend,
		private readonly VtodoCalendarLocator $calendars,
		private readonly TaskProjectionStateMapper $states,
		private readonly TaskInboxService $inbox,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
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
		$this->sync(task: $task, previousAssignee: $previousAssignee, verifyStore: false);
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
		$state = $this->states->findForTask(taskUuid: (string)$task->getUuid());
		$this->sync(task: $task, previousAssignee: $state?->getAssignee(), verifyStore: true);
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
		$uuid = (string)$task->getUuid();
		$utc = new DateTimeZone('UTC');
		$title = $this->inbox->displayTitle(task: $task, subject: null);

		$vcalendar = new VCalendar();
		$vcalendar->remove('PRODID');
		$vcalendar->add('PRODID', '-//OpenRegister//Tasks//EN');

		// No defaults: VObject would otherwise stamp its own UID and DTSTAMP
		// beside ours, and the UID is the task uuid by contract.
		$vtodo = $vcalendar->createComponent('VTODO', [], false);
		$vcalendar->add($vtodo);
		$vtodo->add('UID', $uuid);
		$vtodo->add('DTSTAMP', (new DateTime('now', $utc)));
		$vtodo->add('SUMMARY', $title);

		$description = trim((string)$task->getDescription());
		if ($description !== '') {
			$vtodo->add('DESCRIPTION', $description);
		}

		$vtodo->add('STATUS', TaskVtodoStatusMapping::render(state: (string)$task->getState()));
		$vtodo->add('PRIORITY', (string)TaskVtodoStatusMapping::priority(priority: $task->getPriority()));

		$due = $task->getDueAt();
		if ($due instanceof DateTimeInterface) {
			$vtodo->add('DUE', DateTime::createFromInterface($due)->setTimezone($utc));
		}

		if ((string)$task->getState() === Task::STATE_COMPLETED) {
			$completedAt = ($task->getCompletedAt() ?? new DateTime('now', $utc));
			$vtodo->add('COMPLETED', DateTime::createFromInterface($completedAt)->setTimezone($utc));
			$vtodo->add('PERCENT-COMPLETE', '100');
		}

		$vtodo->add('URL', $this->deepLink(uuid: $uuid));
		$vtodo->add(self::PROP_TASK, $uuid);
		$vtodo->add(self::PROP_ASSIGNEE, (string)$task->getAssignee());

		$objectUuid = trim((string)$task->getObjectUuid());
		if ($objectUuid !== '' && $task->getRegisterId() !== null && $task->getSchemaId() !== null) {
			$vtodo->add('X-OPENREGISTER-REGISTER', (string)$task->getRegisterId());
			$vtodo->add('X-OPENREGISTER-SCHEMA', (string)$task->getSchemaId());
			$vtodo->add('X-OPENREGISTER-OBJECT', $objectUuid);
			$vtodo->add(
				'LINK',
				sprintf('/apps/openregister/api/objects/%d/%d/%s', $task->getRegisterId(), $task->getSchemaId(), $objectUuid),
				[
					'LINKREL' => 'related',
					'LABEL' => $title,
					'VALUE' => 'URI',
				]
			);
		}

		return $vcalendar->serialize();
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

		$due = '';
		$dueProperty = ($vtodo->select('DUE')[0] ?? null);
		if ($dueProperty !== null) {
			try {
				$due = (string)$dueProperty->getDateTime()->getTimestamp();
			} catch (Throwable) {
				$due = (string)$dueProperty;
			}
		}

		return [
			'task' => self::value(component: $vtodo, name: self::PROP_TASK),
			'status' => strtoupper(trim(self::value(component: $vtodo, name: 'STATUS'))),
			'summary' => self::value(component: $vtodo, name: 'SUMMARY'),
			'description' => self::value(component: $vtodo, name: 'DESCRIPTION'),
			'due' => $due,
			'priority' => self::value(component: $vtodo, name: 'PRIORITY'),
			'assignee' => self::value(component: $vtodo, name: self::PROP_ASSIGNEE),
		];
	}//end engineFields()

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
		$state = $this->states->findForTask(taskUuid: $taskUuid);
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
		$state = $this->states->findForTask(taskUuid: $taskUuid);
		if ($state === null) {
			return;
		}

		$this->removeState(state: $state);
	}//end remove()

	/**
	 * The shared body of project() and reconcile().
	 *
	 * @param Task $task The task.
	 * @param string|null $previousAssignee Whose calendar may hold a stale copy.
	 * @param bool $verifyStore Whether to read the calendar rather than trust the state row.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag IS the difference
	 * between the two public entry points; both are documented on their own.
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) One branch per rule of
	 * the sync contract (design D-2); merging them would hide which rule fired.
	 * @SuppressWarnings(PHPMD.NPathComplexity) Same reason: the rules are
	 * independent, so their paths multiply, and each is one line.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-assigned-task-appears-in-the-assignees-own-calendar
	 */
	private function sync(Task $task, ?string $previousAssignee, bool $verifyStore): void {
		$uuid = (string)$task->getUuid();
		$state = $this->states->findForTask(taskUuid: $uuid);
		$assignee = $this->projectableAssignee(task: $task);

		// Reassignment: the previous holder's copy goes first, whoever it was.
		if ($state !== null && ((string)$state->getAssignee() !== (string)$assignee || $assignee === null)) {
			$this->removeState(state: $state);
			$state = null;
		}

		if ($assignee === null) {
			if ($previousAssignee !== null || $task->isInTerminalState() === false) {
				$this->logger->info(
					'[TaskCalendarProjector] Task has no individual assignee; not projected into any calendar.',
					['task' => $uuid, 'state' => $task->getState()]
				);
			}

			return;
		}

		// A terminal task that was never projected does not become a
		// calendar entry now: there is nothing outstanding to show.
		if ($state === null && $task->isInTerminalState() === true) {
			return;
		}

		try {
			$calendar = $this->calendars->forUser(uid: $assignee);
		} catch (NoVtodoCalendarException $missing) {
			$this->logger->info(
				'[TaskCalendarProjector] Assignee has no VTODO-capable calendar; projection skipped, task unaffected: ' . $missing->getMessage(),
				['task' => $uuid, 'assignee' => $assignee, 'surface' => TaskProjectionState::SURFACE_CALDAV]
			);

			return;
		}

		$rendered = $this->render(task: $task);
		$hash = (string)self::contentHash(calendarData: $rendered);

		if ($state !== null && (int)$state->getCalendarId() !== $calendar['id']) {
			$this->removeState(state: $state);
			$state = null;
		}

		$existing = null;
		if ($state !== null) {
			if ($verifyStore === false && $state->getRenderedHash() === $hash) {
				// Idempotent: nothing the engine owns changed, so nothing is written.
				return;
			}

			$existing = $this->calDavBackend->getCalendarObject($calendar['id'], (string)$state->getObjectUri());
			if ($verifyStore === true
				&& $existing !== null
				&& $state->getRenderedHash() === $hash
				&& self::contentHash(calendarData: (string)($existing['calendardata'] ?? '')) === $hash
			) {
				return;
			}
		}

		$uri = ($state?->getObjectUri() ?? (self::URI_PREFIX . $uuid . '.ics'));
		$state ??= new TaskProjectionState();
		$state->setTaskUuid($uuid);
		$state->setSurface(TaskProjectionState::SURFACE_CALDAV);
		$state->setAssignee($assignee);
		$state->setCalendarId($calendar['id']);
		$state->setObjectUri($uri);
		$state->setRenderedHash($hash);
		$state->setRenderedAt(new DateTime());
		// State BEFORE the write, so the write's own event reads as an echo.
		$state = $this->states->save(state: $state);

		if ($existing !== null) {
			$this->calDavBackend->updateCalendarObject($calendar['id'], $uri, $rendered);

			return;
		}

		$this->createObject(state: $state, calendarId: $calendar['id'], rendered: $rendered);
	}//end sync()

	/**
	 * Create the VTODO, falling back to a fresh uri when the first is taken
	 * (a client-deleted projection lingers in the calendar trash under its
	 * old uri).
	 *
	 * @param TaskProjectionState $state The saved state row (updated when the uri changes).
	 * @param int $calendarId The target calendar.
	 * @param string $rendered The document.
	 *
	 * @return void
	 *
	 * @throws Throwable When the second attempt fails too.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-truth-flows-one-way-and-the-one-path-back-is-a-gate
	 */
	private function createObject(TaskProjectionState $state, int $calendarId, string $rendered): void {
		$uri = (string)$state->getObjectUri();
		try {
			$this->calDavBackend->createCalendarObject($calendarId, $uri, $rendered);

			return;
		} catch (Throwable $firstAttempt) {
			$this->logger->debug(
				'[TaskCalendarProjector] Create under the stable uri failed, retrying under a fresh one: ' . $firstAttempt->getMessage(),
				['task' => $state->getTaskUuid(), 'uri' => $uri]
			);
		}

		$fresh = self::URI_PREFIX . (string)$state->getTaskUuid() . '-' . time() . '.ics';
		$this->calDavBackend->createCalendarObject($calendarId, $fresh, $rendered);
		$state->setObjectUri($fresh);
		$this->states->save(state: $state);
	}//end createObject()

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
		$uri = (string)$state->getObjectUri();
		if ($calendarId !== null && $uri !== '') {
			try {
				if ($this->calDavBackend->getCalendarObject($calendarId, $uri) !== null) {
					$this->calDavBackend->deleteCalendarObject($calendarId, $uri);
				}
			} catch (Throwable $failure) {
				$this->logger->warning(
					'[TaskCalendarProjector] Could not remove a stale projection: ' . $failure->getMessage(),
					['task' => $state->getTaskUuid(), 'calendar' => $calendarId, 'uri' => $uri]
				);
			}
		}

		$this->states->delete(entity: $state);
	}//end removeState()

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

	/**
	 * The deep link a projected VTODO carries as `URL`: a surface a person
	 * can act on, never the API.
	 *
	 * @param string $uuid The task uuid.
	 *
	 * @return string The absolute URL.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-assigned-task-appears-in-the-assignees-own-calendar
	 */
	private function deepLink(string $uuid): string {
		return $this->urlGenerator->linkToRouteAbsolute(self::OPEN_ROUTE, ['uuid' => $uuid]);
	}//end deepLink()
}//end class
