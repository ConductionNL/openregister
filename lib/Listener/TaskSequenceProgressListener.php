<?php

/**
 * Progresses an approval sequence when one of its tasks reaches terminality.
 *
 * Listens for {@see TaskTerminalEvent} and hands every task that carries a
 * `sequence_uuid` to {@see TaskSequenceService::onTaskTerminal()}. Runs on
 * the COMMITTED dispatch only, in the same request as the completing
 * decision: the next approver is enabled before the deciding call returns,
 * which is the in-request advance the retired engine performed at
 * `ApprovalService.php:193-204`.
 *
 * A failure here is logged, never rethrown: the decision is committed, and a
 * sequence that missed one report is progressed by the next terminal event
 * or read in its true state by the gate.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-approval-consolidation/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCA\OpenRegister\Service\Task\TaskSequenceService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drives sequence advance, rejection propagation and termination.
 *
 * @template-implements IEventListener<TaskTerminalEvent>
 *
 * @spec openspec/changes/flow-approval-consolidation/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
 */
class TaskSequenceProgressListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param TaskSequenceService $sequences The sequence progression.
	 * @param LoggerInterface $logger Diagnostics.
	 */
	public function __construct(
		private readonly TaskSequenceService $sequences,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle the event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-approval-consolidation/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
	 */
	public function handle(Event $event): void {
		if ($event instanceof TaskTerminalEvent === false || $event->isCommitted() === false) {
			return;
		}

		try {
			$this->sequences->onTaskTerminal(task: $event->getTask());
		} catch (Throwable $failure) {
			$this->logger->error(
				'[TaskSequenceProgressListener] Sequence progression failed for task '
				. $event->getTaskUuid() . ': ' . $failure->getMessage(),
				['exception' => $failure]
			);
		}
	}//end handle()
}//end class
