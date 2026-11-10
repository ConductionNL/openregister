<?php

/**
 * MagicObjectWriterInterface: Inserts, updates and saves objects, single and in bulk.
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

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\AppFramework\Db\Entity;

/**
 * Inserts, updates and saves objects, single and in bulk.
 *
 * One role of MagicMapperInterface: a caller that needs only this role types against it.
 */
interface MagicObjectWriterInterface {
	/**
	 * Insert a new object entity with event dispatching.
	 *
	 * @param Entity $entity Entity to insert.
	 * @param Register|null $register Optional register for routing.
	 * @param Schema|null $schema Optional schema for routing.
	 * @param bool $failIfExists Insert-only: refuse instead of updating when the identifier is taken.
	 *
	 * @return ObjectEntity Inserted entity.
	 *
	 * @throws Exception If insertion fails.
	 */
	public function insert(Entity $entity, ?Register $register = null, ?Schema $schema = null, bool $failIfExists = false): Entity;

	/**
	 * Update an existing object entity with event dispatching.
	 *
	 * @param Entity $entity Entity to update.
	 * @param Register|null $register Optional register for routing.
	 * @param Schema|null $schema Optional schema for routing.
	 * @param ObjectEntity|null $oldEntity Old entity for comparison.
	 *
	 * @return ObjectEntity Updated entity.
	 *
	 * @throws Exception If update fails.
	 */
	public function update(Entity $entity, ?Register $register = null, ?Schema $schema = null, ?ObjectEntity $oldEntity = null): Entity;

	/**
	 * Delete an object entity with event dispatching.
	 *
	 * @param Entity $entity Entity to delete.
	 *
	 * @return ObjectEntity Deleted entity.
	 *
	 * @throws Exception If deletion fails.
	 */
	public function delete(Entity $entity): Entity;

	/**
	 * Insert ObjectEntity into register+schema table.
	 *
	 * @param ObjectEntity $entity The object entity to insert.
	 * @param Register $register The register context.
	 * @param Schema $schema The schema context.
	 * @param bool $dispatchEvents Whether to dispatch events.
	 * @param bool $failIfExists Insert-only: refuse instead of updating when the identifier is taken.
	 *
	 * @throws Exception If insertion fails.
	 *
	 * @return ObjectEntity The inserted object entity.
	 */
	public function insertObjectEntity(
		ObjectEntity $entity, Register $register, Schema $schema, bool $dispatchEvents = true, bool $failIfExists = false
	): ObjectEntity;

	/**
	 * Update ObjectEntity in register+schema table.
	 *
	 * @param ObjectEntity $entity The object entity to update.
	 * @param Register $register The register context.
	 * @param Schema $schema The schema context.
	 * @param ObjectEntity|null $oldEntity The old entity for comparison.
	 *
	 * @throws Exception If update fails.
	 *
	 * @return ObjectEntity The updated object entity.
	 */
	public function updateObjectEntity(ObjectEntity $entity, Register $register, Schema $schema, ?ObjectEntity $oldEntity = null): ObjectEntity;

	/**
	 * What the last single-object write actually did.
	 *
	 * `create` or `update` — as decided by THIS layer's lookup, which is the
	 * one that ran last and therefore the one that matches the row. A caller
	 * that labelled the operation from its own earlier lookup should prefer
	 * this for anything it records, because between the two lookups a
	 * concurrent writer can turn a create into an update.
	 *
	 * Empty before any write in this request.
	 *
	 * @return string `create`, `update`, or ''.
	 */
	public function getLastWriteAction(): string;

	/**
	 * Record the node id of an object's files folder as bookkeeping, not as an edit of the object.
	 *
	 * The files folder is made on demand, often by a request that only lists
	 * the object's files (a portal showing a case's documents). Storing its id
	 * through update() ran the whole save path for that read: the updating and
	 * updated events, so every save-time listener (calculations, quality
	 * scoring, retention, other apps' handlers) ran as whoever was reading,
	 * with no audit row and no version bump to show for it. This write touches
	 * the `_folder` column of the one row and nothing else, dispatches no
	 * lifecycle event, and only lands while the stored value is still empty or
	 * what the caller read, so it never repoints a folder another request
	 * recorded first. It mirrors RegisterFolderRecorder for registers.
	 *
	 * @param ObjectEntity $entity The object whose folder was made.
	 * @param string|null $expected The folder value read before the folder was made: null or '' for none,
	 *                              or the legacy path that no longer resolves.
	 * @param string $folderId The node id of the folder the file service made or found.
	 *
	 * @return bool True when this call recorded the id; false when another request recorded one first.
	 *
	 * @throws Exception If the object has no uuid or its register and schema cannot be resolved.
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-recording-an-objects-folder-id-is-bookkeeping-req-ofib-001
	 */
	public function recordFolder(ObjectEntity $entity, ?string $expected, string $folderId): bool;

	/**
	 * Save objects to register+schema-specific table
	 *
	 * @param array $objects Array of object data to save
	 * @param Register $register The register context for table selection
	 * @param Schema $schema The schema for table selection
	 *
	 * @throws Exception If save operation fails
	 *
	 * @return string[]
	 *
	 * @psalm-return list<non-empty-string>
	 */
	public function saveObjectsToRegisterSchemaTable(array $objects, Register $register, Schema $schema): array;

	/**
	 * Perform bulk upsert operation on register+schema table
	 *
	 * This method provides high-performance bulk insert/update operations for dynamic tables.
	 * It delegates to MagicBulkHandler for the actual database operations.
	 *
	 * @param array $objects Array of object data in standard format
	 * @param Register $register Register context
	 * @param Schema $schema Schema context
	 * @param string $tableName Target table name
	 * @param bool $needsPreUpdateState Whether anything downstream will read the
	 *                                  pre-update rows (audit changeset or update
	 *                                  event). False narrows the fetch to the uuid.
	 *
	 * @return array[] Array of complete objects with object_status field
	 *
	 * @throws \OCP\DB\Exception If a database error occurs
	 *
	 * @psalm-return list<array<string, mixed>>
	 */
	public function bulkUpsert(array $objects, Register $register, Schema $schema, string $tableName, bool $needsPreUpdateState = true): array;

	/**
	 * Ultra-fast bulk save operation with automatic routing.
	 *
	 * Returns complete objects with database-computed classification (created/updated/unchanged).
	 *
	 * @param array $insertObjects Objects to insert/upsert
	 * @param array $updateObjects Objects to update (legacy parameter)
	 * @param Register|null $register Optional register context
	 * @param Schema|null $schema Optional schema context
	 * @param bool $needsPreUpdateState Whether anything downstream will read the
	 *                                  pre-update rows (audit changeset or update
	 *                                  event). False narrows the fetch to the uuid.
	 *
	 * @return array Array of complete objects with object_status field
	 *
	 * @throws \OCP\DB\Exception If a database error occurs
	 */
	public function ultraFastBulkSave(
		array $insertObjects = [], array $updateObjects = [], ?Register $register = null, ?Schema $schema = null,
		bool $needsPreUpdateState = true
	): array;

	/**
	 * Restore a soft-deleted object by clearing its `_deleted` marker.
	 *
	 * Objects live in per-register/schema magic tables (oc_openregister_table_*),
	 * NOT in the legacy generic `openregister_objects` table. A direct
	 * `UPDATE openregister_objects` therefore matches zero rows — which is why
	 * the previous restore reported success but never un-deleted anything. This
	 * resolves the object's owning magic table (including deleted rows) and
	 * sets `_deleted = NULL` on the correct table.
	 *
	 * @param string $uuid The UUID (or id/slug) of the deleted object.
	 *
	 * @return ObjectEntity The restored object.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If the object is not found.
	 * @throws Exception If register/schema context cannot be resolved.
	 */
	public function restoreObject(string $uuid): ObjectEntity;
}//end interface
