<?php

/**
 * Mapper for NotificationHistory entities.
 *
 * Provides the persistence + query API for the notification audit
 * trail. Closes the `notificatie-engine` spec's
 * "Notification history MUST be stored and queryable for audit
 * purposes" requirement together with the
 * `Version1Date20260501100000` migration + the `NotificationHistory`
 * entity + the `NotificationHistoryController` REST endpoint.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class NotificationHistoryMapper.
 *
 * @method NotificationHistory insert(Entity $entity)
 * @method NotificationHistory update(Entity $entity)
 * @method NotificationHistory delete(Entity $entity)
 *
 * @template-extends QBMapper<NotificationHistory>
 *
 * @psalm-suppress PossiblyUnusedMethod
 */
class NotificationHistoryMapper extends QBMapper
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
            tableName: 'openregister_notification_history',
            entityClass: NotificationHistory::class
        );

    }//end __construct()

    /**
     * Record a notification dispatch.
     *
     * Convenience wrapper around `insert()` that takes plain scalars
     * instead of an entity. Used by `AnnotationNotificationDispatcher`
     * which already has the values laid out as named arguments.
     *
     * @param string      $ruleId       The annotation key.
     * @param string      $channel      The channel that fired.
     * @param string      $recipient    Recipient identifier (uid or `__webhook__`/`__talk__`).
     * @param string      $status       `dispatched` | `rate-limited` | `failed`.
     * @param string|null $schemaId     Schema id.
     * @param string|null $registerId   Register id.
     * @param string|null $objectUuid   Object uuid.
     * @param string|null $subject      Interpolated subject.
     * @param string|null $errorMessage Error message when status is `failed`.
     * @param string|null $locale       Recipient locale (null for broadcast).
     *
     * @return NotificationHistory The persisted row.
     */
    public function record(
        string $ruleId,
        string $channel,
        string $recipient,
        string $status,
        ?string $schemaId=null,
        ?string $registerId=null,
        ?string $objectUuid=null,
        ?string $subject=null,
        ?string $errorMessage=null,
        ?string $locale=null
    ): NotificationHistory {
        $entity = new NotificationHistory();
        $entity->setRuleId($ruleId);
        $entity->setChannel($channel);
        $entity->setRecipient($recipient);
        $entity->setStatus($status);
        $entity->setSchemaId($schemaId);
        $entity->setRegisterId($registerId);
        $entity->setObjectUuid($objectUuid);
        $entity->setSubject($subject);
        $entity->setErrorMessage($errorMessage);
        $entity->setLocale($locale);
        $entity->setDispatchedAt(new DateTime());

        return $this->insert(entity: $entity);

    }//end record()

    /**
     * Find history rows matching the supplied filters.
     *
     * Supported filters: `ruleId`, `channel`, `recipient`, `objectUuid`,
     * `schemaId`, `registerId`, `status`. Unknown keys are silently
     * ignored. All filters are AND-combined.
     *
     * @param array<string, string|null> $filters Filter map.
     * @param int|null                   $limit   Result limit.
     * @param int|null                   $offset  Result offset.
     *
     * @return array<int, NotificationHistory>
     */
    public function findFiltered(array $filters=[], ?int $limit=null, ?int $offset=null): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName());

        $columnMap = [
            'ruleId'     => 'rule_id',
            'channel'    => 'channel',
            'recipient'  => 'recipient',
            'objectUuid' => 'object_uuid',
            'schemaId'   => 'schema_id',
            'registerId' => 'register_id',
            'status'     => 'status',
        ];

        foreach ($columnMap as $filterKey => $column) {
            if (array_key_exists($filterKey, $filters) === false) {
                continue;
            }

            $value = $filters[$filterKey];
            if ($value === null || $value === '') {
                continue;
            }

            $qb->andWhere(
                $qb->expr()->eq($column, $qb->createNamedParameter((string) $value))
            );
        }

        $qb->orderBy('dispatched_at', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        return $this->findEntities(query: $qb);

    }//end findFiltered()

    /**
     * Count rows matching the same filters as `findFiltered()`.
     *
     * @param array<string, string|null> $filters Filter map.
     *
     * @return int Row count.
     */
    public function countFiltered(array $filters=[]): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->getTableName());

        $columnMap = [
            'ruleId'     => 'rule_id',
            'channel'    => 'channel',
            'recipient'  => 'recipient',
            'objectUuid' => 'object_uuid',
            'schemaId'   => 'schema_id',
            'registerId' => 'register_id',
            'status'     => 'status',
        ];

        foreach ($columnMap as $filterKey => $column) {
            if (array_key_exists($filterKey, $filters) === false) {
                continue;
            }

            $value = $filters[$filterKey];
            if ($value === null || $value === '') {
                continue;
            }

            $qb->andWhere(
                $qb->expr()->eq($column, $qb->createNamedParameter((string) $value))
            );
        }

        $result = $qb->executeQuery();
        $count  = (int) $result->fetchOne();
        $result->closeCursor();

        return $count;

    }//end countFiltered()
}//end class
