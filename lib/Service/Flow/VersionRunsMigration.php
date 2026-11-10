<?php

/**
 * VersionRunsMigration
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

/**
 * A request to move every run pinned to one version of a flow onto another.
 *
 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-runs-can-be-migrated-in-bulk-per-version
 */
final class VersionRunsMigration {

	/**
	 * Name the request.
	 *
	 * @param string                 $flowId        The flow.
	 * @param int                    $sourceVersion The version to move off.
	 * @param FlowRunMigrationTarget $target        The version to move onto, why, who asked and one mapping for all of them.
	 * @param int                    $limit         The batch bound.
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-runs-can-be-migrated-in-bulk-per-version
	 */
	public function __construct(
		public readonly string $flowId,
		public readonly int $sourceVersion,
		public readonly FlowRunMigrationTarget $target,
		public readonly int $limit = 100,
	) {
	}//end __construct()
}//end class
