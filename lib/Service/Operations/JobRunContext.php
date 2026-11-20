<?php

/**
 * JobRunContext: why a recorded run happened and what it concerned.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Operations
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Operations;

/**
 * The cause, the actor, the argument and the details a run row carries.
 *
 * A run on its schedule carries the defaults; a run started by a person names
 * the manual cause and the actor.
 *
 * @spec openspec/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
 */
final readonly class JobRunContext {

	/**
	 * The run came from the cron schedule.
	 *
	 * @var string
	 */
	public const CAUSE_SCHEDULE = 'schedule';

	/**
	 * The run came from an administrator, and `actor` names them.
	 *
	 * @var string
	 */
	public const CAUSE_MANUAL = 'manual';

	/**
	 * Describe the run.
	 *
	 * @param string                    $cause    One of the CAUSE_ constants.
	 * @param string|null               $actor    The uid that caused the run, when a person did.
	 * @param mixed                     $argument The job argument, digested into the row.
	 * @param array<string, mixed>|null $details  What the act concerned.
	 */
	public function __construct(
		public string $cause = self::CAUSE_SCHEDULE,
		public ?string $actor = null,
		public mixed $argument = null,
		public ?array $details = null,
	) {
	}//end __construct()
}//end class
