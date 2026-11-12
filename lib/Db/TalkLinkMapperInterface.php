<?php

/**
 * TalkLinkMapperInterface: what callers outside the Db component use of TalkLinkMapper.
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
 * The methods of TalkLinkMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to TalkLinkMapper. Guarded by C04InterfaceBindingsTest.
 */
interface TalkLinkMapperInterface {

	/**
	 * Delete a talk link by object UUID + room token (Tier-2 unlink
	 * path — does NOT destroy the Talk room itself).
	 *
	 * Returns the number of rows actually deleted so callers can
	 * distinguish "no such link" (0) from "ok" (>=1).
	 *
	 * @param string $objectUuid The object UUID.
	 * @param string $roomToken The Talk room token.
	 *
	 * @return int Number of deleted rows.
	 */
	public function deleteByObjectAndRoom(string $objectUuid, string $roomToken): int;

	/**
	 * Find a specific talk link by object UUID and room token.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param string $roomToken The Talk room token.
	 *
	 * @return TalkLink|null The link or null if not found.
	 */
	public function findByObjectAndRoom(string $objectUuid, string $roomToken): ?TalkLink;

	/**
	 * Find the links of an object, newest first.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return TalkLink[] The links.
	 *
	 * @spec exclude shared link-mapper plumbing, not a capability of its own
	 */
	public function findByObjectUuid(string $objectUuid): array;

	/**
	 * Insert one row.
	 *
	 * @param Entity $entity The row to insert.
	 *
	 * @return TalkLink The inserted row, with its id.
	 */
	public function insert(Entity $entity): Entity;

	/**
	 * Update one row.
	 *
	 * @param Entity $entity The row to update.
	 *
	 * @return TalkLink The updated row.
	 */
	public function update(Entity $entity): Entity;
}//end interface
