<?php

/**
 * NotificationHistoryMapper
 *
 * Mapper for NotificationHistory entities.
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
 * @spec openspec/changes/notificatie-engine/tasks.md#task-9
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for the oc_openregister_notification_history table.
 *
 * @method NotificationHistory insert(NotificationHistory $entity)
 * @method NotificationHistory update(NotificationHistory $entity)
 * @method NotificationHistory findEntity(IQueryBuilder $query)
 * @method list<NotificationHistory> findEntities(IQueryBuilder $query)
 *
 * @template-extends QBMapper<NotificationHistory>
 *
 * @psalm-suppress UnusedClass
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
     * Record a dispatch attempt.
     *
     * @param string      $ruleId     Rule identifier.
     * @param string      $channel    Delivery channel.
     * @param string      $recipient  Recipient identifier.
     * @param string      $status     One of: dispatched / rate-limited / coalesced / failed.
     * @param string|null $objectUuid Object UUID (optional).
     * @param string|null $schemaId   Schema identifier (optional).
     * @param string|null $registerId Register identifier (optional).
     *
     * @return NotificationHistory
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-9
     */
    public function record(
        string $ruleId,
        string $channel,
        string $recipient,
        string $status='dispatched',
        ?string $objectUuid=null,
        ?string $schemaId=null,
        ?string $registerId=null
    ): NotificationHistory {
        $entry = new NotificationHistory();
        $entry->setRuleId(ruleId: $ruleId);
        $entry->setChannel(channel: $channel);
        $entry->setRecipient(recipient: $recipient);
        $entry->setStatus(status: $status);
        $entry->setObjectUuid(objectUuid: $objectUuid);
        $entry->setSchemaId(schemaId: $schemaId);
        $entry->setRegisterId(registerId: $registerId);
        $entry->setDispatchedAt(dispatchedAt: new DateTime());

        return $this->insert(entity: $entry);
    }//end record()

    /**
     * Find history rows with optional filters, paginated.
     *
     * @param string|null $ruleId     Filter by rule.
     * @param string|null $channel    Filter by channel.
     * @param string|null $recipient  Filter by recipient.
     * @param string|null $objectUuid Filter by object UUID.
     * @param string|null $schemaId   Filter by schema.
     * @param string|null $registerId Filter by register.
     * @param string|null $status     Filter by status.
     * @param int         $limit      Max rows (default 50, max 500).
     * @param int         $offset     Row offset.
     *
     * @return list<NotificationHistory>
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-9
     */
    public function findFiltered(
        ?string $ruleId=null,
        ?string $channel=null,
        ?string $recipient=null,
        ?string $objectUuid=null,
        ?string $schemaId=null,
        ?string $registerId=null,
        ?string $status=null,
        int $limit=50,
        int $offset=0
    ): array {
        $safeLimit = min(value: max(value: 1, value2: $limit), value2: 500);

        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName());

        $this->applyOptionalFilter(qb: $qb, column: 'rule_id', value: $ruleId);
        $this->applyOptionalFilter(qb: $qb, column: 'channel', value: $channel);
        $this->applyOptionalFilter(qb: $qb, column: 'recipient', value: $recipient);
        $this->applyOptionalFilter(qb: $qb, column: 'object_uuid', value: $objectUuid);
        $this->applyOptionalFilter(qb: $qb, column: 'schema_id', value: $schemaId);
        $this->applyOptionalFilter(qb: $qb, column: 'register_id', value: $registerId);
        $this->applyOptionalFilter(qb: $qb, column: 'status', value: $status);

        $qb->orderBy(sort: 'dispatched_at', order: 'DESC')
            ->setMaxResults(maxResults: $safeLimit)
            ->setFirstResult(firstResult: $offset);

        return $this->findEntities(query: $qb);
    }//end findFiltered()

    /**
     * Count history rows with optional filters.
     *
     * @param string|null $ruleId     Filter by rule.
     * @param string|null $channel    Filter by channel.
     * @param string|null $recipient  Filter by recipient.
     * @param string|null $objectUuid Filter by object UUID.
     * @param string|null $schemaId   Filter by schema.
     * @param string|null $registerId Filter by register.
     * @param string|null $status     Filter by status.
     *
     * @return int
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-9
     */
    public function countFiltered(
        ?string $ruleId=null,
        ?string $channel=null,
        ?string $recipient=null,
        ?string $objectUuid=null,
        ?string $schemaId=null,
        ?string $registerId=null,
        ?string $status=null
    ): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: $qb->createFunction(call: 'COUNT(*) AS count'))
            ->from(from: $this->getTableName());

        $this->applyOptionalFilter(qb: $qb, column: 'rule_id', value: $ruleId);
        $this->applyOptionalFilter(qb: $qb, column: 'channel', value: $channel);
        $this->applyOptionalFilter(qb: $qb, column: 'recipient', value: $recipient);
        $this->applyOptionalFilter(qb: $qb, column: 'object_uuid', value: $objectUuid);
        $this->applyOptionalFilter(qb: $qb, column: 'schema_id', value: $schemaId);
        $this->applyOptionalFilter(qb: $qb, column: 'register_id', value: $registerId);
        $this->applyOptionalFilter(qb: $qb, column: 'status', value: $status);

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return (int) ($row['count'] ?? 0);
    }//end countFiltered()

    /**
     * Apply an optional equality filter to a query builder.
     *
     * @param IQueryBuilder $qb     Query builder instance.
     * @param string        $column Column name.
     * @param string|null   $value  Filter value (null = skip).
     *
     * @return void
     */
    private function applyOptionalFilter(IQueryBuilder $qb, string $column, ?string $value): void
    {
        if ($value === null) {
            return;
        }

        $qb->andWhere(
            $qb->expr()->eq(
                x: $column,
                y: $qb->createNamedParameter(value: $value)
            )
        );
    }//end applyOptionalFilter()
}//end class
