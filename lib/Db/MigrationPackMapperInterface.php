<?php

/**
 * MigrationPackMapperInterface: what callers outside the Db component use of MigrationPackMapper.
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

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;

/**
 * The methods of MigrationPackMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to MigrationPackMapper. Guarded by C04InterfaceBindingsTest.
 */
interface MigrationPackMapperInterface {

	/**
	 * Find a migration pack by numeric id.
	 *
	 * @param int $id The migration pack id.
	 *
	 * @return MigrationPack
	 *
	 * @throws DoesNotExistException When no row matches.
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md
	 */
	public function find(int $id): MigrationPack;

	/**
	 * Find every migration pack.
	 *
	 * @return MigrationPack[]
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md
	 */
	public function findAll(): array;

	/**
	 * Find a migration pack by its pack-document slug (`packSlug`, i.e. the
	 * definition's own `id` field). This is the lookup used by the import
	 * endpoint's `packId` request parameter.
	 *
	 * @param string $packSlug The pack document's own `id`.
	 *
	 * @return MigrationPack
	 *
	 * @throws DoesNotExistException When no row matches.
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md
	 */
	public function findByPackSlug(string $packSlug): MigrationPack;

	/**
	 * Delete one row.
	 *
	 * @param Entity $entity The row to delete.
	 *
	 * @return MigrationPack The deleted row.
	 */
	public function delete(Entity $entity): Entity;

	/**
	 * Insert one row.
	 *
	 * @param Entity $entity The row to insert.
	 *
	 * @return MigrationPack The inserted row, with its id.
	 */
	public function insert(Entity $entity): Entity;

	/**
	 * Update one row.
	 *
	 * @param Entity $entity The row to update.
	 *
	 * @return MigrationPack The updated row.
	 */
	public function update(Entity $entity): Entity;
}//end interface
