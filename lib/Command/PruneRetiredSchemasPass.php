<?php

/**
 * OpenRegister prune-retired-schemas pass
 *
 * One run of the prune-retired-schemas command as its steps see it.
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
 * @spec openspec/specs/schema-import/spec.md#requirement-a-schema-retired-from-a-descriptor-must-be-removable-from-the-instance
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * The per-call state every step of one prune-retired-schemas run receives:
 * the run's flags and tally, and the console it reports to. Built once by
 * the command's execute().
 *
 * @spec openspec/specs/schema-import/spec.md#requirement-a-schema-retired-from-a-descriptor-must-be-removable-from-the-instance
 */
final class PruneRetiredSchemasPass {
	/**
	 * Bind the run to its console.
	 *
	 * @param PruneRetiredSchemasRun $run    The run's flags and tally.
	 * @param OutputInterface        $output Console output stream.
	 *
	 * @spec openspec/specs/schema-import/spec.md#requirement-a-schema-retired-from-a-descriptor-must-be-removable-from-the-instance
	 */
	public function __construct(
		public readonly PruneRetiredSchemasRun $run,
		public readonly OutputInterface $output,
	) {
	}//end __construct()
}//end class
