<?php

/**
 * MagicObjectDeletionInterface: Guards and performs the deletion and purging of objects.
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
use OCA\OpenRegister\Exception\HookStoppedException;

/**
 * Guards and performs the deletion and purging of objects.
 *
 * One role of MagicMapperInterface: a caller that needs only this role types against it.
 */
interface MagicObjectDeletionInterface {
	/**
	 * Ask the delete guards whether an object may be deleted.
	 *
	 * Dispatches the stoppable ObjectDeletingEvent, on which the frozen, the
	 * working-calendar and the concept guards listen. Both the permanent and
	 * the soft delete ask it before they write.
	 *
	 * @param ObjectEntity $entity The object about to be deleted.
	 *
	 * @return void
	 *
	 * @throws HookStoppedException When a listener stops the delete.
	 *
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-a-frozen-record-cannot-be-deleted
	 */
	public function assertDeletable(ObjectEntity $entity): void;

	/**
	 * Delete ObjectEntity from register+schema table.
	 *
	 * Supports both soft delete (sets _deleted field) and hard delete (removes from table).
	 *
	 * @param ObjectEntity $entity The object entity to delete.
	 * @param Register $register The register context.
	 * @param Schema $schema The schema context.
	 * @param bool $hardDelete Whether to perform hard delete (default: false for soft delete).
	 * @param bool $dispatchEvents Whether to dispatch events.
	 *
	 * @throws Exception If deletion fails.
	 *
	 * @return ObjectEntity The deleted object entity.
	 */
	public function deleteObjectEntity(
		ObjectEntity $entity, Register $register, Schema $schema, bool $hardDelete = false, bool $dispatchEvents = true
	): ObjectEntity;

	/**
	 * Soft-delete multiple pre-resolved objects with ONE UPDATE per magic table.
	 *
	 * PERF: the per-object soft-delete path re-serialises and rewrites the whole
	 * row (updateObjectEntity → saveObjectToRegisterSchemaTable) and re-fetches
	 * it afterwards — for a cascade of N children that is ~5 queries per child.
	 * This method batches children of the same (register, schema) pair into a
	 * single `UPDATE ... SET _deleted = CASE _uuid ... END WHERE _uuid IN (...)`
	 * statement while preserving the per-object event contract:
	 *
	 * - `ObjectUpdatingEvent` is dispatched per entity BEFORE the write; a hook
	 *   that stops propagation skips that entity (parity with the per-object
	 *   path, where HookStoppedException aborted the child's delete).
	 * - A hook that modifies the payload routes that entity through the
	 *   full-row save so the modification persists (the batched UPDATE only
	 *   writes the deleted marker).
	 * - `ObjectUpdatedEvent` is dispatched per entity AFTER the write.
	 *
	 * The caller must set the `deleted` metadata on every entity beforehand
	 * (its JSON is what the batched UPDATE writes) — deletion attribution is
	 * the caller's domain, persistence is this mapper's.
	 *
	 * @param array $entities ObjectEntity list to soft-delete
	 *                        (validated at runtime); uuid, numeric
	 *                        register/schema ids and `deleted`
	 *                        metadata must be set on every entity.
	 * @param array<string, ObjectEntity> $oldEntities Optional pre-delete snapshots keyed
	 *                                                 by uuid, used as event old-objects.
	 *                                                 Missing snapshots are derived by
	 *                                                 cloning the (mutated) entity.
	 *
	 * @return ObjectEntity[] The entities that were actually soft-deleted.
	 *
	 * @throws Exception If a batched UPDATE fails for every table.
	 *
	 * @psalm-return list<ObjectEntity>
	 */
	public function softDeleteMultipleObjectEntities(array $entities, array $oldEntities = []): array;

	/**
	 * Delete all objects belonging to a specific schema from the magic table.
	 *
	 * This method performs an optimized bulk deletion from the magic table
	 * for all objects belonging to a given register/schema combination.
	 *
	 * @param Register $register The register context.
	 * @param Schema $schema The schema context.
	 * @param bool $hardDelete Whether to perform a hard delete (default: false for soft delete).
	 *
	 * @throws Exception If deletion fails.
	 *
	 * @return int The number of objects deleted.
	 */
	public function deleteObjectsBySchema(Register $register, Schema $schema, bool $hardDelete = false): int;

	/**
	 * Hard-delete the rows of a register+schema table whose `_expires` has passed.
	 *
	 * The sweep for rows written through bulkUpsert() with an expiry, i.e. raw
	 * appends. There is no soft-delete and no audit entry: those rows never had
	 * either, and keeping expired telemetry as tombstones would defeat the
	 * retention the expiry expresses. Rows with a NULL `_expires` are never
	 * touched. A table that does not exist yet has nothing to purge and answers 0.
	 *
	 * @param Register $register The register context.
	 * @param Schema   $schema   The schema context.
	 *
	 * @return int The number of rows removed.
	 *
	 * @throws Exception If the delete fails.
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-raw-append-for-high-volume-writers
	 */
	public function purgeExpired(Register $register, Schema $schema): int;

	/**
	 * Batch delete objects by UUID list from a register+schema magic table.
	 *
	 * Performs a single SQL statement for all UUIDs instead of one-by-one.
	 * Supports both soft delete (sets _deleted metadata) and hard delete.
	 *
	 * @param Register $register The register context.
	 * @param Schema $schema The schema context.
	 * @param array $uuids Array of object UUIDs to delete.
	 * @param bool $hardDelete Whether to hard delete (default: soft delete).
	 *
	 * @return int Number of objects deleted.
	 */
	public function deleteObjectsByUuids(Register $register, Schema $schema, array $uuids, bool $hardDelete = false): int;

	/**
	 * Delete multiple objects.
	 *
	 * @param array $uuids Object UUIDs to delete
	 * @param bool $hardDelete Whether to hard delete
	 *
	 * @return array Delete results
	 *
	 * @psalm-return list<mixed>
	 */
	public function deleteObjects(array $uuids = [], bool $hardDelete = false): array;
}//end interface
