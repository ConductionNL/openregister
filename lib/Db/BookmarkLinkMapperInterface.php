<?php

/**
 * BookmarkLinkMapperInterface: what callers outside the Db component use of BookmarkLinkMapper.
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
 * The methods of BookmarkLinkMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to BookmarkLinkMapper. Guarded by C04InterfaceBindingsTest.
 */
interface BookmarkLinkMapperInterface {

	/**
	 * Delete a bookmark link by object UUID + bookmark id (Tier-2 unlink).
	 *
	 * Returns the number of rows actually deleted so callers can
	 * distinguish "no such link" (0) from "ok" (>=1).
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $bookmarkId The bookmark id.
	 *
	 * @return int Number of deleted rows.
	 */
	public function deleteByObjectAndBookmark(string $objectUuid, int $bookmarkId): int;

	/**
	 * Find a specific bookmark link by object UUID and bookmark id.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $bookmarkId The bookmark id.
	 *
	 * @return BookmarkLink|null The link or null if not found.
	 */
	public function findByObjectAndBookmark(string $objectUuid, int $bookmarkId): ?BookmarkLink;

	/**
	 * Find the links of an object, newest first.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return BookmarkLink[] The links.
	 *
	 * @spec exclude shared link-mapper plumbing, not a capability of its own
	 */
	public function findByObjectUuid(string $objectUuid): array;

	/**
	 * Insert one row.
	 *
	 * @param Entity $entity The row to insert.
	 *
	 * @return BookmarkLink The inserted row, with its id.
	 */
	public function insert(Entity $entity): Entity;

	/**
	 * Update one row.
	 *
	 * @param Entity $entity The row to update.
	 *
	 * @return BookmarkLink The updated row.
	 */
	public function update(Entity $entity): Entity;
}//end interface
