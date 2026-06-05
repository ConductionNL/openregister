<?php

/**
 * TalkLinkMapper — database persistence for TalkLink entities.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-talk/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class TalkLinkMapper
 *
 * Provides CRUD operations for TalkLink entities stored in
 * the `openregister_talk_links` table.
 *
 * @template-extends QBMapper<TalkLink>
 *
 * @spec openspec/changes/integration-talk/tasks.md#task-1
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
     * Find all Talk links for an OR object UUID.
     *
     * @param string $objectUuid The object UUID.
     * @param int    $limit      Maximum rows.
     * @param int    $offset     Pagination offset.
     *
     * @return TalkLink[] Ordered by linked_at DESC.
     */
    public function findByObjectUuid(string $objectUuid, int $limit = 20, int $offset = 0): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
            ->orderBy('linked_at', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        return $this->findEntities(query: $qb);
    }//end findByObjectUuid()

    /**
     * Count Talk links for an OR object UUID.
     *
     * @param string $objectUuid The object UUID.
     *
     * @return int Total number of links.
     */
    public function countByObjectUuid(string $objectUuid): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'count'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));

        $result = $qb->executeQuery();
        $count  = (int) $result->fetchOne();
        $result->closeCursor();

        return $count;
    }//end countByObjectUuid()

    /**
     * Find a specific link by object UUID and conversation token.
     *
     * @param string $objectUuid        The object UUID.
     * @param string $conversationToken The Talk room token.
     *
     * @return TalkLink|null The link or null when not found.
     */
    public function findByObjectAndToken(string $objectUuid, string $conversationToken): ?TalkLink
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
            ->andWhere($qb->expr()->eq('conversation_token', $qb->createNamedParameter($conversationToken)));

        try {
            return $this->findEntity(query: $qb);
        } catch (DoesNotExistException $e) {
            return null;
        }//end try
    }//end findByObjectAndToken()

}//end class
