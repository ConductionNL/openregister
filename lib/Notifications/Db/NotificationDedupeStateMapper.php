<?php

/**
 * Mapper for NotificationDedupeState entities.
 *
 * Provides the per-object dedup state API used by
 * `ScheduledNotificationJob` to decide whether a scheduled-notification
 * rule should fire for a given object. The core operation is
 * `upsert()` — write-or-update on the (schema_id, rule_key, object_uuid)
 * triple — paired with `findByKey()` for the lookup that drives
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

namespace OCA\OpenRegister\Notifications\Db;

use DateTime;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

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
class NotificationDedupeStateMapper extends QBMapper {
	use NotificationQueryTrait;


	/**
	 * Default retention window for the periodic sweep (90 days).
	 */
	public const DEFAULT_RETENTION_DAYS = 90;

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: 'openregister_notification_dedupe',
			entityClass: NotificationDedupeState::class
		);

	}//end __construct()

	/**
	 * Look up the dedup row of one key.
	 *
	 * @param NotificationDedupeKey $key The schema, rule and object.
	 *
	 * @return NotificationDedupeState|null Row when present, null when absent.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function findByKey(NotificationDedupeKey $key): ?NotificationDedupeState {
		$qb = $this->selectWhereEquals(columns: ['schema_id' => $key->schemaId, 'rule_key' => $key->ruleKey, 'object_uuid' => $key->objectUuid]);
		$qb->setMaxResults(1);

		try {
			$found = $this->findEntity(query: $qb);
		} catch (\Throwable $e) {
			// Missing rows and read failures alike should not break the dispatch loop.
			return null;
		}

		if ($found instanceof NotificationDedupeState) {
			return $found;
		}

		return null;
	}//end findByKey()

	/**
	 * Insert-or-update the dedup row for one triple.
	 *
	 * When the sighting dispatched, the row's `dispatched_at` is updated to
	 * the sighting's `now` (initial dispatch or fingerprint-change re-arm).
	 * Otherwise only `seen_at` is touched (the engine matched again but the
	 * fingerprint has not changed, so no dispatch happened).
	 *
	 * @param NotificationDedupeKey $key      The schema, rule and object the row belongs to.
	 * @param DedupeSighting        $sighting The fingerprint, the moment (UTC) and whether it dispatched.
	 *
	 * @return NotificationDedupeState The persisted entity.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function upsert(NotificationDedupeKey $key, DedupeSighting $sighting): NotificationDedupeState {
		$existing = $this->findByKey(key: $key);
		if ($existing !== null) {
			return $this->refresh(existing: $existing, sighting: $sighting);
		}

		return $this->insertOrReadWinner(entity: $this->newState(key: $key, sighting: $sighting), key: $key);
	}//end upsert()


	/**
	 * A new row, seen and dispatched now.
	 *
	 * @param NotificationDedupeKey $key      The schema, rule and object.
	 * @param DedupeSighting        $sighting The fingerprint and the evaluation moment.
	 *
	 * @return NotificationDedupeState The unsaved row.
	 */
	private function newState(NotificationDedupeKey $key, DedupeSighting $sighting): NotificationDedupeState {
		$entity = new NotificationDedupeState();
		$entity->setSchemaId($key->schemaId);
		$entity->setRuleKey($key->ruleKey);
		$entity->setObjectUuid($key->objectUuid);
		$entity->setFingerprint($sighting->fingerprint);
		$entity->setDispatchedAt($sighting->now);
		$entity->setSeenAt($sighting->now);

		return $entity;

	}//end newState()


	/**
	 * Move an existing row on: new fingerprint, seen now, dispatched now when it was.
	 *
	 * @param NotificationDedupeState $existing The stored row.
	 * @param DedupeSighting          $sighting The new fingerprint, the evaluation moment and whether it dispatched.
	 *
	 * @return NotificationDedupeState The updated row, or the row as changed when the update failed.
	 */
	private function refresh(NotificationDedupeState $existing, DedupeSighting $sighting): NotificationDedupeState {
		$existing->setFingerprint($sighting->fingerprint);
		$existing->setSeenAt($sighting->now);
		if ($sighting->dispatched === true) {
			$existing->setDispatchedAt($sighting->now);
		}

		try {
			return $this->update(entity: $existing);
		} catch (\Throwable $e) {
			return $existing;
		}

	}//end refresh()

	/**
	 * Insert a new row, or answer with the one a concurrent dispatcher wrote.
	 *
	 * @param NotificationDedupeState $entity The row to insert.
	 * @param NotificationDedupeKey   $key    Its schema, rule and object.
	 *
	 * @return NotificationDedupeState The stored row.
	 *
	 * @throws DbException When the insert fails for another reason, or no winning row exists.
	 */
	private function insertOrReadWinner(NotificationDedupeState $entity, NotificationDedupeKey $key): NotificationDedupeState {
		try {
			return $this->insert(entity: $entity);
		} catch (DbException $e) {
			if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}

			// Concurrent dispatcher won the race — re-read and treat the
			// row as authoritative.
			return ($this->findByKey(key: $key) ?? throw $e);
		}

	}//end insertOrReadWinner()

	/**
	 * Drop every dedup row tied to an object (used on object purge).
	 *
	 * @param string $objectUuid Target ObjectEntity UUID.
	 *
	 * @return int Number of rows deleted (0 on error).
	 */
	public function deleteByObject(string $objectUuid): int {
		return $this->executeQuietly(statement: fn (): IQueryBuilder => $this->deleteWhereEquals(columns: ['object_uuid' => $objectUuid]));
	}//end deleteByObject()

	/**
	 * Drop every dedup row for a (schema, rule) pair (used on annotation save).
	 *
	 * @param int $schemaId Schema identifier.
	 * @param string $ruleKey Notification annotation key.
	 *
	 * @return int Number of rows deleted (0 on error).
	 */
	public function deleteByRule(int $schemaId, string $ruleKey): int {
		return $this->executeQuietly(
			statement: fn (): IQueryBuilder => $this->deleteWhereEquals(columns: ['schema_id' => $schemaId, 'rule_key' => $ruleKey])
		);
	}//end deleteByRule()

	/**
	 * Retention sweep: drop rows last seen before the cutoff.
	 *
	 * @param DateTime $cutoff Drop rows whose `seen_at` is strictly before this.
	 *
	 * @return int Number of rows deleted (0 on error).
	 */
	public function deleteSeenBefore(DateTime $cutoff): int {
		return $this->deleteBeforeQuietly(column: 'seen_at', cutoff: $cutoff->format('Y-m-d H:i:s'));
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
	public function listRuleKeysForSchema(int $schemaId): array {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->selectDistinct('rule_key')
				->from($this->getTableName());
			$this->whereEquals(qb: $qb, columns: ['schema_id' => $schemaId]);

			$result = $qb->executeQuery();
			$keys = array_map(static fn (array $row): string => (string)($row['rule_key'] ?? ''), $result->fetchAll());
			$result->closeCursor();

			return $keys;
		} catch (\Throwable) {
			return [];
		}//end try
	}//end listRuleKeysForSchema()
}//end class
