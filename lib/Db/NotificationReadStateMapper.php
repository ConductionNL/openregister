<?php

/**
 * NotificationReadStateMapper.
 *
 * DB-backed implementation of the read/unread tracking contract first
 * defined by the in-memory `NotificationReadState` primitive. Same
 * semantics:
 *   - markRead(userId, notificationId) is idempotent
 *   - markUnread reverses it
 *   - isRead is per-(userId, notificationId)
 *
 * Backed by the `oc_openregister_notification_readstate` table created
 * by `Version1Date20260502190000`. The unique index on
 * (user_id, notification_id) enforces idempotency at the DB layer
 * (INSERT race conditions surface as unique-violation, which we map to
 * "already read").
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/notificatie-engine/tasks.md "Read/unread tracking MUST be maintained per user per notification"
 *
 * @template-extends QBMapper<NotificationReadStateEntity>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * DB-backed read-state mapper. Mirrors NotificationReadState's contract.
 */
class NotificationReadStateMapper extends QBMapper
{
    /**
     * Constructor.
     *
     * @param IDBConnection $db The DB connection.
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(
            db: $db,
            tableName: 'openregister_notification_readstate',
            entityClass: NotificationReadStateEntity::class
        );

    }//end __construct()

    /**
     * Mark a notification as read for a user. Idempotent — calling
     * twice with the same (userId, notificationId) does not throw and
     * does not update `read_at` on the second call.
     *
     * @param string $userId         The user UID.
     * @param string $notificationId The notification id.
     *
     * @return NotificationReadStateEntity The entity (existing or newly inserted).
     */
    public function markRead(string $userId, string $notificationId): NotificationReadStateEntity
    {
        try {
            return $this->findByUserAndNotification(userId: $userId, notificationId: $notificationId);
        } catch (DoesNotExistException) {
            // Fall through to insert.
        }

        $entity = new NotificationReadStateEntity();
        $entity->setUserId($userId);
        $entity->setNotificationId($notificationId);
        $entity->setReadAt(new DateTime());

        try {
            /** @var NotificationReadStateEntity $inserted */
            $inserted = $this->insert($entity);
            return $inserted;
        } catch (DbException $e) {
            // Race: another request inserted between the find and insert.
            // The unique index protects us — return the existing row.
            return $this->findByUserAndNotification(userId: $userId, notificationId: $notificationId);
        }

    }//end markRead()

    /**
     * Reverse a markRead — bring the notification back to unread.
     *
     * @param string $userId         The user UID.
     * @param string $notificationId The notification id.
     *
     * @return bool True when a row was deleted, false when nothing existed.
     */
    public function markUnread(string $userId, string $notificationId): bool
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where(
                $qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
            )
            ->andWhere(
                $qb->expr()->eq('notification_id', $qb->createNamedParameter($notificationId, IQueryBuilder::PARAM_STR))
            );

        return ($qb->executeStatement() > 0);

    }//end markUnread()

    /**
     * Test whether a notification has been read by the user.
     *
     * @param string $userId         The user UID.
     * @param string $notificationId The notification id.
     *
     * @return bool
     */
    public function isRead(string $userId, string $notificationId): bool
    {
        try {
            $this->findByUserAndNotification(userId: $userId, notificationId: $notificationId);
            return true;
        } catch (DoesNotExistException) {
            return false;
        }

    }//end isRead()

    /**
     * Number of read tuples currently tracked across all users.
     *
     * @return int
     */
    public function readCount(): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->getTableName());
        $result = $qb->executeQuery();
        $value  = (int) $result->fetchOne();
        $result->closeCursor();
        return $value;

    }//end readCount()

    /**
     * Find the row for a given (userId, notificationId) tuple.
     *
     * @param string $userId         The user UID.
     * @param string $notificationId The notification id.
     *
     * @return NotificationReadStateEntity
     *
     * @throws DoesNotExistException When no row exists.
     */
    private function findByUserAndNotification(string $userId, string $notificationId): NotificationReadStateEntity
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
            )
            ->andWhere(
                $qb->expr()->eq('notification_id', $qb->createNamedParameter($notificationId, IQueryBuilder::PARAM_STR))
            )
            ->setMaxResults(1);

        /** @var NotificationReadStateEntity $entity */
        $entity = $this->findEntity(query: $qb);
        return $entity;

    }//end findByUserAndNotification()
}//end class
