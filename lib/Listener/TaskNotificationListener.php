<?php

/**
 * Bridges a committed task transition to the declarative notification
 * dispatcher, and withdraws notifications that stopped being actionable.
 *
 * The same seam SystemEntityNotificationListener uses: wrap the entity as a
 * virtual ObjectEntity, load the synthetic schema from the rule registry,
 * call dispatchWithSchema() with `context['action']` set to the recorded
 * transition action. No second notification pipeline exists
 * (flow-task-inbox-projections, design D-3).
 *
 * Withdrawal (design D-4) uses IManager::markProcessed(), the call
 * NotificationService already uses: on a terminal transition and on an
 * assignee change, every outstanding notification about the task is
 * withdrawn BEFORE the new one is delivered, so a claimed pool task clears
 * the other members' buttons and a task cancelled by propagation leaves no
 * approve button standing.
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
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-task-lifecycle-delivery-is-automatic-and-declarative
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Event\TaskTransitionedEvent;
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Service\Notification\TaskNotificationRules;
use OCA\OpenRegister\Service\Notification\TaskObjectAdapter;
use OCA\OpenRegister\Service\Task\TaskInboxService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Task transitions become declarative notifications.
 *
 * @template-implements IEventListener<TaskTransitionedEvent>
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-task-lifecycle-delivery-is-automatic-and-declarative
 */
class TaskNotificationListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param AnnotationNotificationDispatcher $dispatcher The one dispatcher.
	 * @param TaskNotificationRules $rules The task rule registry.
	 * @param TaskInboxService $inbox The row the adapter is built from.
	 * @param INotificationManager $notifications Withdrawal only, never delivery.
	 * @param LoggerInterface $logger Failure reporting.
	 */
	public function __construct(
		private readonly AnnotationNotificationDispatcher $dispatcher,
		private readonly TaskNotificationRules $rules,
		private readonly TaskInboxService $inbox,
		private readonly INotificationManager $notifications,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle a committed transition.
	 *
	 * A failure here is logged naming the task and never rethrown: the
	 * transition has committed, and delivery is not a condition of it.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-task-lifecycle-delivery-is-automatic-and-declarative
	 */
	public function handle(Event $event): void {
		if (($event instanceof TaskTransitionedEvent) === false) {
			return;
		}

		$task = $event->getTask();

		try {
			if ($task->isInTerminalState() === true || $event->assigneeChanged() === true) {
				$this->withdraw(task: $task);
			}

			$row = $this->inbox->enrich(task: $task);
			$adapter = new TaskObjectAdapter(
				task: $task,
				row: $row,
				extra: ['previousAssignee' => $event->getPreviousAssignee()]
			);

			$this->dispatcher->dispatchWithSchema(
				object: $adapter,
				trigger: 'transition',
				context: [
					'action' => $event->getAction(),
					'from' => $event->getPreviousState(),
					'to' => $task->getState(),
					'actor' => $event->getActor(),
				],
				schema: $this->rules->buildSchema()
			);
		} catch (Throwable $failure) {
			$this->logger->warning(
				'[TaskNotificationListener] Task notification failed; the transition is unaffected: ' . $failure->getMessage(),
				['task' => $task->getUuid(), 'action' => $event->getAction()]
			);
		}//end try
	}//end handle()

	/**
	 * Withdraw every outstanding notification about a task, for every recipient.
	 *
	 * @param Task $task The task.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-notification-for-an-answered-task-is-withdrawn
	 */
	private function withdraw(Task $task): void {
		$uuid = (string)$task->getUuid();
		if ($uuid === '') {
			return;
		}

		$notification = $this->notifications->createNotification();
		$notification->setApp('openregister')->setObject('object', $uuid);
		$this->notifications->markProcessed($notification);
	}//end withdraw()
}//end class
