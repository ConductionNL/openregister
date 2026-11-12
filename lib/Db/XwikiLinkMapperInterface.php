<?php

/**
 * XwikiLinkMapperInterface: what callers outside the Db component use of XwikiLinkMapper.
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
 * The methods of XwikiLinkMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to XwikiLinkMapper. Guarded by C04InterfaceBindingsTest.
 */
interface XwikiLinkMapperInterface {

	/**
	 * Delete an xWiki link by object UUID + page reference (Tier-2 unlink path).
	 *
	 * Returns the number of rows actually deleted so callers can
	 * distinguish "no such link" (0) from "ok" (>=1).
	 *
	 * @param string $objectUuid The object UUID.
	 * @param string $pageReference The canonical xWiki page reference.
	 *
	 * @return int Number of deleted rows.
	 */
	public function deleteByObjectAndPage(string $objectUuid, string $pageReference): int;

	/**
	 * Find a specific xWiki link by object UUID and page reference.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param string $pageReference The canonical xWiki page reference.
	 *
	 * @return XwikiLink|null The link or null if not found.
	 */
	public function findByObjectAndPage(string $objectUuid, string $pageReference): ?XwikiLink;

	/**
	 * Find the links of an object, newest first.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return XwikiLink[] The links.
	 *
	 * @spec exclude shared link-mapper plumbing, not a capability of its own
	 */
	public function findByObjectUuid(string $objectUuid): array;

	/**
	 * Insert one row.
	 *
	 * @param Entity $entity The row to insert.
	 *
	 * @return XwikiLink The inserted row, with its id.
	 */
	public function insert(Entity $entity): Entity;

	/**
	 * Update one row.
	 *
	 * @param Entity $entity The row to update.
	 *
	 * @return XwikiLink The updated row.
	 */
	public function update(Entity $entity): Entity;
}//end interface
