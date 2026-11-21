<?php

/**
 * OpenRegister BoundedRun
 *
 * The shared row and time budget of one bounded GraphQL run. It lives in the
 * resolver context, so every list resolution of the same run reads and spends
 * the same budget.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service\GraphQL
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\GraphQL;

use GraphQL\Error\Error;

/**
 * One run's remaining rows and its deadline, mutated as each list spends them.
 *
 * It is an object, not an array, because the GraphQL executor hands every
 * resolver the same context value: a mutable object shares the running total
 * across the lists of one document, where an array would be copied per
 * resolver and each list would see the full cap again.
 *
 * The deadline is honest about what it stops: it is checked between list
 * resolutions, so it ends further work after a slow statement, not a single
 * statement already running. The complexity cap is what keeps one statement
 * small.
 *
 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md
 */
final class BoundedRun {

	/**
	 * The context key this run is carried under.
	 *
	 * @var string
	 */
	public const CONTEXT_KEY = 'boundedRun';

	/**
	 * The error code a passed deadline carries.
	 *
	 * @var string
	 */
	public const TIMEOUT_CODE = 'QUERY_TIMEOUT';

	/**
	 * The rows still allowed across the rest of the run.
	 *
	 * @var int
	 */
	private int $remaining;

	/**
	 * Whether a cap has cut a list short.
	 *
	 * @var bool
	 */
	private bool $truncated = false;

	/**
	 * Hold the caps.
	 *
	 * @param int   $rowCap   The rows allowed across the whole run.
	 * @param float $deadline The unix moment (microtime) the run must stop after.
	 */
	public function __construct(
		private readonly int $rowCap,
		private readonly float $deadline,
	) {
		$this->remaining = max(0, $rowCap);
	}//end __construct()

	/**
	 * The lower of a list's requested page size and what the run has left,
	 * marking the run truncated when it cuts the page down.
	 *
	 * @param int $requested The `first` the list asked for.
	 *
	 * @return int The page size the list may use.
	 *
	 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md
	 */
	public function clampFirst(int $requested): int {
		$allowed = min(max(0, $requested), $this->remaining);
		if ($allowed < $requested) {
			$this->truncated = true;
		}

		return $allowed;
	}//end clampFirst()

	/**
	 * Spend the rows a list returned.
	 *
	 * @param int $rows The rows the list returned.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md
	 */
	public function consume(int $rows): void {
		$this->remaining = max(0, ($this->remaining - max(0, $rows)));
	}//end consume()

	/**
	 * Refuse the rest of the run once the deadline has passed.
	 *
	 * @param callable():float|null $clock A clock for testing; the wall clock by default.
	 *
	 * @return void
	 *
	 * @throws Error When the deadline has passed.
	 *
	 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md
	 */
	public function assertWithinDeadline(?callable $clock = null): void {
		$now = microtime(true);
		if ($clock !== null) {
			$now = $clock();
		}

		if ($now > $this->deadline) {
			throw new Error(
				'The query exceeded its time limit before this part could run.',
				null,
				null,
				[],
				null,
				null,
				['code' => self::TIMEOUT_CODE]
			);
		}
	}//end assertWithinDeadline()

	/**
	 * The rows returned across the run so far.
	 *
	 * @return int The rows returned.
	 *
	 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md
	 */
	public function rowsReturned(): int {
		return ($this->rowCap - $this->remaining);
	}//end rowsReturned()

	/**
	 * Whether a cap cut a list of the run short.
	 *
	 * @return bool True when truncated.
	 *
	 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md
	 */
	public function truncated(): bool {
		return $this->truncated;
	}//end truncated()
}//end class
