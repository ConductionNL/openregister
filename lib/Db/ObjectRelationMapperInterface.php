<?php

/**
 * ObjectRelationMapperInterface: what callers outside the Db component use of ObjectRelationMapper.
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

use OCP\AppFramework\Db\Entity;

/**
 * The methods of ObjectRelationMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to ObjectRelationMapper.
 */
interface ObjectRelationMapperInterface {

	/**
	 * Create a row from an array of values.
	 *
	 * @param array<string, mixed> $data The row's values.
	 *
	 * @return ObjectRelation The persisted row.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function createFromArray(array $data): ObjectRelation;

	/**
	 * Inherited from QBMapper::delete().
	 *
	 * @param ObjectRelation $entity The entity.
	 *
	 * @return ObjectRelation
	 *
	 * @spec exclude Inherited persistence primitive; no behaviour of its own.
	 */
	public function delete(Entity $entity): Entity;

	/**
	 * Delete every row one text anchor wrote.
	 *
	 * Deleting the text deletes exactly the rows that text wrote: the anchor
	 * and the prose origin together, never one of the two, so a row somebody
	 * added by hand on the same object survives its neighbour's text going.
	 *
	 * @param string $sourceUuid The object the text belongs to.
	 * @param string $anchor The anchor.
	 *
	 * @return int The number of deleted rows.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function deleteByAnchor(string $sourceUuid, string $anchor): int;

	/**
	 * The rows one text anchor wrote.
	 *
	 * @param string $sourceUuid The object the text belongs to.
	 * @param string $anchor The anchor.
	 *
	 * @return ObjectRelation[] The rows.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function findByAnchor(string $sourceUuid, string $anchor): array;

	/**
	 * The rows an object is the source of.
	 *
	 * @param string $sourceUuid The object's uuid.
	 * @param string|null $kind Optional `object` / `external` filter.
	 *
	 * @return ObjectRelation[] The rows.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function findBySource(string $sourceUuid, ?string $kind = null): array;

	/**
	 * The rows an object is the target of.
	 *
	 * @param string $targetUuid The object's uuid.
	 *
	 * @return ObjectRelation[] The rows.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function findByTarget(string $targetUuid): array;

	/**
	 * One row by its uuid.
	 *
	 * @param string $uuid The row's uuid.
	 *
	 * @return ObjectRelation The row.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no row has that uuid.
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException When more than one does.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function findByUuid(string $uuid): ObjectRelation;

	/**
	 * Every row touching any of a set of objects, in either direction.
	 *
	 * One query per graph level rather than one per node: a level of forty
	 * nodes is one round trip, which is the difference between a graph read
	 * that returns and one that does not.
	 *
	 * @param array<int, string> $uuids The objects at this level.
	 *
	 * @return ObjectRelation[] The rows.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function findTouching(array $uuids): array;
}//end interface
