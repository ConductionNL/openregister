<?php

/**
 * CospendLinkMapperInterface: what callers outside the Db component use of CospendLinkMapper.
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
 * The methods of CospendLinkMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to CospendLinkMapper. Guarded by C04InterfaceBindingsTest.
 */
interface CospendLinkMapperInterface {

	/**
	 * Delete a cospend link by object UUID + link row id (Tier-2 unlink path).
	 *
	 * Returns the number of rows actually deleted so callers can
	 * distinguish "no such link" (0) from "ok" (>=1).
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $entryId The link row id.
	 *
	 * @return int Number of deleted rows.
	 */
	public function deleteByObjectAndId(string $objectUuid, int $entryId): int;

	/**
	 * Find the links of an object, newest first.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return CospendLink[] The links.
	 *
	 * @spec exclude shared link-mapper plumbing, not a capability of its own
	 */
	public function findByObjectUuid(string $objectUuid): array;

	/**
	 * Find a duplicate link by the composite-unique tuple.
	 *
	 * Bill rows match on (object_uuid, entry_type, project_id, bill_id);
	 * project rows pass billId = null which the query matches with an
	 * IS NULL predicate.
	 *
	 * @param string          $objectUuid The object UUID.
	 * @param CospendEntryKey $entry      The entry type, project and bill.
	 *
	 * @return CospendLink|null The existing link or null.
	 */
	public function findDuplicate(string $objectUuid, CospendEntryKey $entry): ?CospendLink;

	/**
	 * Insert one row.
	 *
	 * @param Entity $entity The row to insert.
	 *
	 * @return CospendLink The inserted row, with its id.
	 */
	public function insert(Entity $entity): Entity;

	/**
	 * Update one row.
	 *
	 * @param Entity $entity The row to update.
	 *
	 * @return CospendLink The updated row.
	 */
	public function update(Entity $entity): Entity;
}//end interface
