<?php

/**
 * OpenRegister FlowRunMigration.
 *
 * One request to move a flow run onto another version of its definition:
 * the run, the target version, why, who asked, the node mapping and whether
 * it is a dry run.
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
 * A run migration request handled by `FlowRunMigrationService`.
 *
 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
 */
final class FlowRunMigration {
	/**
	 * Old node id to new node id.
	 *
	 * @var array<string, string>
	 */
	private array $mapping = [];

	/**
	 * True to answer without writing.
	 *
	 * @var boolean
	 */
	private bool $dryRun = false;

	/**
	 * Constructor.
	 *
	 * @param string $runUuid The run.
	 * @param int $targetVersion The version to move onto.
	 * @param string $reason Why, trimmed; recorded on the run.
	 * @param string $actor Who asked.
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	public function __construct(
		public readonly string $runUuid,
		public readonly int $targetVersion,
		public readonly string $reason,
		public readonly string $actor,
	) {

	}//end __construct()

	/**
	 * The same request with a node mapping and the dry-run choice.
	 *
	 * @param array<string, string> $mapping Old node id to new node id.
	 * @param bool $dryRun True to answer without writing.
	 *
	 * @return self The new request.
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	public function withPlan(array $mapping, bool $dryRun): self {
		$copy = clone $this;
		$copy->mapping = $mapping;
		$copy->dryRun = $dryRun;
		return $copy;

	}//end withPlan()

	/**
	 * Old node id to new node id.
	 *
	 * @return array<string, string> The mapping.
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	public function mapping(): array {
		return $this->mapping;

	}//end mapping()

	/**
	 * Whether this request only previews.
	 *
	 * @return bool True for a dry run.
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	public function isDryRun(): bool {
		return $this->dryRun;

	}//end isDryRun()
}//end class
