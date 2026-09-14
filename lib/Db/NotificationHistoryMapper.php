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
class NotificationHistoryMapper extends QBMapper {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	/**
	 * Filter key to column, for the list and its count.
	 *
	 * ONE map, read by both `findFiltered()` and `countFiltered()`. It was two
	 * hand-kept copies until the subject axis was added; two copies of a filter
	 * map drift, and the way they drift is a page that is narrower than its own
	 * total, which reads as a paging bug and is not one.
	 *
	 * @var array<string, string>
	 */
	private const COLUMN_MAP = [
		'ruleId' => 'rule_id',
		'channel' => 'channel',
		'recipient' => 'recipient',
		'objectUuid' => 'object_uuid',
		'schemaId' => 'schema_id',
		'registerId' => 'register_id',
		'status' => 'status',
		'subjectType' => 'subject_type',
		'subjectId' => 'subject_id',
	];

	public function __construct(IDBConnection $db) {
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
	 * @param string $ruleId The annotation key.
	 * @param string $channel The channel that fired.
	 * @param string $recipient Recipient identifier (uid or `__webhook__`/`__talk__`).
	 * @param string $status `dispatched` | `rate-limited` | `failed`.
	 * @param string|null $schemaId Schema id.
	 * @param string|null $registerId Register id.
	 * @param string|null $objectUuid Object uuid.
	 * @param string|null $subject Interpolated subject.
	 * @param string|null $errorMessage Error message when status is `failed`.
	 * @param string|null $locale Recipient locale (null for broadcast).
	 * @param string|null $subjectType What the notice is about, as a list axis.
	 * @param string|null $subjectId The subject's own id, defaulting to the object uuid.
	 *
	 * @return NotificationHistory The persisted row.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
	 */
	public function record(
		string $ruleId,
		string $channel,
		string $recipient,
		string $status,
		?string $schemaId = null,
		?string $registerId = null,
		?string $objectUuid = null,
		?string $subject = null,
		?string $errorMessage = null,
		?string $locale = null,
		?string $subjectType = null,
		?string $subjectId = null,
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
		$entity->setSubjectType($subjectType);
		// A notice with no explicit subject is about the object it fired on, so
		// opening that object still clears it. Defaulting here rather than at
		// each call site is what keeps "the work clears the bell" true for
		// every rule that was written before the axis existed.
		$entity->setSubjectId(($subjectId ?? $objectUuid));
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
	 * @param int|null $limit Result limit.
	 * @param int|null $offset Result offset.
	 *
	 * @return array<int, NotificationHistory>
	 */
	public function findFiltered(array $filters = [], ?int $limit = null, ?int $offset = null, ?DateTime $asOf = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName());

		foreach (self::COLUMN_MAP as $filterKey => $column) {
			if (array_key_exists($filterKey, $filters) === false) {
				continue;
			}

			$value = $filters[$filterKey];
			if ($value === null || $value === '') {
				continue;
			}

			$qb->andWhere(
				$qb->expr()->eq($column, $qb->createNamedParameter((string)$value))
			);
		}

		$this->applyListStateFilters(qb: $qb, filters: $filters, asOf: ($asOf ?? new DateTime()));

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
	public function countFiltered(array $filters = [], ?DateTime $asOf = null): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from($this->getTableName());

		foreach (self::COLUMN_MAP as $filterKey => $column) {
			if (array_key_exists($filterKey, $filters) === false) {
				continue;
			}

			$value = $filters[$filterKey];
			if ($value === null || $value === '') {
				continue;
			}

			$qb->andWhere(
				$qb->expr()->eq($column, $qb->createNamedParameter((string)$value))
			);
		}

		$this->applyListStateFilters(qb: $qb, filters: $filters, asOf: ($asOf ?? new DateTime()));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}//end countFiltered()
	/**
	 * Apply the filters that decide whether a notice is IN the list at all.
	 *
	 * Three, and each one is a different claim:
	 *
	 *  - `unreadOnly` — `read_at IS NULL`. Reading is what the work clears.
	 *  - `includeArchived` — off by default. Archiving takes a notice out of
	 *    the list WITHOUT reading it, so it can never be expressed as a read.
	 *  - the snooze — a notice snoozed past `$asOf` is absent now and present
	 *    afterwards, still unread. `$asOf` is an argument rather than `now()`
	 *    so a test can prove both halves without sleeping through a day.
	 *
	 * They live here rather than in both callers because `findFiltered()` and
	 * `countFiltered()` must agree, and a page narrower than its own total is
	 * the shape they disagree in.
	 *
	 * @param IQueryBuilder $qb The query being built.
	 * @param array<string, mixed> $filters The filter map.
	 * @param DateTime $asOf The moment to judge a snooze against.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-read-state/specs/notificatie-engine/spec.md#requirement-a-notification-may-be-snoozed-or-archived-and-the-list-has-an-axis-req-ors-004
	 */
	private function applyListStateFilters(IQueryBuilder $qb, array $filters, DateTime $asOf): void {
		if (filter_var(($filters['unreadOnly'] ?? false), FILTER_VALIDATE_BOOLEAN) === true) {
			$qb->andWhere($qb->expr()->isNull('read_at'));
		}

		if (filter_var(($filters['includeArchived'] ?? false), FILTER_VALIDATE_BOOLEAN) === false) {
			$qb->andWhere($qb->expr()->isNull('archived_at'));
		}

		if (filter_var(($filters['includeSnoozed'] ?? false), FILTER_VALIDATE_BOOLEAN) === true) {
			return;
		}

		// A snooze that has expired is not a snooze: the notice is back, and
		// still unread. Hence "null OR in the past", never "null".
		$qb->andWhere(
			$qb->expr()->orX(
				$qb->expr()->isNull('snoozed_until'),
				$qb->expr()->lte('snoozed_until', $qb->createNamedParameter($asOf, IQueryBuilder::PARAM_DATE))
			)
		);

	}//end applyListStateFilters()

	/**
	 * Mark one notice read for the recipient it belongs to.
	 *
	 * Scoped by recipient as well as by id, so a caller can never read a notice
	 * addressed to somebody else: the `WHERE` is the authorization, not a check
	 * the caller could be trusted to have made first.
	 *
	 * @param int $id The history row id.
	 * @param string $recipient The uid the notice is addressed to.
	 * @param DateTime|null $readAt The moment, defaulting to now.
	 *
	 * @return boolean True when a row was marked.
	 *
	 * @spec openspec/changes/object-read-state/specs/notificatie-engine/spec.md#requirement-a-notification-is-cleared-by-opening-what-it-was-about-req-ors-003
	 */
	public function markRead(int $id, string $recipient, ?DateTime $readAt = null): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('read_at', $qb->createNamedParameter(($readAt ?? new DateTime()), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('recipient', $qb->createNamedParameter($recipient)))
			->andWhere($qb->expr()->isNull('read_at'));

		return ($qb->executeStatement() > 0);

	}//end markRead()

	/**
	 * Mark read every unread notice one user holds about one subject.
	 *
	 * This is what "the bell empties because the work was done" is: opening the
	 * object clears the object's notices, and opening a sub-resource clears that
	 * sub-resource's, in one statement either way.
	 *
	 * @param string $recipient The uid whose notices are cleared.
	 * @param string $objectUuid The object the notices are about.
	 * @param string|null $subjectId Narrow to one sub-resource, or null for the
	 *                               object itself and everything on it.
	 * @param DateTime|null $readAt The moment, defaulting to now.
	 *
	 * @return integer How many notices were cleared.
	 *
	 * @spec openspec/changes/object-read-state/specs/notificatie-engine/spec.md#requirement-a-notification-is-cleared-by-opening-what-it-was-about-req-ors-003
	 */
	public function markReadForSubject(
		string $recipient,
		string $objectUuid,
		?string $subjectId = null,
		?DateTime $readAt = null
	): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('read_at', $qb->createNamedParameter(($readAt ?? new DateTime()), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('recipient', $qb->createNamedParameter($recipient)))
			->andWhere($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->andWhere($qb->expr()->isNull('read_at'));

		if ($subjectId !== null && $subjectId !== '') {
			$qb->andWhere($qb->expr()->eq('subject_id', $qb->createNamedParameter($subjectId)));
		}

		return $qb->executeStatement();

	}//end markReadForSubject()

	/**
	 * Snooze one notice until a moment, for the recipient it belongs to.
	 *
	 * @param int $id The history row id.
	 * @param string $recipient The uid the notice is addressed to.
	 * @param DateTime $until When it returns to the unread list.
	 *
	 * @return boolean True when a row was snoozed.
	 *
	 * @spec openspec/changes/object-read-state/specs/notificatie-engine/spec.md#requirement-a-notification-may-be-snoozed-or-archived-and-the-list-has-an-axis-req-ors-004
	 */
	public function snooze(int $id, string $recipient, DateTime $until): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('snoozed_until', $qb->createNamedParameter($until, IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('recipient', $qb->createNamedParameter($recipient)));

		return ($qb->executeStatement() > 0);

	}//end snooze()

	/**
	 * Archive one notice, leaving its read state exactly as it was.
	 *
	 * The statement touches `archived_at` and nothing else, which is the whole
	 * point: an archive that also wrote `read_at` would turn "I do not want to
	 * see this" into "I have dealt with this", and those are different answers.
	 *
	 * @param int $id The history row id.
	 * @param string $recipient The uid the notice is addressed to.
	 * @param DateTime|null $archivedAt The moment, defaulting to now.
	 *
	 * @return boolean True when a row was archived.
	 *
	 * @spec openspec/changes/object-read-state/specs/notificatie-engine/spec.md#requirement-a-notification-may-be-snoozed-or-archived-and-the-list-has-an-axis-req-ors-004
	 */
	public function archive(int $id, string $recipient, ?DateTime $archivedAt = null): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('archived_at', $qb->createNamedParameter(($archivedAt ?? new DateTime()), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('recipient', $qb->createNamedParameter($recipient)));

		return ($qb->executeStatement() > 0);

	}//end archive()

	/**
	 * Archive every notice about a subject that no longer exists.
	 *
	 * Not marked read: nobody read it, and nobody can, because the thing it
	 * points at is gone. Archiving is the only honest way to take it out.
	 *
	 * @param string $objectUuid The deleted object's uuid.
	 * @param DateTime|null $archivedAt The moment, defaulting to now.
	 *
	 * @return integer How many notices were archived.
	 *
	 * @spec openspec/changes/object-read-state/specs/notificatie-engine/spec.md#requirement-a-notification-is-cleared-by-opening-what-it-was-about-req-ors-003
	 */
	public function archiveByObject(string $objectUuid, ?DateTime $archivedAt = null): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('archived_at', $qb->createNamedParameter(($archivedAt ?? new DateTime()), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->andWhere($qb->expr()->isNull('archived_at'));

		return $qb->executeStatement();

	}//end archiveByObject()

	/**
	 * One notice, by id, for the recipient it belongs to.
	 *
	 * @param int $id The history row id.
	 * @param string $recipient The uid the notice is addressed to.
	 *
	 * @return NotificationHistory|null The row, or null when it is not theirs.
	 *
	 * @spec openspec/changes/object-read-state/specs/notificatie-engine/spec.md#requirement-a-notification-may-be-snoozed-or-archived-and-the-list-has-an-axis-req-ors-004
	 */
	public function findOwn(int $id, string $recipient): ?NotificationHistory {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('recipient', $qb->createNamedParameter($recipient)))
			->setMaxResults(1);

		try {
			return $this->findEntity(query: $qb);
		} catch (\Throwable $e) {
			return null;
		}

	}//end findOwn()
}//end class
