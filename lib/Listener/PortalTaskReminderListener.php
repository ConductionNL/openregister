<?php

/**
 * A business timer rung fired on an external task: the reminder travels out
 * through the portal delivery seam, the escalation stays inside.
 *
 * This is the CONSUMPTION half of the timer contract (flow-portal-task,
 * design D-8): the node owns no clock, and this listener owns none either.
 * It reacts to flow-business-timers' `FlowTimerFiredEvent` and does one
 * thing per rung kind. A `preBreach` rung whose recipients include the
 * external party becomes a `reminder` delivery request to that party, the
 * same seam the ask travelled by. A `slaBreached` rung is addressed inward,
 * to the caseworker role, by the timers themselves; this listener records
 * NOTHING for the party on it, which is how "the party MUST NOT receive it"
 * is kept: there is no code path here that would.
 *
 * DUCK-TYPED ON PURPOSE. `flow-business-timers` is being built in a parallel
 * change; its event class is not on this branch. The listener is registered
 * against the event's class NAME and reads the event through the methods its
 * spec and branch publish (`getKind()`, `getRungKey()`, `getRecipients()`,
 * `getTimer()` with `getSubjectType()`/`getSubjectUuid()`), so the two changes
 * merge in either order and nothing here needs the class to compile.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-the-overdue-path-is-consumed-from-flow-business-timers-never-rebuilt
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\PortalTaskDelivery;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\Portal\PortalTaskDeliveryService;
use OCA\OpenRegister\Service\Task\TaskService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns a preBreach rung on an external task into a portal reminder.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-the-overdue-path-is-consumed-from-flow-business-timers-never-rebuilt
 */
class PortalTaskReminderListener implements IEventListener {

	/**
	 * The event this listener is registered for, by name: flow-business-timers'.
	 *
	 * @var string
	 */
	public const EVENT_CLASS = 'OCA\OpenRegister\Event\FlowTimerFiredEvent';

	/**
	 * The rung trigger that is the party's reminder. The rung key the timers
	 * generate starts with the trigger (`preBreach:<offset>:<unit>`).
	 *
	 * @var string
	 */
	public const TRIGGER_PRE_BREACH = 'preBreach';

	/**
	 * The rung trigger that escalates inward and never reaches the party.
	 *
	 * @var string
	 */
	public const TRIGGER_BREACHED = 'slaBreached';

	/**
	 * A rung after the deadline addressed to the party: recorded as an overdue delivery (#4166).
	 */
	public const TRIGGER_POST_BREACH = 'postBreach';

	/**
	 * Constructor.
	 *
	 * @param PortalTaskDeliveryService $delivery Records the reminder for the portal.
	 * @param TaskService $tasks Reads the timer's subject task.
	 * @param LoggerInterface $logger Failure reporting.
	 */
	public function __construct(
		private readonly PortalTaskDeliveryService $delivery,
		private readonly TaskService $tasks,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle a fired timer.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-the-overdue-path-is-consumed-from-flow-business-timers-never-rebuilt
	 */
	public function handle(Event $event): void {
		if ($this->isTimerFire(event: $event) === false) {
			return;
		}

		try {
			$this->remind(event: $event);
		} catch (Throwable $failure) {
			// The rung has fired and is recorded by the timers; a reminder that
			// could not be requested costs the party a nudge, never the ask.
			$this->logger->warning(
				'[PortalTaskReminderListener] Could not request a portal reminder: ' . $failure->getMessage(),
				['exception' => $failure]
			);
		}
	}//end handle()

	/**
	 * Decide, per rung, whether the party is reminded through the seam.
	 *
	 * @param Event $event A timer fire, duck-typed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-the-overdue-path-is-consumed-from-flow-business-timers-never-rebuilt
	 */
	private function remind(Event $event): void {
		if ((string)$this->read(source: $event, method: 'getKind') !== 'rung') {
			// Expiry enforcement transitions the task in the timers; the run
			// learns of it through terminality. Nothing to deliver.
			return;
		}

		$rungKey = (string)$this->read(source: $event, method: 'getRungKey');
		$kind = match ($this->triggerOf(rungKey: $rungKey)) {
			self::TRIGGER_PRE_BREACH => PortalTaskDelivery::KIND_REMINDER,
			self::TRIGGER_POST_BREACH => PortalTaskDelivery::KIND_OVERDUE,
			// A slaBreached rung (and anything unknown) escalates inward.
			// Deliberately no delivery to the party here.
			default => null,
		};
		if ($kind === null) {
			return;
		}

		$task = $this->subjectTask(event: $event);
		if ($task === null || (string)$task->getPerformerType() !== Task::PERFORMER_EXTERNAL || $task->isInTerminalState() === true) {
			return;
		}

		$party = (string)$task->getAssignee();
		if ($this->addressesParty(recipients: (array)$this->read(source: $event, method: 'getRecipients'), party: $party) === false) {
			return;
		}

		$message = $this->delivery->messageFor(task: $task);
		$message['rungKey'] = $rungKey;
		$message['priority'] = $this->read(source: $event, method: 'getPriority');
		$message['messageKey'] = $this->read(source: $event, method: 'getMessage');
		if ($kind === PortalTaskDelivery::KIND_OVERDUE) {
			// The line portaliq shows as "If you do not respond: ...".
			$message['consequence'] = $this->consequenceOf(event: $event);
		}

		$this->delivery->request(task: $task, kind: $kind, message: $message);
	}//end remind()

	/**
	 * The rung's trigger: the first segment of its key.
	 *
	 * @param string $rungKey The rung key.
	 *
	 * @return string `preBreach`, `slaBreached`, or whatever an author keyed it.
	 */
	/**
	 * The consequence line a postBreach rung carries, when the event has one.
	 *
	 * @param Event $event The fired timer event.
	 *
	 * @return string|null The consequence, or null when there is none.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-the-overdue-path-is-consumed-from-flow-business-timers-never-rebuilt
	 */
	private function consequenceOf(Event $event): ?string {
		if (method_exists($event, 'getConsequence') === false) {
			return null;
		}

		$consequence = $this->read(source: $event, method: 'getConsequence');
		if (is_string($consequence) === false || trim($consequence) === '') {
			return null;
		}

		return $consequence;
	}//end consequenceOf()

	private function triggerOf(string $rungKey): string {
		$segments = explode(':', $rungKey, 2);

		return trim($segments[0]);
	}//end triggerOf()

	/**
	 * Whether the rung's resolved recipients include the external party.
	 *
	 * The timers resolve the subject task's performer as a recipient with
	 * the performer TYPE as its type (`external`) and the assignee as its id.
	 *
	 * @param array<int, mixed> $recipients The resolved recipients.
	 * @param string $party The task's stored party reference.
	 *
	 * @return bool True when at least one recipient is the party.
	 */
	private function addressesParty(array $recipients, string $party): bool {
		foreach ($recipients as $recipient) {
			if (is_array($recipient) === false) {
				continue;
			}

			$id = (string)($recipient['id'] ?? '');
			$type = (string)($recipient['type'] ?? '');
			if (($type === Task::PERFORMER_EXTERNAL || str_starts_with($id, Task::EXTERNAL_PARTY_PREFIX) === true) && hash_equals($party, $id) === true) {
				return true;
			}
		}

		return false;
	}//end addressesParty()

	/**
	 * The task the timer is anchored to, or null when it is not a task or is gone.
	 *
	 * @param Event $event The timer fire.
	 *
	 * @return Task|null The subject task.
	 */
	private function subjectTask(Event $event): ?Task {
		$timer = $this->read(source: $event, method: 'getTimer');
		// is_callable, not method_exists: FlowTimer's getters are Entity::__call
		// magic, so method_exists() said no on every real timer and no reminder
		// ever reached a party; only a fake timer with real methods passed (#4166).
		if (is_object($timer) === false || is_callable([$timer, 'getSubjectType']) === false || is_callable([$timer, 'getSubjectUuid']) === false) {
			return null;
		}

		if ((string)$this->read(source: $timer, method: 'getSubjectType') !== 'task') {
			return null;
		}

		$uuid = trim((string)$this->read(source: $timer, method: 'getSubjectUuid'));
		if ($uuid === '') {
			return null;
		}

		try {
			return $this->tasks->get(uuid: $uuid);
		} catch (Throwable) {
			return null;
		}
	}//end subjectTask()

	/**
	 * Read one published accessor of a duck-typed object.
	 *
	 * @param object $source The event or timer.
	 * @param string $method The accessor, already known to exist.
	 *
	 * @return mixed What it answers.
	 */
	private function read(object $source, string $method): mixed {
		return $source->{$method}();
	}//end read()

	/**
	 * Whether an event is a timer fire, by the surface it publishes.
	 *
	 * @param Event $event The event.
	 *
	 * @return bool True when every method this listener reads exists.
	 */
	private function isTimerFire(Event $event): bool {
		foreach (['getKind', 'getRungKey', 'getTimer', 'getRecipients', 'getMessage', 'getPriority'] as $method) {
			if (method_exists($event, $method) === false) {
				return false;
			}
		}

		return true;
	}//end isTimerFire()
}//end class
