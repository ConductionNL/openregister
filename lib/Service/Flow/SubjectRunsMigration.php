<?php

/**
 * SubjectRunsMigration
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
 * A request to move the live runs of one subject onto another version.
 *
 * The target stays the reference the consuming app sent: a version number is
 * the only one a migration can act on, and anything else names another flow,
 * which {@see FlowRunMigrationService::migrateSubjectRuns()} refuses by name.
 *
 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
 */
final class SubjectRunsMigration {

	/**
	 * Name the request.
	 *
	 * @param string $subjectUuid         The object the runs are about.
	 * @param string $targetDefinitionRef The target version, as a number, or a flow uuid.
	 * @param string $actorUid            Who asked, recorded on the run.
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	public function __construct(
		public readonly string $subjectUuid,
		public readonly string $targetDefinitionRef,
		public readonly string $actorUid,
	) {
	}//end __construct()

	/**
	 * The per-run target: the version number, why, and who asked.
	 *
	 * @return FlowRunMigrationTarget
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	public function target(): FlowRunMigrationTarget {
		return new FlowRunMigrationTarget(
			targetVersion: (int)$this->targetDefinitionRef,
			reason: 'Migrated with its subject by ' . $this->actorUid,
			actor: $this->actorUid
		);
	}//end target()
}//end class
