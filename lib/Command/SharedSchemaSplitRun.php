<?php

/**
 * One --write pass of the dedupe-shared-schemas command.
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
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * The console and strictness of a split pass, and its counts.
 *
 * A run object (ADR-011 Rule 3): it never leaves DedupeSharedSchemasCommand.
 *
 * @spec openspec/changes/archive/2026-10-30-dedupe-shared-schemas/proposal.md
 */
final class SharedSchemaSplitRun {

	/**
	 * Splits applied.
	 *
	 * @var integer
	 */
	public int $split = 0;

	/**
	 * Splits that failed.
	 *
	 * @var integer
	 */
	public int $failed = 0;

	/**
	 * Constructor.
	 *
	 * @param OutputInterface $output The console output.
	 * @param bool            $strict Whether unmapped columns refuse the move.
	 */
	public function __construct(
		public readonly OutputInterface $output,
		public readonly bool $strict,
	) {
	}//end __construct()
}//end class
