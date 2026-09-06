<?php

/**
 * Cancellation propagation for business timers: a terminal subject cancels
 * its open timers.
 *
 * Listens for {@see TaskTerminalEvent} (dispatched by TaskService right after
 * the transaction that made the task terminal commits — the platform's one
 * terminality seam, shared with the user-task node's run continuation) and
 * {@see FlowRunTerminalEvent} (dispatched from FlowRunMapper inside the run's
 * terminal write) and CANCELS — never deletes — every armed or suspended
 * timer bound to the subject, with the reason recorded (design D-9). The
 * cancellation lands in the same request as the terminal write, before any
 * 300-second sweep can run; the crash window between commit and listener is
 * covered by the invariant repair step, which counts orphans.
 *
 * Idempotent by construction: the cancel reads only open timers, so observing
 * terminality twice cancels nothing twice. A failure is logged, not rethrown:
 * the subject's own terminal write must never be unwound by timer
 * bookkeeping, and the orphan repair check counts what was missed.
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
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Event\FlowRunTerminalEvent;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Cancels a terminal subject's open timers.
 *
 * @template-implements IEventListener<TaskTerminalEvent|FlowRunTerminalEvent>
 */
class FlowTimerSubjectTerminalListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param FlowTimerService $timers The timer lifecycle.
	 * @param LoggerInterface $logger Failure reporting.
	 */
	public function __construct(
		private readonly FlowTimerService $timers,
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
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
	 */
	public function handle(Event $event): void {
		try {
			if ($event instanceof TaskTerminalEvent === true) {
				$task = $event->getTask();
				$uuid = (string)$task->getUuid();
				$cancelled = $this->timers->cancelForSubject(
					subjectType: 'task',
					subjectUuid: $uuid,
					reason: sprintf(
						"Task '%s' reached terminal state '%s' (outcome '%s').",
						$uuid,
						(string)$task->getState(),
						(string)$task->getOutcome()
					),
					actor: sprintf('task:%s', $uuid)
				);
				$this->report(count: $cancelled, subject: 'task ' . $uuid);
				return;
			}

			if ($event instanceof FlowRunTerminalEvent === true) {
				$cancelled = $this->timers->cancelForRun(
					runUuid: $event->getRunUuid(),
					reason: sprintf("Run '%s' reached terminal status '%s'.", $event->getRunUuid(), $event->getStatus()),
					actor: sprintf('flow-run:%s', $event->getRunUuid())
				);
				$this->report(count: $cancelled, subject: 'run ' . $event->getRunUuid());
			}
		} catch (Throwable $failure) {
			$this->logger->error(
				'[FlowTimerSubjectTerminalListener] Timer cancellation failed: ' . $failure->getMessage(),
				['exception' => $failure]
			);
		}//end try
	}//end handle()

	/**
	 * Log a non-zero cancellation count.
	 *
	 * @param int $count How many timers were cancelled.
	 * @param string $subject The subject, for the message.
	 *
	 * @return void
	 */
	private function report(int $count, string $subject): void {
		if ($count > 0) {
			$this->logger->info(sprintf('[FlowTimerSubjectTerminalListener] Cancelled %d timer(s) of %s.', $count, $subject));
		}
	}//end report()
}//end class
