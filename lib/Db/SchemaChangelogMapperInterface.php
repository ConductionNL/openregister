<?php

/**
 * SchemaChangelogMapperInterface: what callers outside the Db component use of SchemaChangelogMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;

/**
 * The methods of SchemaChangelogMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to SchemaChangelogMapper. Guarded by C04InterfaceBindingsTest.
 */
interface SchemaChangelogMapperInterface {

	/**
	 * Create a changelog entry from an array of values.
	 *
	 * @param array<string, mixed> $data The entry values.
	 *
	 * @return SchemaChangelog The persisted entry.
	 */
	public function createFromArray(array $data): SchemaChangelog;

	/**
	 * Find changelog entries for a schema, newest first.
	 *
	 * @param int $schemaId The schema id.
	 * @param ListQuery $query The page: its size and offset, unbounded for every row.
	 *
	 * @return SchemaChangelog[] The entries.
	 */
	public function findBySchema(int $schemaId, ListQuery $query): array;
}//end interface
