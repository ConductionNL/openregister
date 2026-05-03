<?php

/**
 * NotificationSubscriptionMapper.
 *
 * Per-user (register, schema) subscription store. Each row binds a
 * user UID to either a register, a schema, or both — the dispatcher
 * uses these rows to short-circuit recipient resolution to users who
 * have explicitly opted in.
 *
 * Idempotency is enforced at the DB layer via the unique index on
 * (user_id, register_id, schema_id) — `subscribe()` returns the
 * existing row on a duplicate insert.
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
 * @spec openspec/changes/notificatie-engine/tasks.md "Users MUST be able to manage their notification preferences"
 *
 * @template-extends QBMapper<NotificationSubscription>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use InvalidArgumentException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class NotificationSubscriptionMapper extends QBMapper
{
    /**
     * Constructor.
     *
     * @param IDBConnection $db Connection.
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
     * Subscribe a user to (register, schema). Idempotent: returns the
     * existing row when a duplicate is attempted (DB unique index
     * protects against races).
     *
     * At least one of registerId / schemaId MUST be set.
     *
     * @param string $userId     User UID.
     * @param ?int   $registerId Register id, or null for schema-only.
     * @param ?int   $schemaId   Schema id, or null for register-wide.
     *
     * @return NotificationSubscription
     *
     * @throws \InvalidArgumentException When both ids are null.
     */
    public function subscribe(string $userId, ?int $registerId, ?int $schemaId): NotificationSubscription
    {
        if ($registerId === null && $schemaId === null) {
            throw new InvalidArgumentException(
                'subscribe() requires at least one of registerId / schemaId'
            );
        }

        try {
            return $this->findExisting(userId: $userId, registerId: $registerId, schemaId: $schemaId);
        } catch (DoesNotExistException) {
            // Fall through to insert.
        }

        $entity = new NotificationSubscription();
        $entity->setUserId($userId);
        $entity->setRegisterId($registerId);
        $entity->setSchemaId($schemaId);
        $entity->setCreated(new DateTime());

        try {
            /*
             * @var NotificationSubscription $inserted
             */

            $inserted = $this->insert(entity: $entity);
            return $inserted;
        } catch (DbException) {
            // Race: parallel insert won. Return the existing row.
            return $this->findExisting(userId: $userId, registerId: $registerId, schemaId: $schemaId);
        }

    }//end subscribe()

    /**
     * Unsubscribe a (userId, registerId, schemaId) tuple.
     *
     * @param string $userId     User UID.
     * @param ?int   $registerId Register id.
     * @param ?int   $schemaId   Schema id.
     *
     * @return bool True when a row was deleted.
     */
    public function unsubscribe(string $userId, ?int $registerId, ?int $schemaId): bool
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where(
                $qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
            );

        $this->whereNullableEq(qb: $qb, column: 'register_id', value: $registerId);
        $this->whereNullableEq(qb: $qb, column: 'schema_id', value: $schemaId);

        return ($qb->executeStatement() > 0);

    }//end unsubscribe()

    /**
     * Find subscriptions for a user.
     *
     * @param string $userId User UID.
     *
     * @return NotificationSubscription[]
     */
    public function findByUser(string $userId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
            )
            ->orderBy('created', 'DESC');

        /*
         * @var NotificationSubscription[] $rows
         */

        $rows = $this->findEntities(query: $qb);
        return $rows;

    }//end findByUser()

    /**
     * Find user UIDs subscribed to a (register, schema). A user matches
     * when ANY of these rows exist for them:
     *   - exact (registerId, schemaId)
     *   - registerId set, schemaId NULL  (whole-register subscription)
     *   - registerId NULL, schemaId set  (cross-register schema subscription)
     *
     * @param int $registerId Register id of the firing event.
     * @param int $schemaId   Schema id of the firing event.
     *
     * @return string[] List of user UIDs.
     */
    public function findSubscribedUids(int $registerId, int $schemaId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('user_id')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->orX(
                    $qb->expr()->andX(
                        $qb->expr()->eq('register_id', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)),
                        $qb->expr()->eq('schema_id', $qb->createNamedParameter($schemaId, IQueryBuilder::PARAM_INT))
                    ),
                    $qb->expr()->andX(
                        $qb->expr()->eq('register_id', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)),
                        $qb->expr()->isNull('schema_id')
                    ),
                    $qb->expr()->andX(
                        $qb->expr()->isNull('register_id'),
                        $qb->expr()->eq('schema_id', $qb->createNamedParameter($schemaId, IQueryBuilder::PARAM_INT))
                    )
                )
            );

        $result = $qb->executeQuery();
        $uids   = [];
        while (($row = $result->fetch()) !== false) {
            $uids[] = (string) $row['user_id'];
        }

        $result->closeCursor();
        return $uids;

    }//end findSubscribedUids()

    /**
     * Find an existing row for the (user, register, schema) tuple.
     *
     * @param string $userId     User UID.
     * @param ?int   $registerId Register id.
     * @param ?int   $schemaId   Schema id.
     *
     * @return NotificationSubscription
     *
     * @throws DoesNotExistException When no row exists.
     */
    private function findExisting(string $userId, ?int $registerId, ?int $schemaId): NotificationSubscription
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
            );
        $this->whereNullableEq(qb: $qb, column: 'register_id', value: $registerId);
        $this->whereNullableEq(qb: $qb, column: 'schema_id', value: $schemaId);
        $qb->setMaxResults(1);

        /*
         * @var NotificationSubscription $entity
         */

        $entity = $this->findEntity(query: $qb);
        return $entity;

    }//end findExisting()

    /**
     * Add an `<column> = <value> OR <column> IS NULL` style match where
     * a NULL value MUST match an `IS NULL` column (DBs treat
     * NULL = NULL as false otherwise).
     *
     * @param IQueryBuilder $qb     The query builder.
     * @param string        $column The column name.
     * @param ?int          $value  The value, or null for IS NULL match.
     *
     * @return void
     */
    private function whereNullableEq(IQueryBuilder $qb, string $column, ?int $value): void
    {
        if ($value === null) {
            $qb->andWhere($qb->expr()->isNull($column));
            return;
        }

        $qb->andWhere(
            $qb->expr()->eq($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT))
        );

    }//end whereNullableEq()
}//end class
