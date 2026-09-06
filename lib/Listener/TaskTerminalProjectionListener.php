<?php

/**
 * Dismiss-on-terminality: when a task is announced terminal, every
 * outstanding notification about it is withdrawn and its calendar entry is
 * re-rendered terminal.
 *
 * Bound to the terminal-task event the user-task node announces after its
 * commit (flow-user-task-node, `TaskTerminalEvent`), by NAME, because that
 * class ships with the other change. The listener therefore duck-types the
 * event: anything carrying a `getTask()` whose task is terminal qualifies.
 * It is idempotent beside TaskNotificationListener and
 * TaskCalendarProjectionListener, which already withdraw and re-render on
 * the transition itself: a second withdrawal finds nothing to withdraw, and
 * a second render finds the content hash unchanged and writes nothing. Two
 * events, one outcome, no polling.
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
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-notification-for-an-answered-task-is-withdrawn
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\Task\TaskProjectionService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A terminal task leaves no approve button standing and no open VTODO.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-notification-for-an-answered-task-is-withdrawn
 */
class TaskTerminalProjectionListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param INotificationManager $notifications Withdrawal only, never delivery.
	 * @param TaskProjectionService $projections Failure-isolated re-render.
	 * @param LoggerInterface $logger Failure reporting.
	 */
	public function __construct(
		private readonly INotificationManager $notifications,
		private readonly TaskProjectionService $projections,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle a terminal-task announcement.
	 *
	 * @param Event $event The dispatched event; only one carrying a terminal Task is acted on.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-notification-for-an-answered-task-is-withdrawn
	 */
	public function handle(Event $event): void {
		$task = $this->terminalTaskOf(event: $event);
		if ($task === null) {
			return;
		}

		try {
			$notification = $this->notifications->createNotification();
			$notification->setApp('openregister')->setObject('object', (string)$task->getUuid());
			$this->notifications->markProcessed($notification);
		} catch (Throwable $failure) {
			$this->logger->warning(
				'[TaskTerminalProjectionListener] Could not withdraw the notifications of a terminal task: ' . $failure->getMessage(),
				['task' => $task->getUuid()]
			);
		}

		$this->projections->reconcileTask(task: $task);
	}//end handle()

	/**
	 * The terminal task an event carries, or null when it carries none.
	 *
	 * @param Event $event The event.
	 *
	 * @return Task|null The task, only when terminal.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-notification-for-an-answered-task-is-withdrawn
	 */
	private function terminalTaskOf(Event $event): ?Task {
		if (method_exists($event, 'getTask') === false) {
			return null;
		}

		$task = $event->getTask();
		if (($task instanceof Task) === false || $task->isInTerminalState() === false || trim((string)$task->getUuid()) === '') {
			return null;
		}

		return $task;
	}//end terminalTaskOf()
}//end class
