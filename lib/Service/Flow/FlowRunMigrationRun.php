<?php

/**
 * FlowRunMigrationRun
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;

/**
 * One run being moved by {@see FlowRunMigrationService}: the run as read, the
 * migration asked for, and the version the run was on before it moved.
 *
 * A run object (ADR-011 Rule 3): built once the run is read, shared by the
 * verdict, the write and the answer, and never handed outside the service.
 *
 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
 */
final class FlowRunMigrationRun {

	/**
	 * The run's version before the migration, captured before any write.
	 *
	 * @var integer|null
	 */
	public readonly ?int $from;

	/**
	 * Name the run being moved.
	 *
	 * @param FlowRun          $run       The run, as read.
	 * @param FlowRunMigration $migration The migration asked for.
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	public function __construct(
		public readonly FlowRun $run,
		public readonly FlowRunMigration $migration,
	) {
		$this->from = $run->getFlowVersion();
	}//end __construct()
}//end class
