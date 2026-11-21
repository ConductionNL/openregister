<?php

/**
 * Tells the actor of a refused calendar write-back which task and why.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task\WriteBack
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-unauthorized-write-back-is-undone-and-explained
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task\WriteBack;

use OCA\OpenRegister\Notifications\Service\Notification\TaskNotificationRulesInterface;
use OCA\OpenRegister\Notifications\Service\Notification\TaskTransitionNotifierInterface;
use OCA\OpenRegister\Notifications\Service\Notification\TransitionNotice;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The refusal notice, through the declarative rule set (no imperative notification call).
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-unauthorized-write-back-is-undone-and-explained
 */
final class WriteBackRefusalNotice {

	/**
	 * Constructor.
	 *
	 * @param TaskTransitionNotifierInterface $notifier Delivery, through the task rule set.
	 * @param LoggerInterface                 $logger   Failure reporting.
	 */
	public function __construct(
		private readonly TaskTransitionNotifierInterface $notifier,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Tell the actor; an actor who cannot be named cannot be told, which is
	 * logged and never widened to anyone else. A failing dispatch is logged.
	 *
	 * @param WriteBackAttempt $attempt The refused attempt.
	 * @param string           $reason  The refusal reason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-unauthorized-write-back-is-undone-and-explained
	 */
	public function tell(WriteBackAttempt $attempt, string $reason): void {
		if ($attempt->actor === null || trim($attempt->actor) === '') {
			$this->logger->info('[TaskVtodoWriteBackGate] Refusal has no nameable actor to notify.', ['task' => $attempt->task->getUuid()]);

			return;
		}

		try {
			$this->dispatch(attempt: $attempt, reason: $reason);
		} catch (Throwable $failure) {
			$this->logger->warning(
				'[TaskVtodoWriteBackGate] Could not notify the actor of a refusal: ' . $failure->getMessage(),
				['task' => $attempt->task->getUuid(), 'actor' => $attempt->actor]
			);
		}
	}//end tell()

	/**
	 * Dispatch the `write-back-refused` transition for the (named) actor.
	 *
	 * @param WriteBackAttempt $attempt The refused attempt, its actor named.
	 * @param string           $reason  The refusal reason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-unauthorized-write-back-is-undone-and-explained
	 */
	private function dispatch(WriteBackAttempt $attempt, string $reason): void {
		$actor = (string)$attempt->actor;
		$notice = new TransitionNotice(
			extra: ['writeBackActor' => $actor, 'writeBackReason' => $reason],
			context: ['action' => TaskNotificationRulesInterface::ACTION_WRITE_BACK_REFUSED, 'actor' => $actor]
		);
		$this->notifier->notifyTransition(task: $attempt->task, notice: $notice);
	}//end dispatch()
}//end class
