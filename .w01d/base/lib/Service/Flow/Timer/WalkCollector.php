<?php

/**
 * The engine's working, recorded as it walks (row Q8.18).
 *
 * The register's note: "Five working-day implementations in `lib/Service/` that
 * disagree with each other, and no surface that evaluates any of them." An
 * administrator cannot answer a citizen who asks why a term landed where it
 * did, because nothing prints the walk.
 *
 * 🔑 THE SAME CODE PATH, NARRATED (D-1). This is passed INTO
 * {@see SlaCalculator::add()}, which is the method the arm path calls. A
 * separate "explain" implementation would drift from the real one — and a
 * diagnostic that disagrees with the engine is worse than no diagnostic,
 * because it is believed. The arm path passes no collector and pays one null
 * check per day.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Timer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use DateTimeInterface;

/**
 * Collects one row per day the walk examined.
 *
 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md
 */
class WalkCollector {

	/**
	 * A day that counted against the budget.
	 *
	 * @var string
	 */
	public const WORKING = 'working';

	/**
	 * A day the working week does not include.
	 *
	 * @var string
	 */
	public const WEEKEND = 'weekend';

	/**
	 * How many rows are kept.
	 *
	 * A bound rather than a belief: a 10,000-business-day term walks about
	 * fourteen thousand days, and a diagnostic that returns fourteen thousand
	 * rows is a diagnostic nobody reads and a response nobody can render. The
	 * walk itself is NOT stopped — the fire moment stays correct — only the
	 * narration is truncated, and {@see self::isTruncated()} says so rather
	 * than letting a short list read as a short walk.
	 *
	 * @var int
	 */
	public const MAX_ROWS = 400;

	/**
	 * The rows, in the order the walk examined them.
	 *
	 * @var array<int, array{date: string, kind: string, counted: bool}>
	 */
	private array $rows = [];

	/**
	 * How many days the walk examined in total.
	 *
	 * @var int
	 */
	private int $examined = 0;

	/**
	 * Record one examined day.
	 *
	 * @param DateTimeInterface $day     Any instant on the day.
	 * @param bool              $counted Whether it counted against the budget.
	 * @param string|null       $rule    The rule that made it non-working, when one did.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md
	 */
	public function examine(DateTimeInterface $day, bool $counted, ?string $rule = null): void {
		$this->examined++;

		if (count($this->rows) >= self::MAX_ROWS) {
			return;
		}

		$kind = self::WORKING;
		if ($counted === false) {
			$kind = ($rule ?? self::WEEKEND);
		}

		$this->rows[] = [
			'date' => $day->format('Y-m-d'),
			'kind' => $kind,
			'counted' => $counted,
		];
	}//end examine()

	/**
	 * The walk, as ordered rows (D-3).
	 *
	 * @return array<int, array{date: string, kind: string, counted: bool}> The walk.
	 *
	 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md
	 */
	public function walk(): array {
		return $this->rows;
	}//end walk()

	/**
	 * Only the days that were skipped, with the rule that skipped them.
	 *
	 * @return array<int, array{date: string, kind: string, counted: bool}> The skipped days.
	 *
	 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md
	 */
	public function skipped(): array {
		return array_values(
			array_filter($this->rows, static fn (array $row): bool => ($row['counted'] === false))
		);
	}//end skipped()

	/**
	 * How many days the walk examined, truncation included.
	 *
	 * @return int The count.
	 *
	 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md
	 */
	public function examinedCount(): int {
		return $this->examined;
	}//end examinedCount()

	/**
	 * Whether the narration was cut short.
	 *
	 * @return bool True when rows were dropped.
	 *
	 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md
	 */
	public function isTruncated(): bool {
		return ($this->examined > count($this->rows));
	}//end isTruncated()
}//end class
