<?php

/**
 * DeckLinkMapperInterface: what callers outside the Db component use of DeckLinkMapper.
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
 * The methods of DeckLinkMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to DeckLinkMapper. Guarded by C04InterfaceBindingsTest.
 */
interface DeckLinkMapperInterface {

	/**
	 * Delete a deck link by object UUID + card ID (Tier-2 unlink path).
	 *
	 * Returns the number of rows actually deleted so callers can
	 * distinguish "no such link" (0) from "ok" (>=1).
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $cardId The Deck card ID.
	 *
	 * @return int Number of deleted rows.
	 */
	public function deleteByObjectAndCard(string $objectUuid, int $cardId): int;

	/**
	 * Delete all links of an object.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return int Number of deleted rows.
	 *
	 * @spec exclude shared link-mapper plumbing, not a capability of its own
	 */
	public function deleteByObjectUuid(string $objectUuid): int;

	/**
	 * One link by its row id.
	 *
	 * QBMapper has no `find()`, and `DeckCardService::unlinkCard()` calls one:
	 * without it unlinking a card answers 500 with "Call to undefined method",
	 * the same defect the contact links carried. psalm's baseline had it
	 * recorded as a suppressed UndefinedMethod rather than fixed.
	 *
	 * @param int $id The row id.
	 *
	 * @return DeckLink The link.
	 *
	 * @throws DoesNotExistException When no link has that id.
	 */
	public function find(int $id): DeckLink;

	/**
	 * Find deck links by board ID.
	 *
	 * @param int $boardId The Deck board ID.
	 *
	 * @return DeckLink[] Array of deck links.
	 */
	public function findByBoardId(int $boardId): array;

	/**
	 * Find a specific deck link by object UUID and card ID.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $cardId The Deck card ID.
	 *
	 * @return DeckLink|null The link or null if not found.
	 */
	public function findByObjectAndCard(string $objectUuid, int $cardId): ?DeckLink;

	/**
	 * Find the links of an object, newest first.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return DeckLink[] The links.
	 *
	 * @spec exclude shared link-mapper plumbing, not a capability of its own
	 */
	public function findByObjectUuid(string $objectUuid): array;

	/**
	 * Delete one row.
	 *
	 * @param Entity $entity The row to delete.
	 *
	 * @return DeckLink The deleted row.
	 */
	public function delete(Entity $entity): Entity;

	/**
	 * Insert one row.
	 *
	 * @param Entity $entity The row to insert.
	 *
	 * @return DeckLink The inserted row, with its id.
	 */
	public function insert(Entity $entity): Entity;
}//end interface
