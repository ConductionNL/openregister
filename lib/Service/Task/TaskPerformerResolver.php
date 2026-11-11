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
 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-performer-model-spans-people-groups-agents-and-workers
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use Throwable;

/**
 * The five routing strategies, and the pool expansion under them.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-performer-model-spans-people-groups-agents-and-workers
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
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-performer-model-spans-people-groups-agents-and-workers
	 */
	public function resolveAssignee(Task $task): ?string {
		$pool = $this->expandPool(task: $task);
		$chosen = $this->choose(strategy: trim((string)$task->getRoutingStrategy()), pool: $pool);
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
	 * Pick a member of the pool under one routing strategy.
	 *
	 * `or-set` picks nobody on purpose: ANY member answers, everybody may
	 * claim. No strategy, or an unknown one, has nothing to resolve: the pool
	 * stands.
	 *
	 * @param string             $strategy The trimmed routing strategy.
	 * @param array<int, string> $pool     The expanded candidate uids.
	 *
	 * @return string|null The chosen uid, or null to leave the task pooled.
	 */
	private function choose(string $strategy, array $pool): ?string {
		return match ($strategy) {
			'single-role' => $this->pickSoleMember(pool: $pool),
			// The pool is ordered (users first, in declared order): the first
			// tier answers, the next only when it is empty.
			'hierarchical' => ($pool[0] ?? null),
			'round-robin' => $this->pickLeastRecentlyAssigned(pool: $pool),
			'least-loaded' => $this->pickLeastLoaded(pool: $pool),
			default => null,
		};
	}//end choose()

	/**
	 * `single-role`: a role that resolves to exactly one person IS an assignment.
	 *
	 * @param array<int, string> $pool The candidate uids.
	 *
	 * @return string|null The sole member, or null for any other pool size.
	 */
	private function pickSoleMember(array $pool): ?string {
		if (count($pool) === 1) {
			return $pool[0];
		}

		return null;
	}//end pickSoleMember()

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
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-performer-model-spans-people-groups-agents-and-workers
	 */
	public function expandPool(Task $task): array {
		$pool = array_map('strval', array_values($task->getCandidateUsers() ?? []));

		// The role group comes last; a blank role expands to nobody.
		// A typed `{type, id}` entry is never a bare group name.
		$groupNames = array_filter(
			($task->getCandidateGroups() ?? []),
			static fn ($groupId): bool => is_array($groupId) === false
		);
		$groupIds   = array_map('strval', array_values($groupNames));
		$groupIds[] = trim((string)$task->getCandidateRole());
		foreach ($groupIds as $groupId) {
			array_push($pool, ...$this->groupMembers(groupId: $groupId));
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
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-performer-model-spans-people-groups-agents-and-workers
	 */
	private function groupMembers(string $groupId): array {
		if ($this->groupManager === null || $groupId === '') {
			return [];
		}

		try {
			return $this->uidsIn(group: $this->groupManager->get($groupId));
		} catch (Throwable) {
			return [];
		}
	}//end groupMembers()

	/**
	 * The member uids of a group, or nobody for a group that does not exist.
	 *
	 * @param IGroup|null $group The group the backend returned.
	 *
	 * @return array<int, string> The member uids.
	 */
	private function uidsIn(?IGroup $group): array {
		if ($group === null) {
			return [];
		}

		return array_values(array_map(static fn (IUser $user): string => $user->getUID(), $group->getUsers()));
	}//end uidsIn()

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
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-performer-model-spans-people-groups-agents-and-workers
	 */
	private function pickLeastRecentlyAssigned(array $pool): ?string {
		if ($pool === []) {
			return null;
		}

		$latest = $this->tasks->latestAssignedAt(uids: $pool);
		return $this->firstRanked(
			pool: $pool,
			compare: static fn (string $a, string $b): int => strcmp(($latest[$a] ?? ''), ($latest[$b] ?? ''))
		);
	}//end pickLeastRecentlyAssigned()

	/**
	 * `least-loaded`: the member with the fewest open tasks goes next.
	 *
	 * @param array<int, string> $pool The candidate uids.
	 *
	 * @return string|null The chosen uid, or null for an empty pool.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-performer-model-spans-people-groups-agents-and-workers
	 */
	private function pickLeastLoaded(array $pool): ?string {
		if ($pool === []) {
			return null;
		}

		$counts = $this->tasks->countOpenAssigned(uids: $pool);
		return $this->firstRanked(
			pool: $pool,
			compare: static fn (string $a, string $b): int => (($counts[$a] ?? 0) <=> ($counts[$b] ?? 0))
		);
	}//end pickLeastLoaded()

	/**
	 * The first uid of a non-empty pool sorted by a ranking, ties broken on uid.
	 *
	 * @param array<int, string> $pool    The candidate uids, not empty.
	 * @param callable           $compare Orders two uids by their ranking.
	 *
	 * @return string The first uid.
	 */
	private function firstRanked(array $pool, callable $compare): string {
		usort(
			$pool,
			static function (string $a, string $b) use ($compare): int {
				$order = $compare($a, $b);
				if ($order !== 0) {
					return $order;
				}

				return strcmp($a, $b);
			}
		);

		return $pool[0];
	}//end firstRanked()
}//end class
