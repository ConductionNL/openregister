<?php

/**
 * A fired pre-breach rung of a task's due timer becomes the task's `due-soon` action.
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
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-task-nearing-its-deadline-warns-its-assignee-once
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use OCA\OpenRegister\Service\Task\TaskDueSoonWarning;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hands every fired timer to the deadline warning; a failure is logged, never raised.
 *
 * @template-implements IEventListener<FlowTimerFiredEvent>
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-task-nearing-its-deadline-warns-its-assignee-once
 */
class TaskDueSoonListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param TaskDueSoonWarning $warning The fired rung to `due-soon` translation.
	 * @param LoggerInterface    $logger  Failure reporting.
	 */
	public function __construct(
		private readonly TaskDueSoonWarning $warning,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record `due-soon` when the fire is a task's deadline warning.
	 *
	 * The rung is already claimed in the timer ledger when this runs; a
	 * warning that cannot be recorded must not abort the sweep that is firing
	 * other people's deadlines, so it is logged naming the timer and dropped.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-task-nearing-its-deadline-warns-its-assignee-once
	 */
	public function handle(Event $event): void {
		if (($event instanceof FlowTimerFiredEvent) === false) {
			return;
		}

		try {
			$this->warning->onRungFired(event: $event);
		} catch (Throwable $failure) {
			$this->logger->warning(
				'[TaskDueSoonListener] The deadline warning could not be recorded: ' . $failure->getMessage(),
				['timer' => $event->getTimer()->getUuid(), 'rung' => $event->getRungKey()]
			);
		}
	}//end handle()
}//end class
