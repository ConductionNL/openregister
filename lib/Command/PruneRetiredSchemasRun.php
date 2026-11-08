<?php

/**
 * OpenRegister prune-retired-schemas run
 *
 * The state one run of the prune-retired-schemas command shares between its
 * steps: the owning app, the operator's flags, the console and the tally.
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

use OCA\OpenRegister\Service\SchemaTeardownOverride;

/**
 * One run of the prune-retired-schemas command.
 *
 * @spec openspec/specs/schema-import/spec.md#requirement-a-schema-retired-from-a-descriptor-must-be-removable-from-the-instance
 */
final class PruneRetiredSchemasRun {

	/**
	 * Schemas removed.
	 *
	 * @var integer
	 */
	public int $pruned = 0;

	/**
	 * Schemas skipped by a guard or a failed teardown.
	 *
	 * @var integer
	 */
	public int $skipped = 0;

	/**
	 * Slugs the app owns no schema under.
	 *
	 * @var integer
	 */
	public int $missing = 0;

	/**
	 * Hold the operator's choices for this run.
	 *
	 * @param string                 $appId    The owning application id.
	 * @param bool                   $dryRun   Whether the run only reports.
	 * @param bool                   $force    Whether a schema that still owns objects may go.
	 * @param SchemaTeardownOverride $override What the operator lifted beyond --force.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-import/spec.md#requirement-a-schema-retired-from-a-descriptor-must-be-removable-from-the-instance
	 */
	public function __construct(
		public readonly string $appId,
		public readonly bool $dryRun,
		public readonly bool $force,
		public readonly SchemaTeardownOverride $override,
	) {
	}//end __construct()
}//end class
