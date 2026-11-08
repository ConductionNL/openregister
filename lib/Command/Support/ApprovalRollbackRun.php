<?php

/**
 * OpenRegister ApprovalRollbackRun
 *
 * One pass of the approval-rollback command: its mode, its output, and what
 * it has carried back and left behind so far.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-every-in-flight-approval-survives-the-migration-at-the-same-position
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command\Support;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * One pass of the approval-rollback command.
 *
 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-every-in-flight-approval-survives-the-migration-at-the-same-position
 */
final class ApprovalRollbackRun {

	/**
	 * The number of decisions carried back so far.
	 *
	 * @var integer
	 */
	private int $written = 0;

	/**
	 * The facts the legacy schema cannot hold, one line each.
	 *
	 * @var array<int, string>
	 */
	private array $notCarriable = [];

	/**
	 * Start a pass.
	 *
	 * @param boolean         $dryRun Whether to report without writing.
	 * @param OutputInterface $output Command output.
	 *
	 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-every-in-flight-approval-survives-the-migration-at-the-same-position
	 */
	public function __construct(
		public readonly bool $dryRun,
		public readonly OutputInterface $output,
	) {
	}//end __construct()

	/**
	 * Record one decision carried back, with the facts it left behind.
	 *
	 * @param string             $step      The step label, e.g. "step 2 (task task-2)".
	 * @param array<int, string> $lostFacts The facts the legacy schema cannot hold.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-every-in-flight-approval-survives-the-migration-at-the-same-position
	 */
	public function recordCarried(string $step, array $lostFacts): void {
		foreach ($lostFacts as $lost) {
			$this->notCarriable[] = $step . ': ' . $lost;
		}

		$this->written++;
	}//end recordCarried()

	/**
	 * The number of decisions carried back.
	 *
	 * @return integer
	 *
	 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-every-in-flight-approval-survives-the-migration-at-the-same-position
	 */
	public function written(): int {
		return $this->written;
	}//end written()

	/**
	 * The facts left behind, one line each.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-every-in-flight-approval-survives-the-migration-at-the-same-position
	 */
	public function notCarriable(): array {
		return $this->notCarriable;
	}//end notCarriable()
}//end class
