<?php

/**
 * SchemaRunMapperInterface: what callers outside the component use of SchemaRunMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * The schema-run store the schema migration, revalidation and versioning services use: the job's find and save, plus the run listing and creation.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own. Bound to SchemaRunMapper.
 */
interface SchemaRunMapperInterface extends SchemaRunStoreInterface {

	/**
	 * Find runs for a schema, newest first.
	 *
	 * @param int $schemaId The schema id.
	 * @param ListQuery $query The page: its size and offset, unbounded for every row.
	 *
	 * @return SchemaRun[] The runs.
	 */
	public function findBySchema(int $schemaId, ListQuery $query): array;

	/**
	 * Find the active (blocking) run for a schema, if any.
	 *
	 * @param int $schemaId The schema id.
	 *
	 * @return SchemaRun|null The active run, or null.
	 */
	public function findActiveForSchema(int $schemaId): ?SchemaRun;

	/**
	 * Create a run from an array of values, assigning a UUID and timestamps.
	 *
	 * @param array<string, mixed> $data The run values.
	 *
	 * @return SchemaRun The persisted run.
	 */
	public function createFromArray(array $data): SchemaRun;
}//end interface
