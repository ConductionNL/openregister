<?php

/**
 * SchemaMigrationServiceInterface: the part of SchemaMigrationService that the schema migration controller uses, and its default preview sample.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schema
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schema;

use OCA\OpenRegister\Db\SchemaRun;
use OCA\OpenRegister\Exception\SchemaRunConcurrencyException;

/**
 * The methods of SchemaMigrationService that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface SchemaMigrationServiceInterface {

	/**
	 * Default preview sample size.
	 *
	 * SchemaMigrationService inherits it, so SchemaMigrationService::DEFAULT_PREVIEW_SAMPLE still resolves.
	 *
	 * @var int
	 */
	public const DEFAULT_PREVIEW_SAMPLE = 10;

	/**
	 * Validate a migration plan structurally.
	 *
	 * @param array<int, array<string, mixed>> $plan The transform chain.
	 *
	 * @return array<int, string> Problems (empty when valid).
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function validatePlan(array $plan): array;

	/**
	 * Preview a plan against a bounded sample without persisting.
	 *
	 * @param int $schemaId The schema id.
	 * @param int $registerId The register id.
	 * @param array<int, array<string, mixed>> $plan The transform chain.
	 * @param int $sampleSize The sample size.
	 *
	 * @return array<int, array<string, mixed>> Before/after pairs.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function preview(int $schemaId, int $registerId, array $plan, int $sampleSize = self::DEFAULT_PREVIEW_SAMPLE): array;

	/**
	 * Start a migration run.
	 *
	 * @param int $schemaId The schema id.
	 * @param int $registerId The register id.
	 * @param array<int, array<string, mixed>> $plan The transform chain.
	 * @param array<string, mixed> $options Options (stopOnError).
	 * @param string|null $startedBy The starting user.
	 *
	 * @return SchemaRun The created run.
	 *
	 * @throws SchemaRunConcurrencyException When an active run exists.
	 * @throws \InvalidArgumentException When the plan is invalid.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function start(int $schemaId, int $registerId, array $plan, array $options = [], ?string $startedBy = null): SchemaRun;

	/**
	 * Roll a completed or failed migration run back.
	 *
	 * Restores each migrated object's pre-migration snapshot forward through
	 * the save pipeline, but only when the object's current version still
	 * equals the recorded post-migration version; otherwise the object is
	 * conflict-skipped and reported. A run can be rolled back only once.
	 *
	 * @param int $runId The migration run id.
	 * @param string|null $startedBy The user performing the rollback.
	 *
	 * @return SchemaRun The rollback result on the original run.
	 *
	 * @throws SchemaRunConcurrencyException When the run was already rolled back.
	 * @throws \InvalidArgumentException When the run is not a migration.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function rollback(int $runId, ?string $startedBy = null): SchemaRun;
}//end interface
