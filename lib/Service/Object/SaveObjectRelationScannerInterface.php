<?php

/**
 * SaveObjectRelationScannerInterface: the relation scan of SaveObject.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Schema;

/**
 * The relation-scan role of SaveObjectInterface, for callers that only look for relations in object data.
 */
interface SaveObjectRelationScannerInterface {

	/**
	 * Scans an object for relations (UUIDs and URLs) and returns them in dot notation
	 *
	 * This method now also checks schema properties for relation types:
	 * - Properties with type 'text' and format 'uuid', 'uri', or 'url'
	 * - Properties with type 'object' that contain string values (always treated as relations)
	 * - Properties with type 'array' of objects that contain string values
	 *
	 * @param array $data The object data to scan
	 * @param string $prefix The current prefix for dot notation (used in recursion)
	 * @param Schema|null $schema The schema to check property definitions against
	 *
	 * @return array Array of relations with dot notation paths as keys and UUIDs/URLs as values
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function scanForRelations(array $data, string $prefix = '', ?Schema $schema = null): array;
}//end interface
