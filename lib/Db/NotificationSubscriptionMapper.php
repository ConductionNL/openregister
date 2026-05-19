<?php

/**
 * NotificationSubscriptionMapper
 *
 * Mapper for NotificationSubscription entities.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/notificatie-engine/tasks.md#task-7
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for the oc_openregister_notification_subscriptions table.
 *
 * @method NotificationSubscription insert(NotificationSubscription $entity)
 * @method NotificationSubscription update(NotificationSubscription $entity)
 * @method NotificationSubscription findEntity(IQueryBuilder $query)
 * @method list<NotificationSubscription> findEntities(IQueryBuilder $query)
 *
 * @template-extends QBMapper<NotificationSubscription>
 *
 * @psalm-suppress UnusedClass
 */
class NotificationSubscriptionMapper extends QBMapper
{
    /**
     * Constructor.
     *
     * @param IDBConnection $db Database connection.
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(
            db: $db,
            tableName: 'openregister_notification_subscriptions',
            entityClass: NotificationSubscription::class
        );
    }//end __construct()

    /**
     * Subscribe a user to a register/schema. Idempotent.
     *
     * @param string      $userId     Nextcloud user identifier.
     * @param string|null $registerId Register identifier (optional).
     * @param string|null $schemaId   Schema identifier (optional).
     *
     * @return NotificationSubscription
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-7
     */
    public function subscribe(string $userId, ?string $registerId=null, ?string $schemaId=null): NotificationSubscription
    {
        // Check if subscription already exists.
        try {
            return $this->findExact(userId: $userId, registerId: $registerId, schemaId: $schemaId);
        } catch (DoesNotExistException $e) {
            // Does not exist — create it.
        }

        $sub = new NotificationSubscription();
        $sub->setUserId(userId: $userId);
        $sub->setRegisterId(registerId: $registerId);
        $sub->setSchemaId(schemaId: $schemaId);
        $sub->setCreatedAt(createdAt: new DateTime());

        return $this->insert(entity: $sub);
    }//end subscribe()

    /**
     * Unsubscribe a user.
     *
     * @param string      $userId     User identifier.
     * @param string|null $registerId Register identifier (optional).
     * @param string|null $schemaId   Schema identifier (optional).
     *
     * @return bool True if a row was deleted.
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-7
     */
    public function unsubscribe(string $userId, ?string $registerId=null, ?string $schemaId=null): bool
    {
        try {
            $sub = $this->findExact(userId: $userId, registerId: $registerId, schemaId: $schemaId);
            $this->delete(entity: $sub);
            return true;
        } catch (DoesNotExistException $e) {
            return false;
        }
    }//end unsubscribe()

    /**
     * Find all subscriptions for a user.
     *
     * @param string $userId User identifier.
     *
     * @return list<NotificationSubscription>
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-7
     */
    public function findByUser(string $userId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'user_id',
                    y: $qb->createNamedParameter(value: $userId)
                )
            );

        return $this->findEntities(query: $qb);
    }//end findByUser()

    /**
     * Find UIDs subscribed to a (registerId, schemaId) pair.
     *
     * Matches:
     * - Exact (registerId, schemaId) row
     * - Register-wide row (registerId matches, schemaId IS NULL)
     * - Cross-register schema row (schemaId matches, registerId IS NULL)
     *
     * @param string|null $registerId Register identifier.
     * @param string|null $schemaId   Schema identifier.
     *
     * @return list<string> List of user IDs.
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-7
     */
    public function findSubscribedUids(?string $registerId=null, ?string $schemaId=null): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct(column: 'user_id')
            ->from(from: $this->getTableName());

        $conditions = [];

        // Exact match.
        if ($registerId !== null && $schemaId !== null) {
            $conditions[] = $qb->expr()->andX(
                $qb->expr()->eq(x: 'register_id', y: $qb->createNamedParameter(value: $registerId)),
                $qb->expr()->eq(x: 'schema_id', y: $qb->createNamedParameter(value: $schemaId))
            );
        }

        // Register-wide.
        if ($registerId !== null) {
            $conditions[] = $qb->expr()->andX(
                $qb->expr()->eq(x: 'register_id', y: $qb->createNamedParameter(value: $registerId)),
                $qb->expr()->isNull(field: 'schema_id')
            );
        }

        // Cross-register schema.
        if ($schemaId !== null) {
            $conditions[] = $qb->expr()->andX(
                $qb->expr()->isNull(field: 'register_id'),
                $qb->expr()->eq(x: 'schema_id', y: $qb->createNamedParameter(value: $schemaId))
            );
        }

        if (empty($conditions) === false) {
            $qb->where($qb->expr()->orX(...$conditions));
        }

        $result = $qb->executeQuery();
        $rows   = $result->fetchAll();
        $result->closeCursor();

        return array_map(callback: static fn(array $row): string => (string) $row['user_id'], array: $rows);
    }//end findSubscribedUids()

    /**
     * Find an exact subscription match.
     *
     * @param string      $userId     User identifier.
     * @param string|null $registerId Register identifier.
     * @param string|null $schemaId   Schema identifier.
     *
     * @return NotificationSubscription
     *
     * @throws DoesNotExistException When no matching row exists.
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-7
     */
    private function findExact(string $userId, ?string $registerId, ?string $schemaId): NotificationSubscription
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'user_id',
                    y: $qb->createNamedParameter(value: $userId)
                )
            );

        if ($registerId !== null) {
            $qb->andWhere(
                $qb->expr()->eq(
                    x: 'register_id',
                    y: $qb->createNamedParameter(value: $registerId)
                )
            );
        } else {
            $qb->andWhere($qb->expr()->isNull(field: 'register_id'));
        }

        if ($schemaId !== null) {
            $qb->andWhere(
                $qb->expr()->eq(
                    x: 'schema_id',
                    y: $qb->createNamedParameter(value: $schemaId)
                )
            );
        } else {
            $qb->andWhere($qb->expr()->isNull(field: 'schema_id'));
        }

        return $this->findEntity(query: $qb);
    }//end findExact()
}//end class
