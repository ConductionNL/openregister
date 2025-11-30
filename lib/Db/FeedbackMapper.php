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
 * @extends QBMapper<Feedback>
 */
class FeedbackMapper extends QBMapper
{



    /**
     * Override insert to generate UUID and timestamps
     *
     * @param Entity $entity Entity to insert
     *
     * @return Feedback Inserted entity
     * @psalm-return Feedback
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
     * @return Feedback Updated entity
     * @psalm-return Feedback
     */
    public function update(Entity $entity): Feedback
    {
        $entity->setUpdated(new \DateTime());
        return parent::update($entity);

    }//end update()



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
