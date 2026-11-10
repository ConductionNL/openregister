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

namespace OCA\OpenRegister\Notifications\Db;

use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use DateTime;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

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
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Ten named queries and one
 * writer, each a distinct question this table answers with its own predicate
 * set: record a delivery, list and count it under a filter, count by status,
 * and the five per-recipient state changes (read, read-for-subject, snooze,
 * archive, archive-by-object) plus the ownership-scoped read they all lean on.
 * The five state changes are separate precisely BECAUSE each writes a
 * different column under a different `recipient` predicate; collapsing them
 * into one generic updater would move the choice of column and of guard into
 * the caller, which is where a per-recipient guard is easiest to forget. Same
 * argument {@see FlowTimerMapper} and {@see ContactLinkMapper} make.
 */
class NotificationHistoryMapper extends QBMapper implements NotificationHistoryMapperInterface {
	use NotificationQueryTrait;

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
		'eventId' => 'event_id',
	];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
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
	 * Convenience wrapper around `insert()` that takes one HistoryEntry
	 * instead of an entity. Used by `AnnotationNotificationDispatcher`.
	 *
	 * @param HistoryEntry $entry The delivery, the object and firing it belongs to, the notice and its subject axis.
	 *
	 * @return NotificationHistory The persisted row.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function record(HistoryEntry $entry): NotificationHistory {
		$entity = new NotificationHistory();
		$entity->setRuleId($entry->delivery->ruleId);
		$entity->setChannel($entry->delivery->channel);
		$entity->setRecipient($entry->delivery->recipient);
		$entity->setStatus($entry->delivery->status);
		$entity->setSchemaId($entry->target->schemaId);
		$entity->setRegisterId($entry->target->registerId);
		$entity->setObjectUuid($entry->target->objectUuid);
		$entity->setSubject($entry->notice->subject);
		$entity->setErrorMessage($entry->notice->errorMessage);
		$entity->setLocale($entry->notice->locale);
		$entity->setSubjectType($entry->about?->type);
		$entity->setEventId($entry->target->eventId);
		// A notice with no explicit subject is about the object it fired on, so
		// opening that object still clears it. Defaulting here rather than at
		// each call site is what keeps "the work clears the bell" true for
		// every rule that was written before the axis existed.
		$entity->setSubjectId(($entry->about->id ?? $entry->target->objectUuid));
		$entity->setDispatchedAt(new DateTime());

		return $this->insert(entity: $entity);
	}//end record()

	/**
	 * Find history rows matching the supplied filters.
	 *
	 * Supported filters: `ruleId`, `channel`, `recipient`, `objectUuid`,
	 * `schemaId`, `registerId`, `status`, `subjectType` and `subjectId`.
	 * Unknown keys are silently ignored. All filters are AND-combined.
	 *
	 * @param ListQuery     $query The filter map, the result limit (unbounded for none) and the offset.
	 * @param DateTime|null $asOf  The moment a snooze is measured against, defaulting to now.
	 *
	 * @return array<int, NotificationHistory>
	 */
	public function findFiltered(ListQuery $query, ?DateTime $asOf = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName());

		$this->applyFilters(qb: $qb, filter: new HistoryListFilter(filters: $query->filters, asOf: ($asOf ?? new DateTime())));
		$qb->orderBy('dispatched_at', 'DESC');
		$this->page(qb: $qb, limit: $query->limitOrNull(), offset: $query->offset);

		return $this->findEntities(query: $qb);
	}//end findFiltered()

	/**
	 * How the dispatches in a window came out, grouped by outcome.
	 *
	 * The dispatcher writes a status per attempt, and it writes more than two:
	 * `dispatched`, and then every reason a notice never reached anybody, from
	 * `rate-limited` to `preference-off` to `recipient-unresolved`. Grouping
	 * rather than counting a list of known statuses is deliberate: whatever the
	 * dispatcher learns to write next appears on the console by itself, instead
	 * of being silently dropped into neither column.
	 *
	 * Index-backed on `(status, dispatched_at)` (`or_notif_hist_status_idx`),
	 * which is the pair this groups and windows on (ADR-009).
	 *
	 * @param DateTime|null $since Only dispatches at or after this moment.
	 *
	 * @return array<string, int> Status to count, for the statuses in use.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	public function countByStatus(?DateTime $since = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('status')
			->selectAlias($qb->createFunction('COUNT(*)'), 'status_count')
			->from($this->getTableName())
			->groupBy('status');

		if ($since !== null) {
			$qb->where(
				$qb->expr()->gte('dispatched_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_DATETIME_MUTABLE))
			);
		}

		return $this->statusCounts(result: $qb->executeQuery());
	}//end countByStatus()

	/**
	 * Count rows matching the same filters as `findFiltered()`.
	 *
	 * @param array<string, string|null> $filters Filter map.
	 * @param DateTime|null $asOf The moment a snooze is measured against, defaulting to now.
	 *
	 * @return int Row count.
	 */
	public function countFiltered(array $filters = [], ?DateTime $asOf = null): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from($this->getTableName());

		$this->applyFilters(qb: $qb, filter: new HistoryListFilter(filters: $filters, asOf: ($asOf ?? new DateTime())));

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
	 * This is the SQL pushdown of `NotificationHistory::isInUnreadListAt()`,
	 * which states the same three rules in PHP and is where they are unit-tested
	 * against a clock. Same rules, same order; change one and change the other.
	 *
	 * @param IQueryBuilder     $qb     The query being built.
	 * @param HistoryListFilter $filter The filter map and the moment to judge a snooze against.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-notification-may-be-snoozed-or-archived-and-the-list-has-an-axis-req-ors-004
	 */
	private function applyListStateFilters(IQueryBuilder $qb, HistoryListFilter $filter): void {
		if ($this->flag(filters: $filter->filters, key: 'unreadOnly') === true) {
			$qb->andWhere($qb->expr()->isNull('read_at'));
		}

		if ($this->flag(filters: $filter->filters, key: 'includeArchived') === false) {
			$qb->andWhere($qb->expr()->isNull('archived_at'));
		}

		if ($this->flag(filters: $filter->filters, key: 'includeSnoozed') === false) {
			$this->whereNotSnoozed(qb: $qb, asOf: $filter->asOf);
		}
	}//end applyListStateFilters()


	/**
	 * Narrow by the mapped column filters, then by the list-state flags.
	 *
	 * A filter that is absent, null or empty narrows nothing.
	 *
	 * @param IQueryBuilder     $qb     The query.
	 * @param HistoryListFilter $filter The filters, keyed as COLUMN_MAP plus the state flags, and the moment snoozes are judged against.
	 *
	 * @return void
	 */
	private function applyFilters(IQueryBuilder $qb, HistoryListFilter $filter): void {
		$this->whereMappedColumns(qb: $qb, filters: $filter->filters);
		$this->applyListStateFilters(qb: $qb, filter: $filter);

	}//end applyFilters()

	/**
	 * Narrow by each COLUMN_MAP filter that carries a value.
	 *
	 * @param IQueryBuilder        $qb      The query.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return void
	 */
	private function whereMappedColumns(IQueryBuilder $qb, array $filters): void {
		foreach (self::COLUMN_MAP as $filterKey => $column) {
			$value = ($filters[$filterKey] ?? null);
			if ($value === null || $value === '') {
				continue;
			}

			$qb->andWhere(
				$qb->expr()->eq($column, $qb->createNamedParameter((string)$value))
			);
		}

	}//end whereMappedColumns()

	/**
	 * Read a boolean list-state flag; absent is false.
	 *
	 * @param array<string, mixed> $filters The filters.
	 * @param string               $key     The flag.
	 *
	 * @return bool The flag's value.
	 */
	private function flag(array $filters, string $key): bool {
		return filter_var(($filters[$key] ?? false), FILTER_VALIDATE_BOOLEAN);

	}//end flag()

	/**
	 * Leave out the notices still snoozed at the moment given.
	 *
	 * A snooze that has expired is not a snooze: the notice is back, and
	 * still unread. Hence "null OR in the past", never "null".
	 *
	 * @param IQueryBuilder $qb   The query.
	 * @param DateTime      $asOf The moment.
	 *
	 * @return void
	 */
	private function whereNotSnoozed(IQueryBuilder $qb, DateTime $asOf): void {
		$qb->andWhere(
			$qb->expr()->orX(
				$qb->expr()->isNull('snoozed_until'),
				$qb->expr()->lte('snoozed_until', $qb->createNamedParameter($asOf, IQueryBuilder::PARAM_DATETIME_MUTABLE))
			)
		);

	}//end whereNotSnoozed()

	/**
	 * Fold the status/count rows into a map, skipping rows without a status.
	 *
	 * @param IResult $result The grouped result.
	 *
	 * @return array<string, int> Status => count.
	 */
	private function statusCounts(IResult $result): array {
		$counts = [];
		foreach ($result->fetchAll() as $row) {
			$status = (string)($row['status'] ?? '');
			if ($status !== '') {
				$counts[$status] = (int)($row['status_count'] ?? 0);
			}
		}

		$result->closeCursor();

		return $counts;

	}//end statusCounts()

	/**
	 * An UPDATE of the mapper's table setting one timestamp column.
	 *
	 * @param string   $column The column.
	 * @param DateTime $value  The moment to write.
	 *
	 * @return IQueryBuilder The statement, ready for its narrowing.
	 */
	private function updateSetting(string $column, DateTime $value): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_DATETIME_MUTABLE));

		return $qb;

	}//end updateSetting()

	/**
	 * Mark one notice read for the recipient it belongs to.
	 *
	 * Scoped by recipient as well as by id, so a caller can never read a notice
	 * addressed to somebody else: the `WHERE` is the authorization, not a check
	 * the caller could be trusted to have made first.
	 *
	 * @param RecipientNotice $notice The history row and the uid it is addressed to.
	 * @param DateTime|null   $readAt The moment, defaulting to now.
	 *
	 * @return boolean True when a row was marked.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-notification-is-cleared-by-opening-what-it-was-about-req-ors-003
	 */
	public function markRead(RecipientNotice $notice, ?DateTime $readAt = null): bool {
		$qb = $this->updateSetting(column: 'read_at', value: ($readAt ?? new DateTime()));
		$this->whereEquals(qb: $qb, columns: ['id' => $notice->id, 'recipient' => $notice->recipient]);
		$qb->andWhere($qb->expr()->isNull('read_at'));

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
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-notification-is-cleared-by-opening-what-it-was-about-req-ors-003
	 */
	public function markReadForSubject(string $recipient, string $objectUuid, ?string $subjectId = null, ?DateTime $readAt = null): int {
		$qb = $this->updateSetting(column: 'read_at', value: ($readAt ?? new DateTime()));
		$this->whereEquals(qb: $qb, columns: ['recipient' => $recipient, 'object_uuid' => $objectUuid]);
		$qb->andWhere($qb->expr()->isNull('read_at'));

		if ($subjectId !== null && $subjectId !== '') {
			$qb->andWhere($qb->expr()->eq('subject_id', $qb->createNamedParameter($subjectId)));
		}

		return $qb->executeStatement();
	}//end markReadForSubject()

	/**
	 * Snooze one notice until a moment, for the recipient it belongs to.
	 *
	 * @param RecipientNotice $notice The history row and the uid it is addressed to.
	 * @param DateTime        $until  When it returns to the unread list.
	 *
	 * @return boolean True when a row was snoozed.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-notification-may-be-snoozed-or-archived-and-the-list-has-an-axis-req-ors-004
	 */
	public function snooze(RecipientNotice $notice, DateTime $until): bool {
		$qb = $this->updateSetting(column: 'snoozed_until', value: $until);
		$this->whereEquals(qb: $qb, columns: ['id' => $notice->id, 'recipient' => $notice->recipient]);

		return ($qb->executeStatement() > 0);
	}//end snooze()

	/**
	 * Archive one notice, leaving its read state exactly as it was.
	 *
	 * The statement touches `archived_at` and nothing else, which is the whole
	 * point: an archive that also wrote `read_at` would turn "I do not want to
	 * see this" into "I have dealt with this", and those are different answers.
	 *
	 * @param RecipientNotice $notice     The history row and the uid it is addressed to.
	 * @param DateTime|null   $archivedAt The moment, defaulting to now.
	 *
	 * @return boolean True when a row was archived.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-notification-may-be-snoozed-or-archived-and-the-list-has-an-axis-req-ors-004
	 */
	public function archive(RecipientNotice $notice, ?DateTime $archivedAt = null): bool {
		$qb = $this->updateSetting(column: 'archived_at', value: ($archivedAt ?? new DateTime()));
		$this->whereEquals(qb: $qb, columns: ['id' => $notice->id, 'recipient' => $notice->recipient]);

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
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-notification-is-cleared-by-opening-what-it-was-about-req-ors-003
	 */
	public function archiveByObject(string $objectUuid, ?DateTime $archivedAt = null): int {
		$qb = $this->updateSetting(column: 'archived_at', value: ($archivedAt ?? new DateTime()));
		$this->whereEquals(qb: $qb, columns: ['object_uuid' => $objectUuid]);
		$qb->andWhere($qb->expr()->isNull('archived_at'));

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
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-notification-may-be-snoozed-or-archived-and-the-list-has-an-axis-req-ors-004
	 */
	public function findOwn(int $id, string $recipient): ?NotificationHistory {
		$qb = $this->selectWhereEquals(columns: ['id' => $id, 'recipient' => $recipient]);
		$qb->setMaxResults(1);

		try {
			$found = $this->findEntity(query: $qb);
		} catch (\Throwable $e) {
			return null;
		}

		if ($found instanceof NotificationHistory) {
			return $found;
		}

		return null;
	}//end findOwn()
}//end class
