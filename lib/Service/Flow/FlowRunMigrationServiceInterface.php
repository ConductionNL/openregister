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
	 * @param string                $runUuid       The run.
	 * @param integer               $targetVersion The version to move onto.
	 * @param string                $reason        Why, recorded on the run.
	 * @param string                $actor         Who asked.
	 * @param array<string, string> $mapping       Old node id to new node id.
	 *
	 * @return array<string, mixed> The migration answer.
	 */
	public function migrate(string $runUuid, int $targetVersion, string $reason, string $actor, array $mapping = []): array;

	/**
	 * Move every run of one version onto another, bounded.
	 *
	 * @param string                $flowId        The flow.
	 * @param integer               $sourceVersion The version to move off.
	 * @param integer               $targetVersion The version to move onto.
	 * @param string                $reason        Why.
	 * @param string                $actor         Who asked.
	 * @param array<string, string> $mapping       One mapping for all of them.
	 * @param integer               $limit         The batch bound.
	 *
	 * @return array{migrated: int, skipped: int, results: array<int, array<string, mixed>>}
	 */
	public function migrateRunsOfVersion(
		string $flowId, int $sourceVersion, int $targetVersion, string $reason,
		string $actor, array $mapping = [], int $limit = 100,
	): array;

	/**
	 * Answer what migrating one run would do, without moving it.
	 *
	 * @param string                $runUuid       The run.
	 * @param integer               $targetVersion The version to move onto.
	 * @param string                $reason        Why, for the answer's own record.
	 * @param string                $actor         Who asked.
	 * @param array<string, string> $mapping       Old node id to new node id.
	 *
	 * @return array<string, mixed> The dry-run answer.
	 */
	public function preview(string $runUuid, int $targetVersion, string $reason, string $actor, array $mapping = []): array;
}//end interface
