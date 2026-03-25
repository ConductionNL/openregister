<?php

/**
 * Mapper for deck link entities.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class DeckLinkMapper
 *
 * @template-extends QBMapper<DeckLink>
 */
class DeckLinkMapper extends QBMapper
{
    /**
     * Constructor.
     *
     * @param IDBConnection $db Database connection.
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'openregister_deck_links', DeckLink::class);
    }//end __construct()

    /**
     * Find deck links by object UUID.
     *
     * @param string $objectUuid The object UUID.
     *
     * @return DeckLink[] Array of deck links.
     */
    public function findByObjectUuid(string $objectUuid): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
            ->orderBy('linked_at', 'DESC');

        return $this->findEntities($qb);
    }//end findByObjectUuid()

    /**
     * Find deck links by board ID.
     *
     * @param int $boardId The Deck board ID.
     *
     * @return DeckLink[] Array of deck links.
     */
    public function findByBoardId(int $boardId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
            ->orderBy('linked_at', 'DESC');

        return $this->findEntities($qb);
    }//end findByBoardId()

    /**
     * Find a specific deck link by object UUID and card ID.
     *
     * @param string $objectUuid The object UUID.
     * @param int    $cardId     The Deck card ID.
     *
     * @return DeckLink|null The link or null if not found.
     */
    public function findByObjectAndCard(string $objectUuid, int $cardId): ?DeckLink
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
            ->andWhere($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)));

        try {
            return $this->findEntity($qb);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
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
    public function deleteByObjectUuid(string $objectUuid): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));

        return $qb->executeStatement();
    }//end deleteByObjectUuid()
}//end class
