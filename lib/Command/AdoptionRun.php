<?php

/**
 * AdoptionRun — the state of one leaf-organisation adoption.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Where the adoption reports, whether it writes, what it has seen and its tally.
 *
 * Built by AdoptLeafOrganisationsCommand::execute() and never handed outside
 * that command (ADR-011 Rule 3, after CascadeRun).
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @spec openspec/changes/consolidate-organisation-on-or/specs/consolidated-organisation/spec.md#requirement-a-leaf-apps-organisations-are-adopted-not-re-created-req-org-106
 */
final class AdoptionRun {

	/**
	 * Organisations on the instance keyed by uuid; grows as rows are adopted,
	 * so a legal identifier appearing twice within one run merges the second
	 * occurrence too.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $existing = [];

	/**
	 * The run's tally.
	 *
	 * @var array{adopted:int, merged:int, skipped:int, failed:int}
	 */
	public array $tally = ['adopted' => 0, 'merged' => 0, 'skipped' => 0, 'failed' => 0];

	/**
	 * Start a run.
	 *
	 * @param OutputInterface $output Console output stream.
	 * @param bool            $dryRun Whether to report rather than write.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly OutputInterface $output,
		public readonly bool $dryRun,
	) {
	}//end __construct()
}//end class
