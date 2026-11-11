<?php

/**
 * OpenRegister Search Index Pass
 *
 * How one rebuild or restore walks its work: whether it writes, and whom it
 * tells about each index on the way.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Search
 * @package  OCA\OpenRegister\Search\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Search;

use Closure;

/**
 * The dry-run switch and progress callback of one rebuild or restore.
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */
final class SearchIndexPass {

	/**
	 * Called with (table, index, position, total) per index, or null.
	 *
	 * @var Closure|null
	 */
	private readonly ?Closure $progress;

	/**
	 * Constructor.
	 *
	 * @param bool          $apply    False reports what it would change and touches nothing.
	 * @param callable|null $progress Called with (table, index, position, total) per index.
	 *
	 * @phpstan-param callable(string, string, int, int): void|null $progress
	 *
	 * @psalm-param callable(string, string, int, int): void|null $progress
	 */
	public function __construct(
		public readonly bool $apply,
		?callable $progress,
	) {
		$this->progress = self::closureOf(progress: $progress);
	}//end __construct()

	/**
	 * The progress callback as a closure.
	 *
	 * @param callable|null $progress The callback, or null.
	 *
	 * @return Closure|null The closure, or null.
	 */
	private static function closureOf(?callable $progress): ?Closure {
		if ($progress === null) {
			return null;
		}

		return $progress(...);
	}//end closureOf()

	/**
	 * Tell the progress callback, when there is one, which index is next.
	 *
	 * @param array{0: string, 1: string, 2: int, 3: int} $step Table, index, position and total.
	 *
	 * @return void
	 */
	public function announce(array $step): void {
		if ($this->progress !== null) {
			($this->progress)(...$step);
		}
	}//end announce()

	/**
	 * How many indexes this pass changed.
	 *
	 * @param int $succeeded The planned indexes that did not fail.
	 *
	 * @return int The count, or 0 for a dry run, which changes nothing.
	 */
	public function changed(int $succeeded): int {
		if ($this->apply === false) {
			return 0;
		}

		return $succeeded;
	}//end changed()

	/**
	 * The state a pass ends in.
	 *
	 * @param array<int, array<string, string>> $failures The indexes that failed.
	 *
	 * @return string `failed`, `planned` for a dry run, or `completed`.
	 */
	public function state(array $failures): string {
		if ($failures !== []) {
			return 'failed';
		}

		if ($this->apply === false) {
			return 'planned';
		}

		return 'completed';
	}//end state()
}//end class
