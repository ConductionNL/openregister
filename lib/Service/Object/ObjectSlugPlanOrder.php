<?php

/**
 * OpenRegister ObjectSlugPlanOrder
 *
 * Orders a slug backfill plan so a row that vacates a slug is written before the row that takes it (#4037).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * Holders before takers.
 *
 * The slug has no unique index, so writing the taker first would leave two
 * objects on one slug until the holder moves, and for good when the holder's
 * write is skipped or fails. Steps that depend on nothing keep their order
 * (oldest first); the sort is linear in the plan.
 *
 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
 */
class ObjectSlugPlanOrder {

	/**
	 * Order a plan, holders before takers.
	 *
	 * @param list<array{uuid: string, old: ?string, new: ?string}> $plan The steps in row order.
	 *
	 * @return array{ordered: list<array{uuid: string, old: ?string, new: ?string}>, blocked: list<array{uuid: string, old: ?string, new: ?string}>}
	 *     The steps that can be written, holders before takers, and the steps that cannot: those in a cycle
	 *     (A takes B's slug while B takes A's, which the suffix can produce) and those waiting on one.
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
	 */
	public function order(array $plan): array {
		$waiting = $this->waitingCounts(plan: $plan);
		$dependents = $this->dependents(plan: $plan);

		$queue = [];
		foreach (array_keys($plan) as $index) {
			if (isset($waiting[$index]) === false) {
				$queue[] = $index;
			}
		}

		$ordered = [];
		$done = [];
		$head = 0;
		while (isset($queue[$head]) === true) {
			$index = $queue[$head];
			$head++;
			$ordered[] = $plan[$index];
			$done[$index] = true;
			foreach ($dependents[$index] ?? [] as $dependent) {
				$waiting[$dependent]--;
				if ($waiting[$dependent] === 0) {
					$queue[] = $dependent;
				}
			}
		}

		// A cycle has no first write: whichever member went first would put two objects on
		// one slug until the other follows, and for good if that write is skipped or fails.
		// Its members, and every step waiting on one, are handed back unwritten.
		$blocked = [];
		foreach ($plan as $index => $step) {
			if (isset($done[$index]) === false) {
				$blocked[] = $step;
			}
		}

		return ['ordered' => $ordered, 'blocked' => $blocked];
	}//end order()

	/**
	 * Step index => number of other steps that hold the slug it wants to take.
	 *
	 * @param list<array{uuid: string, old: ?string, new: ?string}> $plan The steps.
	 *
	 * @return array<int, int> The counts, only for steps that wait.
	 */
	private function waitingCounts(array $plan): array {
		$holdersOf = $this->holdersOf(plan: $plan);
		$waiting = [];
		foreach ($plan as $index => $step) {
			foreach ($holdersOf[(string)$step['new']] ?? [] as $holder) {
				if ($holder !== $index) {
					$waiting[$index] = (($waiting[$index] ?? 0) + 1);
				}
			}
		}

		return $waiting;
	}//end waitingCounts()

	/**
	 * Step index => the steps that wait for it to vacate its slug.
	 *
	 * @param list<array{uuid: string, old: ?string, new: ?string}> $plan The steps.
	 *
	 * @return array<int, list<int>> The dependents.
	 */
	private function dependents(array $plan): array {
		$holdersOf = $this->holdersOf(plan: $plan);
		$dependents = [];
		foreach ($plan as $index => $step) {
			foreach ($holdersOf[(string)$step['new']] ?? [] as $holder) {
				if ($holder !== $index) {
					$dependents[$holder][] = $index;
				}
			}
		}

		return $dependents;
	}//end dependents()

	/**
	 * Slug => the steps that move away from it.
	 *
	 * @param list<array{uuid: string, old: ?string, new: ?string}> $plan The steps.
	 *
	 * @return array<string, list<int>> The holders.
	 */
	private function holdersOf(array $plan): array {
		$holdersOf = [];
		foreach ($plan as $index => $step) {
			if ($step['old'] !== null) {
				$holdersOf[$step['old']][] = $index;
			}
		}

		return $holdersOf;
	}//end holdersOf()
}//end class
