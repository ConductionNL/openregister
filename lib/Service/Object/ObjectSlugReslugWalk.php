<?php

/**
 * OpenRegister ObjectSlugReslugWalk
 *
 * Walks the rows of a slug reslug until none can move, in work proportional to the moves (#4037).
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

use SplMinHeap;

/**
 * Same outcome as walking all rows, oldest first, in repeated passes until a
 * pass moves nothing, without the repeated scans.
 *
 * A row that did not move can only start to move once the holder of its wanted
 * slug (or of the slug it keeps) moves away, so it waits on those slugs and is
 * woken when one is vacated. A woken row that lies after the row that just
 * moved is tried in the same pass, an earlier one in the next pass, as the full
 * scan would. The cost is the number of moves plus the number of rows, times
 * log of the rows, instead of rows times passes: a chain of N rows that each
 * want the next one's slug took N passes over N rows.
 *
 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
 */
class ObjectSlugReslugWalk {

	/**
	 * Row indexes of the pass in progress, lowest first.
	 *
	 * @var SplMinHeap<int>
	 */
	private SplMinHeap $pass;

	/**
	 * Rows queued for the pass in progress, by index.
	 *
	 * @var array<int, true>
	 */
	private array $queued = [];

	/**
	 * Rows to try in the next pass, by index.
	 *
	 * @var array<int, true>
	 */
	private array $next = [];

	/**
	 * Slug => rows waiting for it to be vacated.
	 *
	 * @var array<string, list<int>>
	 */
	private array $waiting = [];

	/**
	 * Walk the rows.
	 *
	 * @param list<array{candidate: string|null}> $rows The rows, oldest first.
	 * @param callable                            $step fn(array $row): array{moved: bool, own: ?string}, tries to move
	 *                                                  one row and says whether it moved and which slug it held before.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
	 */
	public function run(array $rows, callable $step): void {
		$this->pass = new SplMinHeap();
		$this->queued = [];
		$this->next = [];
		$this->waiting = [];
		foreach (array_keys($rows) as $index) {
			$this->queue(index: $index);
		}

		while ($this->pass->isEmpty() === false || $this->next !== []) {
			if ($this->pass->isEmpty() === true) {
				$this->startNextPass();
			}

			$index = $this->pass->extract();
			unset($this->queued[$index]);
			$outcome = $step($rows[$index]);
			if ($outcome['moved'] === false) {
				$this->waitOn(slugs: [$rows[$index]['candidate'], $outcome['own']], index: $index);
				continue;
			}

			// The row left its slug: wake the rows that wait on it. It may sit on a
			// suffixed variant, and then waits for its own candidate to be vacated.
			$this->waitOn(slugs: [$rows[$index]['candidate']], index: $index);
			$this->wake(vacated: $outcome['own'], current: $index);
		}
	}//end run()

	/**
	 * Order the resulting plan so a row that vacates a slug is written before the row that takes it.
	 *
	 * @param list<array{uuid: string, old: ?string, new: ?string}> $plan The steps in row order.
	 *
	 * @return list<array{uuid: string, old: ?string, new: ?string}> The same steps, holders before takers.
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
	 */
	public function holdersFirst(array $plan): array {
		return (new ObjectSlugPlanOrder())->order(plan: $plan);
	}//end holdersFirst()

	/**
	 * Put a row in the pass in progress.
	 *
	 * @param int $index The row.
	 *
	 * @return void
	 */
	private function queue(int $index): void {
		if (isset($this->queued[$index]) === false) {
			$this->pass->insert($index);
			$this->queued[$index] = true;
		}
	}//end queue()

	/**
	 * Start the next pass with the rows woken during the last one.
	 *
	 * @return void
	 */
	private function startNextPass(): void {
		foreach (array_keys($this->next) as $index) {
			$this->queue(index: $index);
		}

		$this->next = [];
	}//end startNextPass()

	/**
	 * Wake the rows waiting for a vacated slug: later rows this pass, earlier rows the next.
	 *
	 * @param string|null $vacated The slug the moved row left.
	 * @param int         $current The moved row.
	 *
	 * @return void
	 */
	private function wake(?string $vacated, int $current): void {
		if ($vacated === null) {
			return;
		}

		foreach ($this->waiting[$vacated] ?? [] as $woken) {
			if (isset($this->queued[$woken]) === true) {
				continue;
			}

			if ($woken > $current) {
				$this->queue(index: $woken);
				continue;
			}

			$this->next[$woken] = true;
		}

		unset($this->waiting[$vacated]);
	}//end wake()

	/**
	 * Register a row as waiting for the holder of each given slug to move away.
	 *
	 * @param list<string|null> $slugs The slugs; null entries are ignored.
	 * @param int               $index The row.
	 *
	 * @return void
	 */
	private function waitOn(array $slugs, int $index): void {
		foreach ($slugs as $slug) {
			if ($slug !== null) {
				$this->waiting[$slug][] = $index;
			}
		}
	}//end waitOn()
}//end class
