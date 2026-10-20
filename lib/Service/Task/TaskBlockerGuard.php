<?php

/**
 * What a task may declare about the task it waits on.
 *
 * The blocked STATE is derived on read from the blocker's terminality, so a
 * caller may name a blocker but may never write the state. A blocker must be
 * another task that exists, and the chain of blockers may not lead back to the
 * task being created.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/a-task-may-wait-on-another-task/specs/flow-tasks/spec.md#requirement-a-task-may-wait-on-another-task-and-waits-out-of-sight
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Exception\TaskValidationException;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Refuses a hand-written blocked state, a self-block, an unknown blocker and a loop.
 *
 * @spec openspec/changes/a-task-may-wait-on-another-task/specs/flow-tasks/spec.md#requirement-a-task-may-wait-on-another-task-and-waits-out-of-sight
 */
class TaskBlockerGuard {

	/**
	 * How far the chain of blockers is walked before it counts as a loop.
	 */
	private const MAX_CHAIN = 50;

	/**
	 * Constructor.
	 *
	 * @param TaskMapper $tasks Reads the blockers.
	 */
	public function __construct(
		private readonly TaskMapper $tasks,
	) {

	}//end __construct()

	/**
	 * Refuse a payload that writes the blocked state by hand.
	 *
	 * Only the HTTP create path calls this: the trusted import path keeps the
	 * legacy `blocked` state mapping for migrated approval chains.
	 *
	 * @param array<string, mixed> $data The create payload.
	 *
	 * @return void
	 *
	 * @throws TaskValidationException When the payload carries `blocked` or the state `blocked`.
	 *
	 * @spec openspec/changes/a-task-may-wait-on-another-task/specs/flow-tasks/spec.md#requirement-a-task-may-wait-on-another-task-and-waits-out-of-sight
	 */
	public function refuseHandWrittenState(array $data): void {
		$state = strtolower(trim((string)($data['state'] ?? '')));
		if (array_key_exists('blocked', $data) === true || $state === 'blocked') {
			throw new TaskValidationException(
				message: 'A task is blocked while the task it names in blockedBy is open. Name that task in blockedBy; the blocked state cannot be written.'
			);
		}
	}//end refuseHandWrittenState()

	/**
	 * Refuse a blocker that is the task itself, does not exist, or closes a loop.
	 *
	 * With `$mayRead`, the caller's own read rule: a blocker the caller may not
	 * read is refused in the words of one that does not exist. Without that, a
	 * person who knows a task uuid could tell from the answer (and later from
	 * the `blocked` flag of their own task) that a task of somebody else's
	 * exists and whether it is still open. Trusted callers (flows, migrations)
	 * pass none: they declare blockers of their own making.
	 *
	 * @param Task          $task    The task about to be created, uuid already set.
	 * @param callable|null $mayRead Answers whether the caller may read a given Task; null for a trusted caller.
	 *
	 * @return void
	 *
	 * @throws TaskValidationException On any refused blocker.
	 *
	 * @spec openspec/changes/a-task-may-wait-on-another-task/specs/flow-tasks/spec.md#requirement-a-task-may-wait-on-another-task-and-waits-out-of-sight
	 */
	public function assertDeclarable(Task $task, ?callable $mayRead = null): void {
		$blockedBy = (string)$task->getBlockedBy();
		if ($blockedBy === '') {
			return;
		}

		$own = (string)$task->getUuid();
		if ($blockedBy === $own) {
			throw new TaskValidationException(message: 'A task cannot wait on itself.');
		}

		$next = $blockedBy;
		for ($step = 0; $step < self::MAX_CHAIN && $next !== ''; $step++) {
			try {
				$blocker = $this->tasks->findByUuid(uuid: $next);
			} catch (DoesNotExistException) {
				if ($next === $blockedBy) {
					throw new TaskValidationException(message: sprintf("blockedBy names task '%s', and no task has that uuid.", $blockedBy));
				}

				// A removed task further up the chain blocks nothing.
				return;
			}

			if ($this->isHiddenFromCaller(blocker: $blocker, named: ($next === $blockedBy), mayRead: $mayRead) === true) {
				throw new TaskValidationException(message: sprintf("blockedBy names task '%s', and no task has that uuid.", $blockedBy));
			}

			$next = (string)$blocker->getBlockedBy();
			if ($next === $own) {
				throw new TaskValidationException(
					message: sprintf("blockedBy '%s' would make a loop: that task already waits, through its chain, on this one.", $blockedBy)
				);
			}
		}
	}//end assertDeclarable()

	/**
	 * Whether the caller may not read the blocker they named.
	 *
	 * Only the task the caller NAMES is held to their read rule: the chain
	 * behind it is walked for loops and never reported back.
	 *
	 * @param Task          $blocker The blocker found.
	 * @param bool          $named   Whether it is the one the caller named, not one further up its chain.
	 * @param callable|null $mayRead The caller's read rule; null for a trusted caller.
	 *
	 * @return bool True when it must be refused as if it did not exist.
	 */
	private function isHiddenFromCaller(Task $blocker, bool $named, ?callable $mayRead): bool {
		return $named === true && $mayRead !== null && $mayRead($blocker) !== true;
	}//end isHiddenFromCaller()

	/**
	 * Refuse a task that waits on an open blocker as one that does not exist.
	 *
	 * For a seam that never lists a held-back task (the portal): reading or
	 * completing one by its uuid must not be a way around the list.
	 *
	 * @param Task $task The task.
	 *
	 * @return void
	 *
	 * @throws DoesNotExistException When the task waits on a blocker that is still open.
	 *
	 * @spec openspec/changes/a-task-may-wait-on-another-task/specs/flow-tasks/spec.md#requirement-a-task-may-wait-on-another-task-and-waits-out-of-sight
	 */
	public function assertNotHeldBack(Task $task): void {
		if ($this->isHeldBack(task: $task) === true) {
			throw new DoesNotExistException('No such task.');
		}
	}//end assertNotHeldBack()

	/**
	 * Whether the task waits on a blocker that is still open.
	 *
	 * A blocker that no longer exists blocks nothing.
	 *
	 * @param Task $task The task.
	 *
	 * @return bool True when its blocker exists and is not terminal.
	 *
	 * @spec openspec/changes/a-task-may-wait-on-another-task/specs/flow-tasks/spec.md#requirement-a-task-may-wait-on-another-task-and-waits-out-of-sight
	 */
	public function isHeldBack(Task $task): bool {
		$blockedBy = (string)$task->getBlockedBy();
		if ($blockedBy === '') {
			return false;
		}

		try {
			return $this->tasks->findByUuid(uuid: $blockedBy)->getIsTerminal() !== true;
		} catch (DoesNotExistException) {
			return false;
		}
	}//end isHeldBack()
}//end class
