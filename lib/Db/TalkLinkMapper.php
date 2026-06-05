<?php

/**
 * Mapper for talk link entities.
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
use OCP\IDBConnection;

/**
 * Class TalkLinkMapper
 *
 * @template-extends QBMapper<TalkLink>
 */
class TalkLinkMapper extends QBMapper
{
    /**
     * Constructor.
     *
     * @param IDBConnection $db Database connection.
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(db: $db, tableName: 'openregister_talk_links', entityClass: TalkLink::class);
    }//end __construct()

    /**
     * Find talk links by object UUID.
     *
     * @param string $objectUuid The object UUID.
     *
     * @return TalkLink[] Array of talk links.
     */
    public function findByObjectUuid(string $objectUuid): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
            ->orderBy('linked_at', 'DESC');

        return $this->findEntities(query: $qb);
    }//end findByObjectUuid()

    /**
     * Find talk links by room token (reverse lookup — which objects is
     * this room linked to?).
     *
     * @param string $roomToken The Talk room token.
     *
     * @return TalkLink[] Array of talk links.
     */
    public function findByRoomToken(string $roomToken): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('room_token', $qb->createNamedParameter($roomToken)))
            ->orderBy('linked_at', 'DESC');

        return $this->findEntities(query: $qb);
    }//end findByRoomToken()

    /**
     * Find a specific talk link by object UUID and room token.
     *
     * @param string $objectUuid The object UUID.
     * @param string $roomToken  The Talk room token.
     *
     * @return TalkLink|null The link or null if not found.
     */
    public function findByObjectAndRoom(string $objectUuid, string $roomToken): ?TalkLink
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
            ->andWhere($qb->expr()->eq('room_token', $qb->createNamedParameter($roomToken)));

        try {
            return $this->findEntity(query: $qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }//end findByObjectAndRoom()

    /**
     * Delete all talk links for an object UUID.
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

    /**
     * Delete a talk link by object UUID + room token (Tier-2 unlink
     * path — does NOT destroy the Talk room itself).
     *
     * Returns the number of rows actually deleted so callers can
     * distinguish "no such link" (0) from "ok" (>=1).
     *
     * @param string $objectUuid The object UUID.
     * @param string $roomToken  The Talk room token.
     *
     * @return int Number of deleted rows.
     */
    public function deleteByObjectAndRoom(string $objectUuid, string $roomToken): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
            ->andWhere($qb->expr()->eq('room_token', $qb->createNamedParameter($roomToken)));

        return $qb->executeStatement();
    }//end deleteByObjectAndRoom()
}//end class
