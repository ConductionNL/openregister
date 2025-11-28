<?php
/**
 * OpenRegister Feedback Mapper
 *
 * Mapper for Feedback entities to handle database operations.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class FeedbackMapper
 *
 * @package OCA\OpenRegister\Db
 *
 * @template-extends QBMapper<Feedback>
 *
 * @method Feedback insert(Entity $entity)
 * @method Feedback update(Entity $entity)
 * @method Feedback insertOrUpdate(Entity $entity)
 * @method Feedback delete(Entity $entity)
 * @method Feedback find(int|string $id)
 * @method Feedback findEntity(IQueryBuilder $query)
 * @method Feedback[] findAll(int|null $limit = null, int|null $offset = null)
 * @method list<Feedback> findEntities(IQueryBuilder $query)
 *
 * @extends        QBMapper<Feedback>
 * @psalm-suppress LessSpecificImplementedReturnType - @method annotation is correct, parent returns list<T>
 */
class FeedbackMapper extends QBMapper
{


    /**
     * Constructor for FeedbackMapper
     *
     * @param IDBConnection $db Database connection
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'openregister_feedback', Feedback::class);

    }//end __construct()


    /**
     * Override insert to generate UUID and timestamps
     *
     * @param Entity $entity Entity to insert
     *
     * @return         Feedback Inserted entity
     * @psalm-suppress LessSpecificImplementedReturnType - QBMapper returns more specific type
     */
    public function insert(Entity $entity): Feedback
    {
        // Generate UUID if not set.
        if (empty($entity->getUuid()) === true) {
            $entity->setUuid(\Symfony\Component\Uid\Uuid::v4()->toRfc4122());
        }

        // Set timestamps.
        $now = new \DateTime();
        if ($entity->getCreated() === null) {
            $entity->setCreated($now);
        }

        $entity->setUpdated($now);

        return parent::insert($entity);

    }//end insert()


    /**
     * Override update to set updated timestamp
     *
     * @param Entity $entity Entity to update
     *
     * @return         Feedback Updated entity
     * @psalm-suppress LessSpecificImplementedReturnType - QBMapper returns more specific type
     */
    public function update(Entity $entity): Feedback
    {
        $entity->setUpdated(new \DateTime());
        return parent::update($entity);

    }//end update()


    /**
     * Find feedback by UUID
     *
     * @param string $uuid UUID to search for
     *
     * @return Feedback Found feedback entity
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function findByUuid(string $uuid): Feedback
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)));

        return $this->findEntity($qb);

    }//end findByUuid()


    /**
     * Find feedback for a specific message
     *
     * @param int    $messageId Message ID
     * @param string $userId    User ID (to ensure user can only see their own feedback)
     *
     * @return Feedback|null
     */
    public function findByMessage(int $messageId, string $userId): ?Feedback
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('message_id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }

    }//end findByMessage()


    /**
     * Find all feedback for a conversation
     *
     * @param int $conversationId Conversation ID
     *
     * @return array<Feedback>
     */
    public function findByConversation(int $conversationId): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('conversation_id', $qb->createNamedParameter($conversationId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created', 'DESC');

        return $this->findEntities($qb);

    }//end findByConversation()


    /**
     * Find all feedback for an agent
     *
     * @param int $agentId Agent ID
     * @param int $limit   Limit
     * @param int $offset  Offset
     *
     * @return array<Feedback>
     */
    public function findByAgent(int $agentId, int $limit=100, int $offset=0): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('agent_id', $qb->createNamedParameter($agentId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        return $this->findEntities($qb);

    }//end findByAgent()


    /**
     * Count feedback by agent and type
     *
     * @param int         $agentId Agent ID
     * @param string|null $type    'positive' or 'negative' (null for all)
     *
     * @return int
     */
    public function countByAgent(int $agentId, ?string $type=null): int
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->func()->count('*', 'count'))
            ->from($this->tableName)
            ->where($qb->expr()->eq('agent_id', $qb->createNamedParameter($agentId, IQueryBuilder::PARAM_INT)));

        if ($type !== null) {
            $qb->andWhere($qb->expr()->eq('type', $qb->createNamedParameter($type, IQueryBuilder::PARAM_STR)));
        }

        $result = $qb->execute();
        $count  = (int) $result->fetchOne();
        $result->closeCursor();

        return $count;

    }//end countByAgent()


    /**
     * Find all feedback by user
     *
     * @param string      $userId       User ID
     * @param string|null $organisation Organisation UUID
     * @param int         $limit        Limit
     * @param int         $offset       Offset
     *
     * @return array<Feedback>
     */
    public function findByUser(string $userId, ?string $organisation=null, int $limit=100, int $offset=0): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->orderBy('created', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($organisation !== null) {
            $qb->andWhere($qb->expr()->eq('organisation', $qb->createNamedParameter($organisation, IQueryBuilder::PARAM_STR)));
        }

        return $this->findEntities($qb);

    }//end findByUser()


    /**
     * Delete all feedback for a message
     *
     * @param int $messageId Message ID
     *
     * @return void
     */
    public function deleteByMessage(int $messageId): void
    {
        $qb = $this->db->getQueryBuilder();

        $qb->delete($this->tableName)
            ->where($qb->expr()->eq('message_id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)));

        $qb->execute();

    }//end deleteByMessage()


    /**
     * Delete all feedback for a conversation
     *
     * @param int $conversationId Conversation ID
     *
     * @return void
     */
    public function deleteByConversation(int $conversationId): void
    {
        $qb = $this->db->getQueryBuilder();

        $qb->delete($this->tableName)
            ->where($qb->expr()->eq('conversation_id', $qb->createNamedParameter($conversationId, IQueryBuilder::PARAM_INT)));

        $qb->execute();

    }//end deleteByConversation()


}//end class
