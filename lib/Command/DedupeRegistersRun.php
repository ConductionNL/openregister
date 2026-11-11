<?php

/**
 * OpenRegister dedupe-registers run
 *
 * The state one run of the registers:dedupe command shares between its steps:
 * the operator's flags and the tally.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/occ-commands/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

/**
 * One run of the registers:dedupe command.
 *
 * @spec openspec/specs/occ-commands/spec.md
 */
final class DedupeRegistersRun {

	/**
	 * Slug groups holding more than one register.
	 *
	 * @var integer
	 */
	public int $duplicateGroups = 0;

	/**
	 * Duplicates deleted.
	 *
	 * @var integer
	 */
	public int $deleted = 0;

	/**
	 * Duplicates kept because they own objects or the delete was refused.
	 *
	 * @var integer
	 */
	public int $keptNonEmpty = 0;

	/**
	 * Hold the operator's choices for this run.
	 *
	 * @param bool $dryRun Whether the run only reports.
	 * @param bool $force  Whether a duplicate that owns objects may go.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/occ-commands/spec.md
	 */
	public function __construct(
		public readonly bool $dryRun,
		public readonly bool $force,
	) {
	}//end __construct()
}//end class
