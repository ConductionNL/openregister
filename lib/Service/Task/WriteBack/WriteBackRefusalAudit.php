<?php

/**
 * Records a refused calendar write-back the lifecycle did not see.
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

use OCA\OpenRegister\Db\TaskAudit;
use OCA\OpenRegister\Db\TaskAuditMapperInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The unauthorized audit entry of a write-back refusal.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-unauthorized-write-back-is-undone-and-explained
 */
final class WriteBackRefusalAudit {

	/**
	 * Constructor.
	 *
	 * @param TaskAuditMapperInterface $audits Where the entry is appended.
	 * @param LoggerInterface $logger Where a failed append is reported.
	 */
	public function __construct(
		private readonly TaskAuditMapperInterface $audits,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Append an unauthorized entry; a task without an id gets none, and a
	 * failing append is logged, never thrown.
	 *
	 * @param WriteBackAttempt $attempt The refused attempt.
	 * @param string           $reason  The denial reason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-unauthorized-write-back-is-undone-and-explained
	 */
	public function record(WriteBackAttempt $attempt, string $reason): void {
		if ($attempt->task->getId() === null) {
			return;
		}

		try {
			$this->audits->insert($this->entryFor(attempt: $attempt, reason: $reason));
		} catch (Throwable $failure) {
			$this->logger->warning(
				'[TaskVtodoWriteBackGate] Could not record a write-back denial: ' . $failure->getMessage(),
				['task' => $attempt->task->getUuid(), 'verb' => $attempt->verb]
			);
		}
	}//end record()

	/**
	 * The audit entry for a refused attempt.
	 *
	 * @param WriteBackAttempt $attempt The refused attempt.
	 * @param string           $reason  The denial reason.
	 *
	 * @return TaskAudit The unsaved entry.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-unauthorized-write-back-is-undone-and-explained
	 */
	private function entryFor(WriteBackAttempt $attempt, string $reason): TaskAudit {
		$task  = $attempt->task;
		$audit = new TaskAudit();
		$audit->setTaskId((int)$task->getId());
		$audit->setAction($attempt->verb);
		$audit->setStateAfter($task->getState());
		$audit->setActor($attempt->actor);
		$audit->setPerformerType($task->getPerformerType());
		$audit->setOnBehalfOf($task->getOnBehalfOf());
		$audit->setMandate($task->getMandate());
		$audit->setReason('Calendar write-back refused: ' . $reason);
		$audit->setAuthorized(false);

		return $audit;
	}//end entryFor()
}//end class
