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
 * MessageMapper handles database operations for Message entities
 *
 * Mapper for Message entities to handle database operations on chat messages.
 * Extends QBMapper to provide standard CRUD operations for conversation messages.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @template-extends QBMapper<Message>
 * @method           Message insert(Entity $entity)
 * @method           Message update(Entity $entity)
 * @method           Message insertOrUpdate(Entity $entity)
 * @method           Message delete(Entity $entity)
 * @method           Message find(int|string $id)
 * @method           Message findEntity(IQueryBuilder $query)
 * @method           Message[] findAll(int|null $limit=null, int|null $offset=null)
 * @method           list<Message> findEntities(IQueryBuilder $query)
 */
class MessageMapper extends QBMapper
{


    /**
     * Constructor
     *
     * Initializes mapper with database connection.
     * Calls parent constructor to set up base mapper functionality.
     *
     * @param IDBConnection $db Database connection
     *
     * @return void
     */
    public function __construct(IDBConnection $db)
    {
        // Call parent constructor to initialize base mapper with table name and entity class.
        parent::__construct($db, 'openregister_messages', Message::class);

    }//end __construct()


    /**
     * Find a message by its ID
     *
     * Retrieves message entity by ID. Throws exception if message not found.
     *
     * @param int $id Message ID to find
     *
     * @return Message The found message entity
     *
     * @throws DoesNotExistException If message not found
     * @throws MultipleObjectsReturnedException If multiple messages found (should not happen)
     */
    public function find(int $id): Message
    {
        // Step 1: Get query builder instance.
        $qb = $this->db->getQueryBuilder();

        // Step 2: Build SELECT query with ID filter.
        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        // Step 3: Execute query and return single entity.
        return $this->findEntity($qb);

    }//end find()


    /**
     * Find all messages in a conversation
     *
     * Retrieves all messages for a specific conversation with pagination support.
     * Results are ordered by creation date ascending (oldest first) for chronological display.
     *
     * @param int $conversationId Conversation ID to filter messages by
     * @param int $limit          Maximum number of results to return (default: 100)
     * @param int $offset         Offset for pagination (default: 0)
     *
     * @return Message[]
     *
     * @psalm-return list<\OCA\OpenRegister\Db\Message>
     */
    public function findByConversation(
        int $conversationId,
        int $limit=100,
        int $offset=0
    ): array {
        // Step 1: Get query builder instance.
        $qb = $this->db->getQueryBuilder();

        // Step 2: Build SELECT query with conversation ID filter.
        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('conversation_id', $qb->createNamedParameter($conversationId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        // Step 3: Execute query and return entities.
        return $this->findEntities($qb);

    }//end findByConversation()


    /**
     * Find recent messages in a conversation
     *
     * Gets the most recent N messages from a conversation and returns them
     * in chronological order (oldest first) for display purposes.
     *
     * @param int $conversationId Conversation ID to filter messages by
     * @param int $limit          Number of recent messages to get (default: 10)
     *
     * @return Message[]
     *
     * @psalm-return list<\OCA\OpenRegister\Db\Message>
     */
    public function findRecentByConversation(int $conversationId, int $limit=10): array
    {
        // Step 1: Get query builder instance.
        $qb = $this->db->getQueryBuilder();

        // Step 2: Build SELECT query with conversation ID filter.
        // Order by created DESC to get newest messages first.
        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('conversation_id', $qb->createNamedParameter($conversationId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created', 'DESC')
            ->setMaxResults($limit);

        // Step 3: Execute query to get newest messages first.
        $messages = $this->findEntities($qb);

        // Step 4: Reverse array to get oldest-first order for display.
        // This ensures messages appear in chronological order in UI.
        return array_reverse($messages);

    }//end findRecentByConversation()


    /**
     * Count messages in a conversation
     *
     * Counts total number of messages in a specific conversation.
     * Useful for pagination and statistics.
     *
     * @param int $conversationId Conversation ID to count messages for
     *
     * @return int Total message count (0 or positive integer)
     */
    public function countByConversation(int $conversationId): int
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->func()->count('*', 'count'))
            ->from($this->tableName)
            ->where($qb->expr()->eq('conversation_id', $qb->createNamedParameter($conversationId, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $count  = (int) $result->fetchOne();
        $result->closeCursor();

        return $count;

    }//end countByConversation()


    /**
     * Delete all messages in a conversation
     *
     * Used when hard-deleting a conversation.
     *
     * @param int $conversationId Conversation ID
     *
     * @return \OCP\DB\IResult|int Number of messages deleted
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function deleteByConversation(int $conversationId): int|\OCP\DB\IResult
    {
        $qb = $this->db->getQueryBuilder();

        $qb->delete($this->tableName)
            ->where($qb->expr()->eq('conversation_id', $qb->createNamedParameter($conversationId, IQueryBuilder::PARAM_INT)));

        return $qb->executeStatement();

    }//end deleteByConversation()


}//end class
