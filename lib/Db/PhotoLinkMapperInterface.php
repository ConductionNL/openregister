<?php

/**
 * PhotoLinkMapperInterface: what callers outside the Db component use of PhotoLinkMapper.
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
 * The methods of PhotoLinkMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to PhotoLinkMapper. Guarded by C04InterfaceBindingsTest.
 */
interface PhotoLinkMapperInterface {

	/**
	 * Delete a photo link by object UUID + album id (Tier-2 unlink path).
	 *
	 * Returns the number of rows actually deleted so callers can
	 * distinguish "no such link" (0) from "ok" (>=1).
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $albumId The album id.
	 *
	 * @return int Number of deleted rows.
	 */
	public function deleteByObjectAndAlbum(string $objectUuid, int $albumId): int;

	/**
	 * Find a specific photo link by object UUID and album id.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $albumId The album id.
	 *
	 * @return PhotoLink|null The link or null if not found.
	 */
	public function findByObjectAndAlbum(string $objectUuid, int $albumId): ?PhotoLink;

	/**
	 * Find the links of an object, newest first.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return PhotoLink[] The links.
	 *
	 * @spec exclude shared link-mapper plumbing, not a capability of its own
	 */
	public function findByObjectUuid(string $objectUuid): array;

	/**
	 * Insert one row.
	 *
	 * @param Entity $entity The row to insert.
	 *
	 * @return PhotoLink The inserted row, with its id.
	 */
	public function insert(Entity $entity): Entity;

	/**
	 * Update one row.
	 *
	 * @param Entity $entity The row to update.
	 *
	 * @return PhotoLink The updated row.
	 */
	public function update(Entity $entity): Entity;
}//end interface
