<?php

/**
 * PurgeRun — the state of one purge-object run.
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

use OCA\OpenRegister\Command\Support\PurgeSwitches;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The operator's switches and where the purge reports.
 *
 * Built by PurgeObjectCommand::execute() and never handed outside that command
 * (ADR-011 Rule 3).
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
 */
final class PurgeRun {

	/**
	 * Start a run.
	 *
	 * @param PurgeSwitches   $switches The operator's --force and --apply switches.
	 * @param OutputInterface $output   Console output.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly PurgeSwitches $switches,
		public readonly OutputInterface $output,
	) {
	}//end __construct()
}//end class
