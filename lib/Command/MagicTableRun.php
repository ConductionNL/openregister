<?php

/**
 * What a run over the magic tables has done so far.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The console of one run, and its counts.
 *
 * @spec openspec/specs/auth-system/spec.md
 */
final class MagicTableRun {

	/**
	 * The selected registers.
	 *
	 * @var Register[]
	 */
	public array $registers = [];

	/**
	 * The selected schemas.
	 *
	 * @var Schema[]
	 */
	public array $schemas = [];

	/**
	 * Tables worked on.
	 *
	 * @var integer
	 */
	public int $tables = 0;

	/**
	 * Rows looked at.
	 *
	 * @var integer
	 */
	public int $scanned = 0;

	/**
	 * Rows changed, or that a dry run would change.
	 *
	 * @var integer
	 */
	public int $changed = 0;

	/**
	 * Rows that failed.
	 *
	 * @var integer
	 */
	public int $failed = 0;

	/**
	 * Constructor.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output stream.
	 */
	public function __construct(
		public readonly InputInterface $input,
		public readonly OutputInterface $output,
	) {
	}//end __construct()

	/**
	 * Whether this run only counts.
	 *
	 * @return bool True for a dry run
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function isDryRun(): bool {
		return (bool)$this->input->getOption('dry-run');
	}//end isDryRun()

	/**
	 * The words that mark a line of a dry run.
	 *
	 * @param string $words The words
	 *
	 * @return string The words in a dry run, or else nothing
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function dryRunNote(string $words): string {
		if ($this->isDryRun() === true) {
			return $words;
		}

		return '';
	}//end dryRunNote()
}//end class
