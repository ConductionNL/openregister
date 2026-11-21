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

		$this->assertNoLoop(task: $task, next: (string)$this->namedBlocker(task: $task, mayRead: $mayRead)->getBlockedBy());
	}//end assertDeclarable()

	/**
	 * The task the caller names as blocker, refused when absent or unreadable.
	 *
	 * A blocker the caller may not read answers exactly like one that does
	 * not exist, so the refusal discloses nothing.
	 *
	 * @param Task          $task    The task that declares its blocker.
	 * @param callable|null $mayRead The caller's read rule; null for a trusted caller.
	 *
	 * @return Task The named blocker.
	 *
	 * @throws TaskValidationException When it does not exist or the caller may not read it.
	 */
	private function namedBlocker(Task $task, ?callable $mayRead): Task {
		$blockedBy = (string)$task->getBlockedBy();
		try {
			$blocker = $this->tasks->findByUuid(uuid: $blockedBy);
		} catch (DoesNotExistException) {
			throw new TaskValidationException(message: sprintf("blockedBy names task '%s', and no task has that uuid.", $blockedBy));
		}

		if ($this->isHiddenFromCaller(blocker: $blocker, mayRead: $mayRead) === true) {
			throw new TaskValidationException(message: sprintf("blockedBy names task '%s', and no task has that uuid.", $blockedBy));
		}

		return $blocker;
	}//end namedBlocker()

	/**
	 * Walk the chain behind the named blocker and refuse a loop back to the task.
	 *
	 * At most MAX_CHAIN tasks are read, the named blocker included. A removed
	 * task further up the chain blocks nothing, and ends the walk.
	 *
	 * @param Task   $task The task that declares its blocker.
	 * @param string $next What the named blocker itself waits on.
	 *
	 * @return void
	 *
	 * @throws TaskValidationException When the chain leads back to the task.
	 */
	private function assertNoLoop(Task $task, string $next): void {
		if ($this->chainReaches(own: (string)$task->getUuid(), next: $next) === false) {
			return;
		}

		throw new TaskValidationException(
			message: sprintf(
				"blockedBy '%s' would make a loop: that task already waits, through its chain, on this one.",
				(string)$task->getBlockedBy()
			)
		);
	}//end assertNoLoop()

	/**
	 * Whether walking the blocker chain from $next comes back to the task itself.
	 *
	 * The walk stops, answering no, at the end of the chain, at a removed task,
	 * or after {@see self::MAX_CHAIN} steps.
	 *
	 * @param string $own  The task's own uuid.
	 * @param string $next The first blocker in the chain.
	 *
	 * @return bool True when the chain loops back to the task.
	 */
	private function chainReaches(string $own, string $next): bool {
		for ($step = 1; $next !== $own; $step++) {
			if ($step >= self::MAX_CHAIN || $next === '') {
				return false;
			}

			$next = $this->nextInChain(uuid: $next);
			if ($next === null) {
				return false;
			}
		}

		return true;
	}//end chainReaches()

	/**
	 * What the given task waits on, or null when it no longer exists.
	 *
	 * @param string $uuid A task further up the chain.
	 *
	 * @return string|null Its blocker uuid ('' for none), or null when removed.
	 */
	private function nextInChain(string $uuid): ?string {
		try {
			return (string)$this->tasks->findByUuid(uuid: $uuid)->getBlockedBy();
		} catch (DoesNotExistException) {
			return null;
		}
	}//end nextInChain()

	/**
	 * Whether the caller may not read the blocker they named.
	 *
	 * Only the task the caller NAMES is held to their read rule: the chain
	 * behind it is walked for loops and never reported back.
	 *
	 * @param Task          $blocker The blocker the caller named.
	 * @param callable|null $mayRead The caller's read rule; null for a trusted caller.
	 *
	 * @return bool True when it must be refused as if it did not exist.
	 */
	private function isHiddenFromCaller(Task $blocker, ?callable $mayRead): bool {
		return $mayRead !== null && $mayRead($blocker) !== true;
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
