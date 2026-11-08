<?php

/**
 * SchemaChangelogMapperInterface: the part of SchemaChangelogMapper that the schema migration controller uses.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;

/**
 * The methods of SchemaChangelogMapper that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface SchemaChangelogMapperInterface {

	/**
	 * Find changelog entries for a schema, newest first.
	 *
	 * @param int $schemaId The schema id.
	 * @param int|null $limit Optional page size.
	 * @param int|null $offset Optional page offset.
	 *
	 * @return SchemaChangelog[] The entries.
	 */
	public function findBySchema(int $schemaId, ?int $limit = null, ?int $offset = null): array;
}//end interface
