<?php

/**
 * PollLinkMapperInterface: what callers outside the Db component use of PollLinkMapper.
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
 * The methods of PollLinkMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to PollLinkMapper. Guarded by C04InterfaceBindingsTest.
 */
interface PollLinkMapperInterface {

	/**
	 * Delete a poll link by object UUID + poll id (Tier-2 unlink path).
	 *
	 * Returns the number of rows actually deleted so callers can
	 * distinguish "no such link" (0) from "ok" (>=1).
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $pollId The poll id.
	 *
	 * @return int Number of deleted rows.
	 */
	public function deleteByObjectAndPoll(string $objectUuid, int $pollId): int;

	/**
	 * Find a specific poll link by object UUID and poll id.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $pollId The poll id.
	 *
	 * @return PollLink|null The link or null if not found.
	 */
	public function findByObjectAndPoll(string $objectUuid, int $pollId): ?PollLink;

	/**
	 * Find the links of an object, newest first.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return PollLink[] The links.
	 *
	 * @spec exclude shared link-mapper plumbing, not a capability of its own
	 */
	public function findByObjectUuid(string $objectUuid): array;

	/**
	 * Insert one row.
	 *
	 * @param Entity $entity The row to insert.
	 *
	 * @return PollLink The inserted row, with its id.
	 */
	public function insert(Entity $entity): Entity;
}//end interface
