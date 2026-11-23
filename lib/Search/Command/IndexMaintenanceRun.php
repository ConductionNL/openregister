<?php

/**
 * OpenRegister IndexMaintenanceRun
 *
 * One invocation of `openregister:tables:search-index`: the console input it
 * reads its scope options from and the output it reports to.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The state one search-index maintenance run shares between its actions.
 *
 * Built by SearchIndexCommand::execute() from the console input and passed to
 * the rebuild, snapshot and restore helpers (ADR-011 Rule 3). It never leaves
 * SearchIndexCommand.
 *
 * @spec openspec/specs/search-index/spec.md
 */
final class IndexMaintenanceRun {
	/**
	 * Constructor.
	 *
	 * @param InputInterface  $input  The console input.
	 * @param OutputInterface $output The console output.
	 */
	public function __construct(
		public readonly InputInterface $input,
		public readonly OutputInterface $output,
	) {
	}//end __construct()

	/**
	 * Whether to actually change anything (--apply).
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/search-index/spec.md
	 */
	public function apply(): bool {
		return (bool)$this->input->getOption('apply');
	}//end apply()

	/**
	 * The register to limit the run to (--register), null for all.
	 *
	 * @return int|null
	 *
	 * @spec openspec/specs/search-index/spec.md
	 */
	public function registerId(): ?int {
		$registerOption = $this->input->getOption('register');
		if ($registerOption === null) {
			return null;
		}

		return (int)$registerOption;
	}//end registerId()

	/**
	 * The snapshot file to write or read (--file), null when absent.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/search-index/spec.md
	 */
	public function file(): ?string {
		$path = $this->input->getOption('file');
		if ($path === null) {
			return null;
		}

		return (string)$path;
	}//end file()
}//end class
