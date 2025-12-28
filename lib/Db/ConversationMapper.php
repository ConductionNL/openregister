<?php

/**
 * OpenRegister Conversation Mapper
 *
 * This file contains the ConversationMapper class for database operations on conversations.
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

use DateTime;
use OCA\OpenRegister\Event\ConversationCreatedEvent;
use OCA\OpenRegister\Event\ConversationDeletedEvent;
use OCA\OpenRegister\Event\ConversationUpdatedEvent;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;

/**
 * ConversationMapper handles database operations for Conversation entities
 *
 * Mapper for Conversation entities to handle database operations.
 * Extends QBMapper to provide standard CRUD operations with event dispatching.
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
 * @template-extends QBMapper<Conversation>
 * @method           Conversation insert(Entity $entity)
 * @method           Conversation update(Entity $entity)
 * @method           Conversation insertOrUpdate(Entity $entity)
 * @method           Conversation delete(Entity $entity)
 * @method           Conversation find(int|string $id)
 * @method           Conversation findEntity(IQueryBuilder $query)
 * @method           Conversation[] findAll(int|null $limit=null, int|null $offset=null)
 * @method           list<Conversation> findEntities(IQueryBuilder $query)
 */
class ConversationMapper extends QBMapper
{
    /**
     * Event dispatcher for dispatching conversation events
     *
     * Used to dispatch ConversationCreatedEvent, ConversationUpdatedEvent,
     * and ConversationDeletedEvent for event-driven architecture.
     *
     * @var IEventDispatcher Event dispatcher instance
     */
    private readonly IEventDispatcher $eventDispatcher;

    /**
     * Constructor
     *
     * Initializes mapper with database connection and event dispatcher.
     * Calls parent constructor to set up base mapper functionality.
     *
     * @param IDBConnection    $db              Database connection
     * @param IEventDispatcher $eventDispatcher Event dispatcher for conversation lifecycle events
     *
     * @return void
     */
    public function __construct(
        IDBConnection $db,
        IEventDispatcher $eventDispatcher
    ) {
        // Call parent constructor to initialize base mapper with table name and entity class.
        parent::__construct($db, 'openregister_conversations', Conversation::class);

        // Store event dispatcher for use in CRUD operations.
        $this->eventDispatcher = $eventDispatcher;
    }//end __construct()

    /**
     * Insert a new conversation entity
     *
     * Inserts conversation entity into database with automatic UUID and timestamp
     * generation. Dispatches ConversationCreatedEvent after successful insertion.
     *
     * @param Entity $entity The conversation entity to insert
     *
     * @return Conversation The inserted conversation entity with database-generated ID
     */
    public function insert(Entity $entity): Conversation
    {
        if ($entity instanceof Conversation) {
            // Step 1: Ensure UUID is set (generate if missing or empty).
            $uuid = $entity->getUuid();
            if (($uuid === null || $uuid === '') || trim($uuid) === '') {
                $newUuid = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
                $entity->setUuid($newUuid);
            }

            // Step 2: Set created timestamp if not already set.
            if ($entity->getCreated() === null) {
                $entity->setCreated(new DateTime());
            }

            // Step 3: Set updated timestamp if not already set.
            if ($entity->getUpdated() === null) {
                $entity->setUpdated(new DateTime());
            }
        }

        // Step 4: Insert entity into database using parent method.
        $entity = parent::insert($entity);

        // Step 5: Dispatch creation event for event-driven architecture.
        // Listeners can react to conversation creation (e.g., notifications, logging).
        $this->eventDispatcher->dispatchTyped(new ConversationCreatedEvent($entity));

        return $entity;
    }//end insert()

    /**
     * Update a conversation entity
     *
     * Updates conversation entity in database with automatic updated timestamp.
     * Dispatches ConversationUpdatedEvent with both old and new entity states.
     *
     * @param Entity $entity The conversation entity to update
     *
     * @return Conversation The updated conversation entity
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If conversation not found
     */
    public function update(Entity $entity): Conversation
    {
        // Step 1: Get old state before update for event payload.
        // This allows listeners to compare old and new values.
        $oldEntity = $this->find(id: $entity->getId());

        if ($entity instanceof Conversation) {
            // Step 2: Always update the updated timestamp to current time.
            $entity->setUpdated(new DateTime());
        }

        // Step 3: Update entity in database using parent method.
        $entity = parent::update($entity);

        // Step 4: Dispatch update event with old and new entity states.
        // Listeners can react to conversation updates (e.g., cache invalidation, notifications).
        $this->eventDispatcher->dispatchTyped(new ConversationUpdatedEvent($entity, $oldEntity));

        return $entity;
    }//end update()

    /**
     * Delete a conversation entity
     *
     * @param Entity $entity The conversation entity to delete
     *
     * @return Conversation The deleted conversation entity
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function delete(Entity $entity): Conversation
    {
        $entity = parent::delete($entity);

        // Dispatch deletion event.
        $this->eventDispatcher->dispatchTyped(new ConversationDeletedEvent($entity));

        return $entity;
    }//end delete()

    /**
     * Find a conversation by its ID
     *
     * @param int $id Conversation ID
     *
     * @return Conversation The conversation entity
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function find(int $id): Conversation
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);
    }//end find()

    /**
     * Find a conversation by its UUID
     *
     * @param string $uuid Conversation UUID
     *
     * @return Conversation The conversation entity
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function findByUuid(string $uuid): Conversation
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)));

        return $this->findEntity($qb);
    }//end findByUuid()

    /**
     * Find all conversations for a user
     *
     * @param string      $userId         User ID
     * @param string|null $organisation   Optional organisation UUID filter
     * @param bool        $includeDeleted Whether to include soft-deleted conversations
     * @param int         $limit          Maximum number of results
     * @param int         $offset         Offset for pagination
     *
     * @return Conversation[]
     *
     * @psalm-return list<\OCA\OpenRegister\Db\Conversation>
     */
    public function findByUser(
        string $userId,
        ?string $organisation = null,
        bool $includeDeleted = false,
        int $limit = 50,
        int $offset = 0
    ): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));

        // Filter by organisation if provided.
        if ($organisation !== null) {
            $qb->andWhere($qb->expr()->eq('organisation', $qb->createNamedParameter($organisation, IQueryBuilder::PARAM_STR)));
        }

        // Exclude soft-deleted conversations unless requested.
        if ($includeDeleted === false) {
            $qb->andWhere($qb->expr()->isNull('deleted_at'));
        }

        $qb->orderBy('updated', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        return $this->findEntities($qb);
    }//end findByUser()

    /**
     * Find all soft-deleted conversations for a user (archive)
     *
     * @param string      $userId       User ID
     * @param null|string $organisation Optional organisation filter
     * @param int         $limit        Maximum number of results
     * @param int         $offset       Offset for pagination
     *
     * @return Conversation[]
     *
     * @psalm-return list<\OCA\OpenRegister\Db\Conversation>
     */
    public function findDeletedByUser(
        string $userId,
        ?string $organisation = null,
        int $limit = 50,
        int $offset = 0
    ): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->isNotNull('deleted_at'));

        // Filter by organisation if provided.
        if ($organisation !== null) {
            $qb->andWhere($qb->expr()->eq('organisation', $qb->createNamedParameter($organisation, IQueryBuilder::PARAM_STR)));
        }

        $qb->orderBy('deleted_at', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        return $this->findEntities($qb);
    }//end findDeletedByUser()

    /**
     * Find conversations by user and agent with matching title pattern
     *
     * Used to check for duplicate conversation names and generate unique titles.
     *
     * @param string $userId       User ID
     * @param int    $agentId      Agent ID
     * @param string $titlePattern Title pattern to match (e.g., "New Conversation%")
     *
     * @return array Array of matching conversation titles
     *
     * @psalm-return list<mixed>
     */
    public function findTitlesByUserAgent(
        string $userId,
        int $agentId,
        string $titlePattern
    ): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('title')
            ->from($this->tableName)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('agent_id', $qb->createNamedParameter($agentId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->like('title', $qb->createNamedParameter($titlePattern, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->isNull('deleted_at'));
        // Only active conversations.
        $result = $qb->executeQuery();
        $titles = [];

        while (($row = $result->fetch()) !== false) {
            if ($row['title'] !== null) {
                $titles[] = $row['title'];
            }
        }

        $result->closeCursor();

        return $titles;
    }//end findTitlesByUserAgent()

    /**
     * Count conversations for a user
     *
     * @param string      $userId         User ID
     * @param string|null $organisation   Optional organisation UUID filter
     * @param bool        $includeDeleted Whether to include soft-deleted conversations
     *
     * @return int Total count
     */
    public function countByUser(
        string $userId,
        ?string $organisation = null,
        bool $includeDeleted = false
    ): int {
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->func()->count('*', 'count'))
            ->from($this->tableName)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));

        // Filter by organisation if provided.
        if ($organisation !== null) {
            $qb->andWhere($qb->expr()->eq('organisation', $qb->createNamedParameter($organisation, IQueryBuilder::PARAM_STR)));
        }

        // Exclude soft-deleted conversations unless requested.
        if ($includeDeleted === false) {
            $qb->andWhere($qb->expr()->isNull('deleted_at'));
        }

        $result = $qb->executeQuery();
        $count  = (int) $result->fetchOne();
        $result->closeCursor();

        return $count;
    }//end countByUser()

    /**
     * Count soft-deleted conversations for a user (archived)
     *
     * @param string      $userId       User ID
     * @param string|null $organisation Optional organisation filter
     *
     * @return int Count of archived conversations
     */
    public function countDeletedByUser(
        string $userId,
        ?string $organisation = null
    ): int {
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->func()->count('*', 'count'))
            ->from($this->tableName)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->isNotNull('deleted_at'));

        // Filter by organisation if provided.
        if ($organisation !== null) {
            $qb->andWhere($qb->expr()->eq('organisation', $qb->createNamedParameter($organisation, IQueryBuilder::PARAM_STR)));
        }

        $result = $qb->executeQuery();
        $count  = (int) $result->fetchOne();
        $result->closeCursor();

        return $count;
    }//end countDeletedByUser()

    /**
     * Soft delete a conversation
     *
     * @param int $id Conversation ID
     *
     * @return Conversation The updated conversation entity
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function softDelete(int $id): Conversation
    {
        $conversation = $this->find(id: $id);
        $conversation->softDelete();
        $conversation->setUpdated(new DateTime());

        return $this->update($conversation);
    }//end softDelete()

    /**
     * Restore a soft-deleted conversation
     *
     * @param int $id Conversation ID
     *
     * @return Conversation The updated conversation entity
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function restore(int $id): Conversation
    {
        $conversation = $this->find($id);
        $conversation->restore();
        $conversation->setUpdated(new DateTime());

        return $this->update($conversation);
    }//end restore()

    /**
     * Check if user can access a conversation
     *
     * Access rules:
     * - User must be the owner of the conversation
     * - Conversation must belong to the user's current organisation (if provided)
     *
     * @param Conversation $conversation     Conversation entity
     * @param string       $userId           User ID
     * @param string|null  $organisationUuid Current organisation UUID (optional)
     *
     * @return bool True if user can access
     */
    public function canUserAccessConversation(Conversation $conversation, string $userId, ?string $organisationUuid = null): bool
    {
        // User must be the owner.
        if ($conversation->getUserId() !== $userId) {
            return false;
        }

        // If organisation is provided, rbac: conversation must belong to it.
        if ($organisationUuid !== null && $conversation->getOrganisation() !== $organisationUuid) {
            return false;
        }

        return true;
    }//end canUserAccessConversation()

    /**
     * Check if user can modify a conversation
     *
     * Modification rules:
     * - User must be the owner of the conversation
     *
     * @param Conversation $conversation Conversation entity
     * @param string       $userId       User ID
     *
     * @return bool True if user can modify
     */
    public function canUserModifyConversation(Conversation $conversation, string $userId): bool
    {
        return $conversation->getUserId() === $userId;
    }//end canUserModifyConversation()
}//end class
