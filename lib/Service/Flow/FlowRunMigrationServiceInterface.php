<?php

/**
 * FlowRunMigrationServiceInterface: what callers outside the Service component ask of FlowRunMigrationService.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Moving flow runs onto another version, as the run-migration controller uses
 * it. Only the methods that caller calls.
 */
interface FlowRunMigrationServiceInterface {

	/**
	 * Move one run onto another version.
	 *
	 * @param string                 $runUuid The run.
	 * @param FlowRunMigrationTarget $target  The version to move onto, why, who asked and the node mapping.
	 *
	 * @return array<string, mixed> The migration answer.
	 */
	public function migrate(string $runUuid, FlowRunMigrationTarget $target): array;

	/**
	 * Move every run of one version onto another, bounded.
	 *
	 * @param VersionRunsMigration $request The flow, the version to move off, the target and the batch bound.
	 *
	 * @return array{migrated: int, skipped: int, results: array<int, array<string, mixed>>}
	 */
	public function migrateRunsOfVersion(VersionRunsMigration $request): array;

	/**
	 * Answer what migrating one run would do, without moving it.
	 *
	 * @param string                 $runUuid The run.
	 * @param FlowRunMigrationTarget $target  The version to move onto, why (for the answer's own record), who asked and the mapping.
	 *
	 * @return array<string, mixed> The dry-run answer.
	 */
	public function preview(string $runUuid, FlowRunMigrationTarget $target): array;
}//end interface
