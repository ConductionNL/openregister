<?php

/**
 * GdprEntityMapperInterface: what callers outside the component use of GdprEntityMapper.
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

use OCP\AppFramework\Db\Entity;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The detected-entity (personal data) store the GDPR entity endpoints and the file text extraction read and write.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own. Bound to GdprEntityMapper.
 */
interface GdprEntityMapperInterface {

	/**
	 * Public wrapper for findEntities (parent protected method).
	 *
	 * @param IQueryBuilder $query The query builder.
	 *
	 * @return list<GdprEntity> Array of entities.
	 */
	public function findEntitiesPublic(IQueryBuilder $query): array;

	/**
	 * Find entity by ID.
	 *
	 * @param int $id Entity ID.
	 *
	 * @return GdprEntity The entity.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If entity not found.
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple entities found.
	 */
	public function find(int $id): GdprEntity;

	/**
	 * Look up a catalogue entry by its (value, type) pair.
	 *
	 * Used by `ManualEntityService` to implement the lookup-or-create
	 * catalogue dedup invariant: a `(value, type)` pair that already
	 * exists is REUSED on subsequent manual-entity adds so the same
	 * placeholder ID survives across documents.
	 *
	 * The query selects up to two rows. If two are returned the dedup
	 * invariant has been violated (most likely by a parallel manual-
	 * add race per design §D4); we log a warning and return the first
	 * row. We do NOT throw — the worst case is two near-identical
	 * catalogue rows, neither destructive, and the caller has a usable
	 * entity to proceed with.
	 *
	 * @param string $value Operator-supplied entity value (case- and whitespace-sensitive).
	 * @param string $type Entity type tag (e.g. `PERSON`, `ORGANIZATION`).
	 *
	 * @return GdprEntity|null The matching entity, or null when no row matches.
	 */
	public function findOneByValueAndType(string $value, string $type): ?GdprEntity;

	/**
	 * Insert a new entity row (QBMapper).
	 *
	 * @param Entity $entity The entity.
	 *
	 * @return GdprEntity The stored entity.
	 */
	public function insert(Entity $entity): Entity;

	/**
	 * Update an entity row (QBMapper).
	 *
	 * @param Entity $entity The entity.
	 *
	 * @return GdprEntity The stored entity.
	 */
	public function update(Entity $entity): Entity;

	/**
	 * Delete an entity row (QBMapper).
	 *
	 * @param Entity $entity The entity.
	 *
	 * @return GdprEntity The deleted entity.
	 */
	public function delete(Entity $entity): Entity;
}//end interface
