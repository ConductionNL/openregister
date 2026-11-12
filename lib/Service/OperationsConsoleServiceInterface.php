<?php

/**
 * OperationsConsoleServiceInterface: what callers outside the Service component ask of OperationsConsoleService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * The read model behind the operations console: health panes, background jobs and rule runs.
 */
interface OperationsConsoleServiceInterface {

	/**
	 * The window the console reports over, in hours.
	 *
	 * A day, because that is the span in which "it started failing this
	 * morning" is still answerable and a nightly job has run exactly once.
	 *
	 * @var int
	 */
	public const DEFAULT_WINDOW_HOURS = 24;

	/**
	 * The console's panes: what each one counts, and what wants attention.
	 *
	 * `attention` is the number a reader acts on, and it is computed here
	 * rather than in the browser so every consumer agrees on what counts as
	 * wrong. A pane whose `attention` is zero is not the same as one that
	 * counted nothing, which is why `total` is carried beside it.
	 *
	 * @param int $windowHours How far back to look, bounded by MAX_WINDOW_HOURS.
	 *
	 * @return array<string, mixed> The window and the three panes.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	public function panes(int $windowHours = self::DEFAULT_WINDOW_HOURS): array;

	/**
	 * The job pane's rows: the bulk jobs, and the inventory they sit in.
	 *
	 * @param string|null $state Narrow the bulk jobs to one state.
	 * @param int         $limit How many bulk jobs to return.
	 *
	 * @return array<string, mixed> The rows.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	public function jobs(?string $state = null, int $limit = 50): array;

	/**
	 * The most recent rule-engine runs, across every rule.
	 *
	 * @param int $windowHours How far back to look.
	 * @param int $limit       How many runs to return.
	 *
	 * @return array<string, mixed> The runs and the rules holding an error.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	public function ruleRuns(int $windowHours = self::DEFAULT_WINDOW_HOURS, int $limit = 50): array;
}//end interface
