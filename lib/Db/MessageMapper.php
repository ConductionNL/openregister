<?php
/**
 * OpenRegister Message Mapper
 *
 * This file contains the MessageMapper class for database operations on messages.
 *
 * @category Mapper
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class MessageMapper
 *
 * @package OCA\OpenRegister\Db
 *
 * @template-extends QBMapper<Message>
 * @method           Message insert(Entity $entity)
 * @method           Message update(Entity $entity)
 * @method           Message insertOrUpdate(Entity $entity)
 * @method           Message delete(Entity $entity)
 * @method           Message find(int|string $id)
 * @method           Message findEntity(IQueryBuilder $query)
 * @method           Message[] findAll(int|null $limit = null, int|null $offset = null)
 * @method           list<Message> findEntities(IQueryBuilder $query)
 */
class MessageMapper extends QBMapper
{


    /**
     * MessageMapper constructor.
     *
     * @param IDBConnection $db Database connection instance
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'openregister_messages', Message::class);

    }//end __construct()


    /**
     * Find a message by its ID
     *
     * @param int $id Message ID
     *
     * @return Message The message entity
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function find(int $id): Message
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);

    }//end find()


    /**
     * Find a message by its UUID
     *
     * @param string $uuid Message UUID
     *
     * @return Message The message entity
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function findByUuid(string $uuid): Message
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)));

        return $this->findEntity($qb);

    }//end findByUuid()


    /**
     * Find all messages in a conversation
     *
     * @param int $conversationId Conversation ID
     * @param int $limit          Maximum number of results
     * @param int $offset         Offset for pagination
     *
     * @return Message[] Array of Message entities
     *
     * @psalm-return array<Message>
     */
    public function findByConversation(
        int $conversationId,
        int $limit=100,
        int $offset=0
    ): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('conversation_id', $qb->createNamedParameter($conversationId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        return $this->findEntities($qb);

    }//end findByConversation()


    /**
     * Find recent messages in a conversation
     *
     * Gets the most recent N messages from a conversation.
     *
     * @param int $conversationId Conversation ID
     * @param int $limit          Number of recent messages to get
     *
     * @return Message[] Array of Message entities (oldest first)
     *
     * @psalm-return array<Message>
     */
    public function findRecentByConversation(int $conversationId, int $limit=10): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('conversation_id', $qb->createNamedParameter($conversationId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created', 'DESC')
            ->setMaxResults($limit);

        $messages = $this->findEntities($qb);

        // Reverse to get oldest-first order.
        return array_reverse($messages);

    }//end findRecentByConversation()


    /**
     * Count messages in a conversation
     *
     * @param int $conversationId Conversation ID
     *
     * @return int Total message count
     */
    public function countByConversation(int $conversationId): int
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->func()->count('*', 'count'))
            ->from($this->tableName)
            ->where($qb->expr()->eq('conversation_id', $qb->createNamedParameter($conversationId, IQueryBuilder::PARAM_INT)));

        $result = $qb->execute();
        $count  = (int) $result->fetchOne();
        $result->closeCursor();

        return $count;

    }//end countByConversation()


    /**
     * Count messages by role in a conversation
     *
     * @param int    $conversationId Conversation ID
     * @param string $role           Message role (user or assistant)
     *
     * @return int Message count for role
     */
    public function countByRole(int $conversationId, string $role): int
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->func()->count('*', 'count'))
            ->from($this->tableName)
            ->where($qb->expr()->eq('conversation_id', $qb->createNamedParameter($conversationId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('role', $qb->createNamedParameter($role, IQueryBuilder::PARAM_STR)));

        $result = $qb->execute();
        $count  = (int) $result->fetchOne();
        $result->closeCursor();

        return $count;

    }//end countByRole()


    /**
     * Get the first message in a conversation
     *
     * Useful for generating conversation titles from the first user message.
     *
     * @param int $conversationId Conversation ID
     *
     * @return Message|null The first message or null if conversation is empty
     */
    public function findFirstMessage(int $conversationId): ?Message
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('conversation_id', $qb->createNamedParameter($conversationId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created', 'ASC')
            ->setMaxResults(1);

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }

    }//end findFirstMessage()


    /**
     * Delete all messages in a conversation
     *
     * Used when hard-deleting a conversation.
     *
     * @param int $conversationId Conversation ID
     *
     * @return \OCP\DB\IResult|int Number of messages deleted
     */
    public function deleteByConversation(int $conversationId): int|\OCP\DB\IResult
    {
        $qb = $this->db->getQueryBuilder();

        $qb->delete($this->tableName)
            ->where($qb->expr()->eq('conversation_id', $qb->createNamedParameter($conversationId, IQueryBuilder::PARAM_INT)));

        return $qb->execute();

    }//end deleteByConversation()


}//end class
