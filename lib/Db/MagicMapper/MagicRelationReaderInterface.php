<?php

/**
 * MagicRelationReaderInterface: Finds the objects that refer to an object or to a linked entity.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
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

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * Finds the objects that refer to an object or to a linked entity.
 *
 * One role of MagicMapperInterface: a caller that needs only this role types against it.
 */
interface MagicRelationReaderInterface {
	/**
	 * Find objects that reference a specific UUID in any of their columns.
	 *
	 * This method searches across ALL magic mapper tables for objects that
	 * contain the specified UUID in any column. This is used for inverse
	 * relationship resolution.
	 *
	 * For PostgreSQL, it uses casting to text and LIKE for JSON columns.
	 * For other databases, it uses LIKE on all columns.
	 *
	 * @param string $uuid The UUID to search for
	 * @param string|null $_search Optional search filter
	 * @param bool $_partialMatch Whether to use partial matching
	 *
	 * @return ObjectEntity[] Array of objects that contain the UUID
	 *
	 * @psalm-return list<ObjectEntity>
	 */
	public function findByRelation(string $uuid, ?string $_search = null, bool $_partialMatch = false): array;

	/**
	 * Find objects that reference a UUID using the _relations column.
	 *
	 * This is a PERFORMANT alternative to findByRelation() that uses
	 * PostgreSQL's JSONB @> operator on the indexed _relations column
	 * instead of slow full-text LIKE searches.
	 *
	 * The _relations column stores an array of UUIDs that the object references,
	 * making containment queries very efficient (O(log n) with GIN index).
	 *
	 * @param string $uuid The UUID to search for in _relations
	 *
	 * @return ObjectEntity[] Array of objects that have this UUID in their _relations
	 *
	 * @psalm-return list<ObjectEntity>
	 */
	public function findByRelationUsingRelationsColumn(string $uuid): array;

	/**
	 * Find objects in a specific schema's magic table that have a given entity ID in a linked type column.
	 *
	 * Used for reverse lookups: "find all objects linked to mail 1/6".
	 * The linked type columns (_mail, _contacts, etc.) store JSON arrays of string IDs.
	 *
	 * @param Schema $schema The schema whose magic table to search
	 * @param string $columnName The column name (e.g., '_mail', '_contacts')
	 * @param string $entityId The entity ID to search for
	 *
	 * @return array Array of ObjectEntity objects
	 */
	public function findByLinkedEntity(Schema $schema, string $columnName, string $entityId): array;

	/**
	 * Batch find objects in a specific schema that reference ANY of the given UUIDs.
	 *
	 * This is a CRITICAL performance optimization for inverse relationship preloading.
	 * Instead of N queries (one per entity), we do ONE query per target schema to find
	 * ALL objects that reference ANY of our entities.
	 *
	 * Uses the _relations GIN index for efficient containment queries.
	 *
	 * @param array $uuids Array of UUIDs to search for in _relations.
	 * @param int $schemaId The target schema ID to search in.
	 * @param int $registerId The register ID for the magic table.
	 * @param string $fieldName The field name to check (for logging/debugging).
	 * @param array $additionalFieldNames Additional field names to check.
	 *
	 * @return ObjectEntity[] Array of objects that have ANY of the UUIDs in _relations
	 *
	 * @psalm-return list<ObjectEntity>
	 */
	public function findByRelationBatchInSchema(
		array $uuids, int $schemaId, int $registerId, string $fieldName, array $additionalFieldNames = []
	): array;
}//end interface
