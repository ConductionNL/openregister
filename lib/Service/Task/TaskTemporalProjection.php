<?php

/**
 * The ONE derivation of overdue — computed, never stored.
 *
 * Three fleet schemas store `overdue` as a status value, and decidesk's
 * `actionOverdue` notification filters on it, so it fires only when
 * something remembered to write the value. A clock-derived fact maintained
 * by hand is wrong between writes; this class is the single place the clock
 * is consulted instead.
 *
 * Both consumers go through here: the API projection calls {@see project()},
 * and the inbox's datastore filter takes its clock instant from
 * {@see now()} and applies the SAME comparison ("effective deadline strictly
 * before now") as a WHERE predicate (`TaskMapper::applyInboxPredicates`).
 * One derivation, no second opinion. Nothing writes any of these values
 * anywhere.
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
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-overdue-is-derived-and-must-not-be-stored
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use DateTime;
use DateTimeInterface;
use OCA\OpenRegister\Db\Task;

/**
 * Computes overdue, days-until-due and days-overdue from the stored deadlines.
 *
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-overdue-is-derived-and-must-not-be-stored
 */
final class TaskTemporalProjection {

	/**
	 * The clock instant the derivation runs against.
	 *
	 * @return DateTime Now.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-overdue-is-derived-and-must-not-be-stored
	 */
	public function now(): DateTime {
		return new DateTime();
	}//end now()

	/**
	 * The temporal projection of one task.
	 *
	 * The effective deadline is `due_at`, or `expires_at` where only that is
	 * set. Passing `due_at` changes ONLY what this projection reports — the
	 * task's state is untouched by the clock (due_at advises; the enforcing
	 * sweep on expires_at belongs to flow-business-timers).
	 *
	 * @param Task $task The task to project.
	 * @param DateTimeInterface|null $now The clock, injectable for tests;
	 *                                    null means the real clock.
	 *
	 * @return array{overdue: bool, daysUntilDue: int|null, daysOverdue: int|null} The projection.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-overdue-is-derived-and-must-not-be-stored
	 */
	public function project(Task $task, ?DateTimeInterface $now = null): array {
		$now ??= $this->now();
		$deadline = ($task->getDueAt() ?? $task->getExpiresAt());

		if ($deadline === null) {
			return [
				'overdue' => false,
				'daysUntilDue' => null,
				'daysOverdue' => null,
			];
		}

		$overdue = ($deadline < $now);
		$seconds = ($deadline->getTimestamp() - $now->getTimestamp());
		$days = intdiv(abs($seconds), 86400);

		if ($overdue === true) {
			return [
				'overdue' => true,
				'daysUntilDue' => null,
				'daysOverdue' => $days,
			];
		}

		return [
			'overdue' => false,
			'daysUntilDue' => $days,
			'daysOverdue' => null,
		];
	}//end project()
}//end class
