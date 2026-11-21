<?php

/**
 * OpenRegister migrate-storage run
 *
 * The console input and output one run of the migrate-storage command shares
 * between its steps.
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
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One run of the migrate-storage command.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class MigrateStorageRun {

	/**
	 * Hold the run's console.
	 *
	 * @param InputInterface  $input  The operator's arguments and options.
	 * @param OutputInterface $output The console output stream.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly InputInterface $input,
		public readonly OutputInterface $output,
	) {
	}//end __construct()
}//end class
