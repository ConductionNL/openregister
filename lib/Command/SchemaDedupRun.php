<?php

/**
 * SchemaDedupRun — one run of the dedup-collided-schemas command.
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
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Whether a dedup run writes or only reports, and where it reports to;
 * built once by the command's execute() and carried through its split steps.
 */
final class SchemaDedupRun {

	/**
	 * Console output stream
	 *
	 * @var OutputInterface
	 */
	public readonly OutputInterface $output;

	/**
	 * Whether to write (--apply) instead of only reporting
	 *
	 * @var bool
	 */
	public readonly bool $apply;

	/**
	 * Constructor
	 *
	 * @param OutputInterface $output Console output stream
	 * @param bool            $apply  Whether to write
	 */
	public function __construct(OutputInterface $output, bool $apply) {
		$this->output = $output;
		$this->apply = $apply;
	}//end __construct()

	/**
	 * The closing summary line.
	 *
	 * @param array{split: int, failed: int} $tally Pairs split (or that would be) and failed splits.
	 *
	 * @return string The line to print.
	 */
	public function summaryLine(array $tally): string {
		$verb = 'Would split';
		if ($this->apply === true) {
			$verb = 'Split';
		}

		return sprintf("\n%s %d register/schema pair(s); %d failure(s).", $verb, $tally['split'], $tally['failed']);
	}//end summaryLine()
}//end class
