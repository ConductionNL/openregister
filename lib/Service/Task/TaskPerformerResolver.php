<?php

/**
 * Resolves a candidate pool to an assignee — or, honestly, to nobody.
 *
 * Implements the five routing strategies over the performer model of design
 * D-5. The one rule that matters more than any strategy: a strategy that
 * resolves to NOBODY, with no fallback, leaves the task POOLED. The tempting
 * fallbacks — assign to the requester, the first pool member, a system
 * identity — each turn a routing misconfiguration into a silently answerable
 * task, so none of them exists here.
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
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-performer-model-spans-people-groups-agents-and-workers
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCP\IGroupManager;
use Throwable;

/**
 * The five routing strategies, and the pool expansion under them.
 *
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-performer-model-spans-people-groups-agents-and-workers
 */
class TaskPerformerResolver {

	/**
	 * Constructor.
	 *
	 * @param TaskMapper $tasks Supplies the load and recency reads the
	 *                          `least-loaded` and `round-robin` strategies
	 *                          rank by.
	 * @param IGroupManager|null $groupManager Expands candidate groups and
	 *                                         roles to members. Nullable so
	 *                                         the resolver stays
	 *                                         constructible without a
	 *                                         container; absent, groups and
	 *                                         roles expand to NOBODY — which
	 *                                         leaves tasks pooled, the safe
	 *                                         direction.
	 */
	public function __construct(
		private readonly TaskMapper $tasks,
		private readonly ?IGroupManager $groupManager = null,
	) {

	}//end __construct()

	/**
	 * Resolve the task's pool under its routing strategy.
	 *
	 * @param Task $task The task whose pool is being resolved.
	 *
	 * @return string|null The chosen uid, or null to LEAVE THE TASK POOLED.
	 *         Null is a first-class answer, not a failure: the pool members
	 *         can still claim.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-performer-model-spans-people-groups-agents-and-workers
	 */
	public function resolveAssignee(Task $task): ?string {
		$pool = $this->expandPool(task: $task);
		$strategy = trim((string)$task->getRoutingStrategy());

		$chosen = null;
		switch ($strategy) {
			case 'single-role':
				// A role that resolves to exactly one person IS an
				// assignment; any other size is a pool.
				if (count($pool) === 1) {
					$chosen = $pool[0];
				}
				break;
			case 'or-set':
				// The whole point of an or-set is that ANY member answers:
				// nobody is picked, everybody may claim.
				$chosen = null;
				break;
			case 'hierarchical':
				// The pool is ordered (users first, in declared order): the
				// first tier answers, the next only when it is empty.
				$chosen = ($pool[0] ?? null);
				break;
			case 'round-robin':
				$chosen = $this->pickLeastRecentlyAssigned(pool: $pool);
				break;
			case 'least-loaded':
				$chosen = $this->pickLeastLoaded(pool: $pool);
				break;
			default:
				// No strategy: nothing to resolve, the pool stands.
				$chosen = null;
				break;
		}//end switch

		if ($chosen !== null) {
			return $chosen;
		}

		// The ONLY fallback is the explicitly configured one.
		$fallback = trim((string)$task->getRoutingFallback());
		if ($fallback !== '') {
			return $fallback;
		}

		return null;
	}//end resolveAssignee()

	/**
	 * Expand the candidate pool to uids, in declared order.
	 *
	 * Order matters to `hierarchical`; the other strategies treat the result
	 * as a set. Groups and the role group expand through the group backend;
	 * without one they expand to nothing, which pools rather than guesses.
	 *
	 * @param Task $task The task whose pool is expanded.
	 *
	 * @return array<int, string> The candidate uids, deduplicated, in order.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-performer-model-spans-people-groups-agents-and-workers
	 */
	public function expandPool(Task $task): array {
		$pool = [];
		foreach (($task->getCandidateUsers() ?? []) as $uid) {
			$pool[] = (string)$uid;
		}

		foreach (($task->getCandidateGroups() ?? []) as $groupId) {
			foreach ($this->groupMembers(groupId: (string)$groupId) as $uid) {
				$pool[] = $uid;
			}
		}

		$role = trim((string)$task->getCandidateRole());
		if ($role !== '') {
			foreach ($this->groupMembers(groupId: $role) as $uid) {
				$pool[] = $uid;
			}
		}

		return array_values(array_unique($pool));
	}//end expandPool()

	/**
	 * The members of one group, or nobody when the backend cannot answer.
	 *
	 * @param string $groupId The group id (or role name).
	 *
	 * @return array<int, string> The member uids.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-performer-model-spans-people-groups-agents-and-workers
	 */
	private function groupMembers(string $groupId): array {
		if ($this->groupManager === null || $groupId === '') {
			return [];
		}

		try {
			$group = $this->groupManager->get($groupId);
			if ($group === null) {
				return [];
			}

			$members = [];
			foreach ($group->getUsers() as $user) {
				$members[] = $user->getUID();
			}

			return $members;
		} catch (Throwable) {
			return [];
		}
	}//end groupMembers()

	/**
	 * `round-robin`: the member handed a task least recently goes next.
	 *
	 * A member never assigned anything sorts first. Ties break on uid so
	 * the answer is deterministic under test and under retry.
	 *
	 * @param array<int, string> $pool The candidate uids.
	 *
	 * @return string|null The chosen uid, or null for an empty pool.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-performer-model-spans-people-groups-agents-and-workers
	 */
	private function pickLeastRecentlyAssigned(array $pool): ?string {
		if ($pool === []) {
			return null;
		}

		$latest = $this->tasks->latestAssignedAt(uids: $pool);
		usort(
			$pool,
			static function (string $a, string $b) use ($latest): int {
				$atA = ($latest[$a] ?? '');
				$atB = ($latest[$b] ?? '');
				if ($atA !== $atB) {
					return strcmp($atA, $atB);
				}

				return strcmp($a, $b);
			}
		);

		return $pool[0];
	}//end pickLeastRecentlyAssigned()

	/**
	 * `least-loaded`: the member with the fewest open tasks goes next.
	 *
	 * @param array<int, string> $pool The candidate uids.
	 *
	 * @return string|null The chosen uid, or null for an empty pool.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-performer-model-spans-people-groups-agents-and-workers
	 */
	private function pickLeastLoaded(array $pool): ?string {
		if ($pool === []) {
			return null;
		}

		$counts = $this->tasks->countOpenAssigned(uids: $pool);
		usort(
			$pool,
			static function (string $a, string $b) use ($counts): int {
				$countA = ($counts[$a] ?? 0);
				$countB = ($counts[$b] ?? 0);
				if ($countA !== $countB) {
					return ($countA <=> $countB);
				}

				return strcmp($a, $b);
			}
		);

		return $pool[0];
	}//end pickLeastLoaded()
}//end class
