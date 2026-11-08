<?php

/**
 * Where a run migration moves runs to, why, on whose request and with which node mapping.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * The part of a migration that is the same for every run it moves: the
 * target version, the reason, the actor and the node mapping. A single
 * migration, its preview and a bulk migration of one version all take it.
 *
 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
 */
final class FlowRunMigrationTarget {

	/**
	 * Constructor.
	 *
	 * @param int                   $targetVersion The version to move onto.
	 * @param string                $reason        Why; recorded on the run as given, trimmed.
	 * @param string                $actor         Who asked.
	 * @param array<string, string> $mapping       Old node id to new node id.
	 */
	public function __construct(
		public readonly int $targetVersion,
		public readonly string $reason,
		public readonly string $actor,
		public readonly array $mapping = [],
	) {
	}//end __construct()

	/**
	 * The migration of one run onto this target.
	 *
	 * @param string $runUuid The run.
	 * @param bool   $dryRun  Whether only the answer is wanted.
	 *
	 * @return FlowRunMigration The migration request.
	 */
	public function forRun(string $runUuid, bool $dryRun): FlowRunMigration {
		return (new FlowRunMigration(runUuid: $runUuid, targetVersion: $this->targetVersion, reason: trim($this->reason), actor: $this->actor))
			->withPlan(mapping: $this->mapping, dryRun: $dryRun);
	}//end forRun()
}//end class
