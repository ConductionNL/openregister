<?php

/**
 * Mapper for deck link entities.
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
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class DeckLinkMapper
 *
 * @template-extends QBMapper<DeckLink>
 */
class DeckLinkMapper extends QBMapper {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_deck_links', entityClass: DeckLink::class);
	}//end __construct()

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
	public function find(int $id): DeckLink {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity(query: $qb);
	}//end find()

	/**
	 * Find deck links by object UUID.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return DeckLink[] Array of deck links.
	 */
	public function findByObjectUuid(string $objectUuid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->orderBy('linked_at', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findByObjectUuid()

	/**
	 * Find deck links by board ID.
	 *
	 * @param int $boardId The Deck board ID.
	 *
	 * @return DeckLink[] Array of deck links.
	 */
	public function findByBoardId(int $boardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->orderBy('linked_at', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findByBoardId()

	/**
	 * Find a specific deck link by object UUID and card ID.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $cardId The Deck card ID.
	 *
	 * @return DeckLink|null The link or null if not found.
	 */
	public function findByObjectAndCard(string $objectUuid, int $cardId): ?DeckLink {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->andWhere($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)));

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}//end findByObjectAndCard()

	/**
	 * Delete all deck links for an object UUID.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return int Number of deleted rows.
	 */
	public function deleteByObjectUuid(string $objectUuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));

		return $qb->executeStatement();
	}//end deleteByObjectUuid()

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
	public function deleteByObjectAndCard(string $objectUuid, int $cardId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->andWhere($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}//end deleteByObjectAndCard()
}//end class
