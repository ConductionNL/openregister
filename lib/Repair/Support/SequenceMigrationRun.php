<?php

/**
 * The task sequence one legacy step set migrates onto
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair\Support;

use OCA\OpenRegister\Db\TaskSequence;

/**
 * The sequence a legacy (chain, object) step set migrates onto, and the chain's name.
 *
 * ADR-011 Rule 3: the run object of MigrateApprovalChainsToTasks' per-step
 * helpers. It never leaves that class.
 *
 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-every-in-flight-approval-survives-the-migration-at-the-same-position
 */
final class SequenceMigrationRun {
	/**
	 * Constructor.
	 *
	 * @param TaskSequence $sequence  The sequence the step set's tasks belong to.
	 * @param string       $chainName The legacy chain's name, for the task titles.
	 *
	 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-every-in-flight-approval-survives-the-migration-at-the-same-position
	 */
	public function __construct(
		public readonly TaskSequence $sequence,
		public readonly string $chainName,
	) {
	}//end __construct()
}//end class
