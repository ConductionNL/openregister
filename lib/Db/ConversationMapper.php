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
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class ConversationMapper
 *
 * @package OCA\OpenRegister\Db
 *
 * @template-extends QBMapper<Conversation>
 *
 * @psalm-suppress MissingTemplateParam
 */
class ConversationMapper extends QBMapper
{

    /**
     * ConversationMapper constructor.
     *
     * @param IDBConnection $db Database connection instance
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'openregister_conversations', Conversation::class);

    }//end __construct()


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
     * @param string   $userId         User ID
     * @param int|null $organisation   Optional organisation filter
     * @param bool     $includeDeleted Whether to include soft-deleted conversations
     * @param int      $limit          Maximum number of results
     * @param int      $offset         Offset for pagination
     *
     * @return array Array of Conversation entities
     */
    public function findByUser(
        string $userId,
        ?int $organisation = null,
        bool $includeDeleted = false,
        int $limit = 50,
        int $offset = 0
    ): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));

        // Filter by organisation if provided
        if ($organisation !== null) {
            $qb->andWhere($qb->expr()->eq('organisation', $qb->createNamedParameter($organisation, IQueryBuilder::PARAM_INT)));
        }

        // Exclude soft-deleted conversations unless requested
        if (!$includeDeleted) {
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
     * @param string   $userId       User ID
     * @param int|null $organisation Optional organisation filter
     * @param int      $limit        Maximum number of results
     * @param int      $offset       Offset for pagination
     *
     * @return array Array of Conversation entities
     */
    public function findDeletedByUser(
        string $userId,
        ?int $organisation = null,
        int $limit = 50,
        int $offset = 0
    ): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->isNotNull('deleted_at'));

        // Filter by organisation if provided
        if ($organisation !== null) {
            $qb->andWhere($qb->expr()->eq('organisation', $qb->createNamedParameter($organisation, IQueryBuilder::PARAM_INT)));
        }

        $qb->orderBy('deleted_at', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        return $this->findEntities($qb);

    }//end findDeletedByUser()


    /**
     * Find all conversations using a specific agent
     *
     * @param int  $agentId Agent ID
     * @param bool $includeDeleted Whether to include soft-deleted conversations
     * @param int  $limit   Maximum number of results
     * @param int  $offset  Offset for pagination
     *
     * @return array Array of Conversation entities
     */
    public function findByAgent(
        int $agentId,
        bool $includeDeleted = false,
        int $limit = 50,
        int $offset = 0
    ): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('agent_id', $qb->createNamedParameter($agentId, IQueryBuilder::PARAM_INT)));

        // Exclude soft-deleted conversations unless requested
        if (!$includeDeleted) {
            $qb->andWhere($qb->expr()->isNull('deleted_at'));
        }

        $qb->orderBy('updated', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        return $this->findEntities($qb);

    }//end findByAgent()


    /**
     * Find conversations by organisation
     *
     * @param int  $organisation   Organisation ID
     * @param bool $includeDeleted Whether to include soft-deleted conversations
     * @param int  $limit          Maximum number of results
     * @param int  $offset         Offset for pagination
     *
     * @return array Array of Conversation entities
     */
    public function findByOrganisation(
        int $organisation,
        bool $includeDeleted = false,
        int $limit = 50,
        int $offset = 0
    ): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->tableName)
            ->where($qb->expr()->eq('organisation', $qb->createNamedParameter($organisation, IQueryBuilder::PARAM_INT)));

        // Exclude soft-deleted conversations unless requested
        if (!$includeDeleted) {
            $qb->andWhere($qb->expr()->isNull('deleted_at'));
        }

        $qb->orderBy('updated', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        return $this->findEntities($qb);

    }//end findByOrganisation()


    /**
     * Count conversations for a user
     *
     * @param string   $userId         User ID
     * @param int|null $organisation   Optional organisation filter
     * @param bool     $includeDeleted Whether to include soft-deleted conversations
     *
     * @return int Total count
     */
    public function countByUser(
        string $userId,
        ?int $organisation = null,
        bool $includeDeleted = false
    ): int {
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->func()->count('*', 'count'))
            ->from($this->tableName)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));

        // Filter by organisation if provided
        if ($organisation !== null) {
            $qb->andWhere($qb->expr()->eq('organisation', $qb->createNamedParameter($organisation, IQueryBuilder::PARAM_INT)));
        }

        // Exclude soft-deleted conversations unless requested
        if (!$includeDeleted) {
            $qb->andWhere($qb->expr()->isNull('deleted_at'));
        }

        $result = $qb->execute();
        $count = (int) $result->fetchOne();
        $result->closeCursor();

        return $count;

    }//end countByUser()


    /**
     * Soft delete a conversation
     *
     * @param int $id Conversation ID
     *
     * @return Conversation The updated conversation entity
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function softDelete(int $id): Conversation
    {
        $conversation = $this->find($id);
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
     * Hard delete old soft-deleted conversations
     *
     * Permanently removes conversations that have been soft-deleted
     * for more than the specified number of days.
     *
     * @param int $daysOld Number of days old (default: 30)
     *
     * @return int Number of conversations deleted
     */
    public function cleanupOldDeleted(int $daysOld = 30): int
    {
        $threshold = new DateTime("-{$daysOld} days");
        
        $qb = $this->db->getQueryBuilder();
        
        $qb->delete($this->tableName)
            ->where($qb->expr()->isNotNull('deleted_at'))
            ->andWhere(
                $qb->expr()->lt(
                    'deleted_at',
                    $qb->createNamedParameter($threshold, IQueryBuilder::PARAM_DATE)
                )
            );

        return $qb->execute();

    }//end cleanupOldDeleted()


    /**
     * Check if user can access a conversation
     *
     * Access rules:
     * - User must be the owner of the conversation
     * - Conversation must belong to the user's current organisation (if provided)
     *
     * @param Conversation $conversation    Conversation entity
     * @param string       $userId          User ID
     * @param int|null     $organisationId  Current organisation ID (optional)
     *
     * @return bool True if user can access
     */
    public function canUserAccessConversation(Conversation $conversation, string $userId, ?int $organisationId = null): bool
    {
        // User must be the owner
        if ($conversation->getUserId() !== $userId) {
            return false;
        }

        // If organisation is provided, conversation must belong to it
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


