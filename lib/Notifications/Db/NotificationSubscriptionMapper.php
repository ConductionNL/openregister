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
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md "Users MUST be able to manage their notification preferences"
 *
 * @template-extends QBMapper<NotificationSubscription>
 *
 * @deprecated Superseded by override-only user-config notification preferences
 *             (NotificationPreferenceService). Rows are migrated by the
 *             MigrateNotificationSubscriptionsToUserConfig repair step and the
 *             table is scheduled for removal.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Db;

use DateTime;
use InvalidArgumentException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

class NotificationSubscriptionMapper extends QBMapper {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: 'openregister_notification_subscriptions',
			entityClass: NotificationSubscription::class
		);

	}//end __construct()

	/**
	 * Subscribe a user to (register, schema). Idempotent: returns the existing
	 * row when a duplicate is attempted (the DB unique index protects against
	 * races). At least one of registerId / schemaId MUST be set.
	 *
	 * @param SubscriptionKey $key The user and the register and/or schema.
	 *
	 * @return NotificationSubscription
	 *
	 * @throws \InvalidArgumentException When both ids are null.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function subscribeTo(SubscriptionKey $key): NotificationSubscription {
		if ($key->registerId === null && $key->schemaId === null) {
			throw new InvalidArgumentException(
				'subscribe() requires at least one of registerId / schemaId'
			);
		}

		try {
			return $this->findExisting(key: $key);
		} catch (DoesNotExistException) {
			// Fall through to insert.
		}

		$entity = new NotificationSubscription();
		$entity->setUserId($key->userId);
		$entity->setRegisterId($key->registerId);
		$entity->setSchemaId($key->schemaId);
		$entity->setCreated(new DateTime());

		try {
			// insert() fills in the id on the entity it is given and returns that same entity.
			$this->insert(entity: $entity);
			return $entity;
		} catch (DbException) {
			// Race: parallel insert won. Return the existing row.
			return $this->findExisting(key: $key);
		}

	}//end subscribeTo()

	/**
	 * Unsubscribe one (user, register, schema) subscription.
	 *
	 * @param SubscriptionKey $key The user and the register and/or schema.
	 *
	 * @return bool True when a row was deleted.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function unsubscribeFrom(SubscriptionKey $key): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where(
				$qb->expr()->eq('user_id', $qb->createNamedParameter($key->userId, IQueryBuilder::PARAM_STR))
			);

		$this->whereNullableEquals(qb: $qb, columns: $key->scopeColumns());

		return ($qb->executeStatement() > 0);
	}//end unsubscribeFrom()

	/**
	 * Find subscriptions for a user.
	 *
	 * @param string $userId User UID.
	 *
	 * @return NotificationSubscription[]
	 */
	public function findByUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
			)
			->orderBy('created', 'DESC');

		// The findEntities() helper is typed Entity[] on the parent; this mapper's
		// generic binding narrows it to NotificationSubscription at runtime. The
		// annotation has to be a `/** */` docblock for PHPStan to honour it — the
		// `/* */` one that used to sit here was inert — which is why the inline
		// doc-block sniff is silenced for this single line rather than the type
		// being left wrong.
		// phpcs:ignore Squiz.Commenting.InlineComment.DocBlock -- PHPStan only reads @var from a /** */ block.
		/** @var NotificationSubscription[] $rows */
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
	 * @param int $schemaId Schema id of the firing event.
	 *
	 * @return string[] List of user UIDs.
	 */
	public function findSubscribedUids(int $registerId, int $schemaId): array {
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
		$uids = [];
		while (($row = $result->fetch()) !== false) {
			$uids[] = (string)$row['user_id'];
		}

		$result->closeCursor();
		return $uids;
	}//end findSubscribedUids()

	/**
	 * Find an existing row for the (user, register, schema) tuple.
	 *
	 * @param SubscriptionKey $key The user, register and schema.
	 *
	 * @return NotificationSubscription
	 *
	 * @throws DoesNotExistException When no row exists.
	 */
	private function findExisting(SubscriptionKey $key): NotificationSubscription {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('user_id', $qb->createNamedParameter($key->userId, IQueryBuilder::PARAM_STR))
			);
		$this->whereNullableEquals(qb: $qb, columns: $key->scopeColumns());
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
	 * @param IQueryBuilder           $qb      The query builder.
	 * @param array<string, int|null> $columns Column to value, null for an IS NULL match.
	 *
	 * @return void
	 */
	private function whereNullableEquals(IQueryBuilder $qb, array $columns): void {
		foreach ($columns as $column => $value) {
			if ($value === null) {
				$qb->andWhere($qb->expr()->isNull($column));
				continue;
			}

			$qb->andWhere(
				$qb->expr()->eq($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT))
			);
		}

	}//end whereNullableEquals()
}//end class
