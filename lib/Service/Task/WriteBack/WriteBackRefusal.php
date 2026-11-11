<?php

/**
 * Makes a refused calendar write-back visible.
 *
 * Refusal is visible (flow-task-inbox-projections D-2 rule 4): the denial is
 * audited (unless the lifecycle already did), the projection is re-rendered
 * to the engine's truth, the actor is told, and the refusal is logged — in
 * that order. There is no silent-revert branch.
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

use OCA\OpenRegister\Service\Task\TaskProjectionService;
use Psr\Log\LoggerInterface;

/**
 * Audit, restore the projection, tell the actor, log.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-unauthorized-write-back-is-undone-and-explained
 */
final class WriteBackRefusal {

	/**
	 * Constructor.
	 *
	 * @param WriteBackRefusalAudit  $audit       Records denials the lifecycle did not already record.
	 * @param TaskProjectionService  $projections Failure-isolated reconciliation.
	 * @param WriteBackRefusalNotice $notice      Tells the actor.
	 * @param LoggerInterface        $logger      Where the refusal is logged.
	 */
	public function __construct(
		private readonly WriteBackRefusalAudit $audit,
		private readonly TaskProjectionService $projections,
		private readonly WriteBackRefusalNotice $notice,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Refuse an attempt the lifecycle did not see: audit it, then restore,
	 * tell and log.
	 *
	 * @param WriteBackAttempt $attempt The refused attempt.
	 * @param string           $reason  Why it was refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-unauthorized-write-back-is-undone-and-explained
	 */
	public function refuse(WriteBackAttempt $attempt, string $reason): void {
		$this->audit->record(attempt: $attempt, reason: $reason);
		$this->refuseRecorded(attempt: $attempt, reason: $reason);
	}//end refuse()

	/**
	 * Refuse an attempt whose denial the lifecycle already audited: restore,
	 * tell and log, without a second audit entry.
	 *
	 * @param WriteBackAttempt $attempt The refused attempt.
	 * @param string           $reason  Why it was refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-unauthorized-write-back-is-undone-and-explained
	 */
	public function refuseRecorded(WriteBackAttempt $attempt, string $reason): void {
		$this->projections->reconcileTask(task: $attempt->task);
		$this->notice->tell(attempt: $attempt, reason: $reason);

		$this->logger->info(
			'[TaskVtodoWriteBackGate] Refused a calendar write-back: ' . $reason,
			['task' => $attempt->task->getUuid(), 'verb' => $attempt->verb, 'actor' => $attempt->actor]
		);
	}//end refuseRecorded()
}//end class
