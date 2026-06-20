<?php

/**
 * Mapper for NotificationDedupeState entities.
 *
 * Provides the per-object dedup state API used by
 * `ScheduledNotificationJob` to decide whether a scheduled-notification
 * rule should fire for a given object. The core operation is
 * `upsert()` — write-or-update on the (schema_id, rule_key, object_uuid)
 * triple — paired with `findOne()` for the lookup that drives
 * fingerprint comparison.
 *
 * Pruning is best-effort: `deleteByObject()` on object purge,
 * `deleteByRule()` on annotation save with removed rule keys, and
 * `deleteSeenBefore()` for the retention sweep run inside the job.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
use DateTimeZone;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class NotificationDedupeStateMapper.
 *
 * @method NotificationDedupeState insert(Entity $entity)
 * @method NotificationDedupeState update(Entity $entity)
 * @method NotificationDedupeState delete(Entity $entity)
 *
 * @template-extends QBMapper<NotificationDedupeState>
 *
 * @psalm-suppress PossiblyUnusedMethod
 */
class NotificationDedupeStateMapper extends QBMapper
{

    /**
     * Default retention window for the periodic sweep (90 days).
     */
    public const DEFAULT_RETENTION_DAYS = 90;

    /**
     * Constructor.
     *
     * @param IDBConnection $db Database connection.
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(
            db: $db,
            tableName: 'openregister_notification_dedupe',
            entityClass: NotificationDedupeState::class
        );

    }//end __construct()

    /**
     * Look up the dedup row for a single (schema, rule, object) triple.
     *
     * @param int    $schemaId   Schema identifier.
     * @param string $ruleKey    Notification annotation key.
     * @param string $objectUuid Target ObjectEntity UUID.
     *
     * @return NotificationDedupeState|null Row when present, null when absent.
     */
    public function findOne(int $schemaId, string $ruleKey, string $objectUuid): ?NotificationDedupeState
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq(
                    'schema_id',
                    $qb->createNamedParameter($schemaId, IQueryBuilder::PARAM_INT)
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    'rule_key',
                    $qb->createNamedParameter($ruleKey)
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    'object_uuid',
                    $qb->createNamedParameter($objectUuid)
                )
            )
            ->setMaxResults(1);

        try {
            return $this->findEntity(query: $qb);
        } catch (DoesNotExistException $e) {
            return null;
        } catch (\Throwable $e) {
            // Read failures should not break the dispatch loop.
            return null;
        }

    }//end findOne()

    /**
     * Insert-or-update the dedup row for one triple.
     *
     * When `$dispatched` is true the row's `dispatched_at` is updated to the
     * given `$now` (initial dispatch or fingerprint-change re-arm). Otherwise
     * only `seen_at` is touched (the engine matched again but the fingerprint
     * has not changed, so no dispatch happened).
     *
     * @param int      $schemaId    Schema identifier.
     * @param string   $ruleKey     Notification annotation key.
     * @param string   $objectUuid  Target ObjectEntity UUID.
     * @param string   $fingerprint SHA-1 of watched-field values.
     * @param DateTime $now         Logical "now" for the scan pass (UTC).
     * @param bool     $dispatched  True when this call recorded an actual dispatch.
     *
     * @return NotificationDedupeState The persisted entity.
     */
    public function upsert(
        int $schemaId,
        string $ruleKey,
        string $objectUuid,
        string $fingerprint,
        DateTime $now,
        bool $dispatched
    ): NotificationDedupeState {
        $existing = $this->findOne(schemaId: $schemaId, ruleKey: $ruleKey, objectUuid: $objectUuid);

        if ($existing !== null) {
            $existing->setFingerprint($fingerprint);
            $existing->setSeenAt($now);
            if ($dispatched === true) {
                $existing->setDispatchedAt($now);
            }

            try {
                return $this->update($existing);
            } catch (\Throwable $e) {
                return $existing;
            }
        }

        $entity = new NotificationDedupeState();
        $entity->setSchemaId($schemaId);
        $entity->setRuleKey($ruleKey);
        $entity->setObjectUuid($objectUuid);
        $entity->setFingerprint($fingerprint);
        $entity->setDispatchedAt($now);
        $entity->setSeenAt($now);

        try {
            return $this->insert($entity);
        } catch (DbException $e) {
            // Concurrent dispatcher won the race — re-read and treat the
            // row as authoritative.
            if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                $existing = $this->findOne(
                    schemaId: $schemaId,
                    ruleKey: $ruleKey,
                    objectUuid: $objectUuid
                );
                if ($existing !== null) {
                    return $existing;
                }
            }

            throw $e;
        }

    }//end upsert()

    /**
     * Drop every dedup row tied to an object (used on object purge).
     *
     * @param string $objectUuid Target ObjectEntity UUID.
     *
     * @return int Number of rows deleted (0 on error).
     */
    public function deleteByObject(string $objectUuid): int
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($this->getTableName())
                ->where(
                    $qb->expr()->eq(
                        'object_uuid',
                        $qb->createNamedParameter($objectUuid)
                    )
                );
            return (int) $qb->executeStatement();
        } catch (\Throwable) {
            return 0;
        }

    }//end deleteByObject()

    /**
     * Drop every dedup row for a (schema, rule) pair (used on annotation save).
     *
     * @param int    $schemaId Schema identifier.
     * @param string $ruleKey  Notification annotation key.
     *
     * @return int Number of rows deleted (0 on error).
     */
    public function deleteByRule(int $schemaId, string $ruleKey): int
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($this->getTableName())
                ->where(
                    $qb->expr()->eq(
                        'schema_id',
                        $qb->createNamedParameter($schemaId, IQueryBuilder::PARAM_INT)
                    )
                )
                ->andWhere(
                    $qb->expr()->eq(
                        'rule_key',
                        $qb->createNamedParameter($ruleKey)
                    )
                );
            return (int) $qb->executeStatement();
        } catch (\Throwable) {
            return 0;
        }

    }//end deleteByRule()

    /**
     * Retention sweep: drop rows last seen before the cutoff.
     *
     * @param DateTime $cutoff Drop rows whose `seen_at` is strictly before this.
     *
     * @return int Number of rows deleted (0 on error).
     */
    public function deleteSeenBefore(DateTime $cutoff): int
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($this->getTableName())
                ->where(
                    $qb->expr()->lt(
                        'seen_at',
                        $qb->createNamedParameter(
                            $cutoff->format('Y-m-d H:i:s'),
                            IQueryBuilder::PARAM_STR
                        )
                    )
                );
            return (int) $qb->executeStatement();
        } catch (\Throwable) {
            return 0;
        }

    }//end deleteSeenBefore()

    /**
     * List every rule_key currently tracked for a schema.
     *
     * Used by the annotation-save diff path: callers compute the set of
     * rule keys currently in `x-openregister-notifications`, intersect
     * with this list, and `deleteByRule()` the difference.
     *
     * @param int $schemaId Schema identifier.
     *
     * @return array<int, string> Distinct rule keys.
     */
    public function listRuleKeysForSchema(int $schemaId): array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->selectDistinct('rule_key')
                ->from($this->getTableName())
                ->where(
                    $qb->expr()->eq(
                        'schema_id',
                        $qb->createNamedParameter($schemaId, IQueryBuilder::PARAM_INT)
                    )
                );

            $result = $qb->executeQuery();
            $keys   = [];
            while (($row = $result->fetch()) !== false) {
                $keys[] = (string) ($row['rule_key'] ?? '');
            }

            $result->closeCursor();
            return $keys;
        } catch (\Throwable) {
            return [];
        }//end try

    }//end listRuleKeysForSchema()
}//end class
