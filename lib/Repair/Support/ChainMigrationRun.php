<?php

/**
 * The legacy approval chain one migration run converts
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

/**
 * One legacy approval chain and the task template derived from it.
 *
 * ADR-011 Rule 3: the run object of MigrateApprovalChainsToTasks' step-set
 * helpers. It never leaves that class.
 *
 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-every-in-flight-approval-survives-the-migration-at-the-same-position
 */
final class ChainMigrationRun {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $chain    The legacy chain row.
	 * @param array<string, mixed> $template The template derived from the chain.
	 *
	 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-every-in-flight-approval-survives-the-migration-at-the-same-position
	 */
	public function __construct(
		public readonly array $chain,
		public readonly array $template,
	) {
	}//end __construct()
}//end class
