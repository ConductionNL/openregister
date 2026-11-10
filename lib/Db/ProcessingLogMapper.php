<?php

/**
 * Mapper for `oc_openregister_processing_log` — append-only.
 *
 * Exposes only append + read + retention-prune operations. There is no
 * `update()` and no single-row `delete()`: the processing log is
 * immutable by surface (AVG Art 5(2) / VNG Logging Verwerkingen
 * accountability). The only deletion path is the retention prune
 * (`deleteCreatedBefore`), a bulk hard-delete by the `created` index.
 *
 * Read helpers are all organisation- and (optionally) register-scoped
 * and FG-gate `confidential` entries at the query level so a non-FG
 * caller can never retrieve them.
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
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCA\OpenRegister\Db\ProcessingLog\ProcessingLogSubject;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

/**
 * Mapper class for ProcessingLogEntry rows.
 *
 * @template-extends QBMapper<ProcessingLogEntry>
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */
class ProcessingLogMapper extends QBMapper implements ProcessingLogMapperInterface {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: 'openregister_processing_log',
			entityClass: ProcessingLogEntry::class
		);

	}//end __construct()

	/**
	 * Insert a single entry, auto-filling uuid + created.
	 *
	 * @param ProcessingLogEntry $entity Entry to insert.
	 *
	 * @return ProcessingLogEntry Persisted entry with id populated.
	 */
	public function insert($entity): ProcessingLogEntry {
		if ($entity->getUuid() === null || $entity->getUuid() === '') {
			$entity->setUuid(Uuid::v4()->toRfc4122());
		}

		if ($entity->getCreated() === null) {
			$entity->setCreated(new DateTime());
		}

		if ($entity->getObjectCount() === null) {
			$entity->setObjectCount(1);
		}

		return parent::insert(entity: $entity);
	}//end insert()

	/**
	 * Append a batch of entries in one transaction.
	 *
	 * Used by the deferred emission flush so a request's entries land as
	 * one batched write rather than a per-row synchronous insert.
	 *
	 * @param array<int, ProcessingLogEntry> $entries Entries to append.
	 *
	 * @return int Number of entries persisted.
	 */
	public function insertBatch(array $entries): int {
		if ($entries === []) {
			return 0;
		}

		$count = 0;
		$this->db->beginTransaction();
		try {
			foreach ($entries as $entry) {
				$this->insert(entity: $entry);
				$count++;
			}

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return $count;
	}//end insertBatch()

	/**
	 * Find entries by data-subject identifier within a read scope.
	 *
	 * Covers single reads (`subject_id_*`) and list reads (`subject_ids`); a
	 * list entry comes back reduced to this subject.
	 *
	 * @param ProcessingLogSubject   $subject The subject, type and value both given (e.g. `BSN`, `111`).
	 * @param ProcessingLogReadScope $scope   Period, tenant scope and confidentiality; the defaults when omitted.
	 *
	 * @return ProcessingLogEntry[]
	 */
	public function findForSubject(ProcessingLogSubject $subject, ProcessingLogReadScope $scope=new ProcessingLogReadScope()): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($this->subjectMatch(qb: $qb, subject: $subject));

		$this->applyReadScope(qb: $qb, scope: $scope);

		$qb->orderBy('created', 'ASC');

		$entries = $this->findEntities(query: $qb);
		foreach ($entries as $entry) {
			$this->narrowToSubject(entry: $entry, subject: $subject);
		}

		return $entries;
	}//end findForSubject()

	/**
	 * The predicate for one subject, on single reads and on list reads.
	 *
	 * Single reads carry the subject in `subject_id_*`, list reads in the
	 * `subject_ids` JSON. The pattern is a stretch of one element exactly as
	 * stored: its braces and quotes keep `111` from matching `1112`, and JSON
	 * escaping keeps a value from imitating an element.
	 *
	 * @param IQueryBuilder        $qb      Query under construction.
	 * @param ProcessingLogSubject $subject The subject; a null type or value matches any.
	 *
	 * @return ICompositeExpression The expression.
	 */
	private function subjectMatch(IQueryBuilder $qb, ProcessingLogSubject $subject): ICompositeExpression {
		$idType = $subject->type;
		$idValue = $subject->value;
		$columns = [];
		$element = '';
		if ($idType !== null) {
			$columns[] = $qb->expr()->eq('subject_id_type', $qb->createNamedParameter($idType));
			$element = substr((string)json_encode(['type' => $idType]), 0, -1) . ',';
		}

		if ($idValue !== null) {
			$columns[] = $qb->expr()->eq('subject_id_value', $qb->createNamedParameter($idValue));
			$element .= substr((string)json_encode(['value' => $idValue]), 1);
		}

		return $qb->expr()->orX(
			$qb->expr()->andX(...$columns),
			$qb->expr()->like(
				'subject_ids',
				$qb->createNamedParameter('%' . $this->db->escapeLikeParameter($element) . '%')
			)
		);
	}//end subjectMatch()

	/**
	 * Reduce a list entry to what concerns one subject.
	 *
	 * A per-subject extract goes to that subject, so a list entry must not
	 * carry the other subjects in the list, the objects it covered or the
	 * query, which can name other people. The count stays.
	 *
	 * @param ProcessingLogEntry   $entry   The entry as read.
	 * @param ProcessingLogSubject $subject The subject, type and value both given.
	 *
	 * @return void
	 */
	private function narrowToSubject(ProcessingLogEntry $entry, ProcessingLogSubject $subject): void {
		if ($entry->getSubjectIds() === null) {
			return;
		}

		$entry->setSubjectIdType($subject->type);
		$entry->setSubjectIdValue($subject->value);
		$entry->setSubjectIds([['type' => $subject->type, 'value' => $subject->value]]);
		$entry->setObjectUuids(null);
		$entry->setQueryParams(null);
	}//end narrowToSubject()

	/**
	 * Find one page of entries by an arbitrary filter set (FG inquiry / VNG API), newest first.
	 *
	 * A subject filter covers single reads and list reads. An entry stays whole
	 * here: unlike the per-subject extract, it goes to the FG or the auditor.
	 *
	 * @param ListQuery              $query The filters (register_id, schema_id, activity_id, actor, action,
	 *                                      subject_id_type, subject_id_value), the limit and the offset.
	 * @param ProcessingLogReadScope $scope Period, tenant scope and confidentiality; the defaults when omitted.
	 *
	 * @return ProcessingLogEntry[]
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	public function findListed(ListQuery $query, ProcessingLogReadScope $scope=new ProcessingLogReadScope()): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());

		$this->applyColumnFilters(qb: $qb, filters: $query->filters);
		$this->applySubjectFilter(qb: $qb, filters: $query->filters);

		$this->applyReadScope(qb: $qb, scope: $scope);

		$qb->orderBy('created', 'DESC')
			->setMaxResults($query->limit)
			->setFirstResult($query->offset ?? 0);

		return $this->findEntities(query: $qb);
	}//end findListed()

	/**
	 * One equality per filled column filter, compared as a string.
	 *
	 * A null or empty-string value leaves the column unfiltered.
	 *
	 * @param IQueryBuilder        $qb      The query.
	 * @param array<string, mixed> $filters The inquiry filters.
	 *
	 * @return void
	 */
	private function applyColumnFilters(IQueryBuilder $qb, array $filters): void {
		$columns = ['register_id', 'schema_id', 'activity_id', 'actor', 'action'];
		$values = array_map(static fn (string $column): mixed => ($filters[$column] ?? null), $columns);
		$this->applyEqualsWhenSet(qb: $qb, values: array_combine($columns, $values));
	}//end applyColumnFilters()

	/**
	 * Restrict to a subject when the filters name its id type, its id value, or both.
	 *
	 * @param IQueryBuilder        $qb      The query.
	 * @param array<string, mixed> $filters The inquiry filters.
	 *
	 * @return void
	 */
	private function applySubjectFilter(IQueryBuilder $qb, array $filters): void {
		$subject = new ProcessingLogSubject(
			type: $this->subjectFilter(value: ($filters['subject_id_type'] ?? null)),
			value: $this->subjectFilter(value: ($filters['subject_id_value'] ?? null))
		);
		if ($subject->isOpen() === false) {
			$qb->andWhere($this->subjectMatch(qb: $qb, subject: $subject));
		}
	}//end applySubjectFilter()

	/**
	 * An equality per column, as a string, in the given order; a null or '' value adds no condition.
	 *
	 * @param IQueryBuilder        $qb     The query.
	 * @param array<string, mixed> $values Column to value.
	 *
	 * @return void
	 */
	private function applyEqualsWhenSet(IQueryBuilder $qb, array $values): void {
		foreach ($values as $column => $value) {
			if ($value !== null && $value !== '') {
				$qb->andWhere($qb->expr()->eq($column, $qb->createNamedParameter((string)$value)));
			}
		}
	}//end applyEqualsWhenSet()

	/**
	 * A subject filter as a string, or null when it filters nothing.
	 *
	 * @param mixed $value The filter value.
	 *
	 * @return string|null The value.
	 */
	private function subjectFilter(mixed $value): ?string {
		if ($value === null || $value === '') {
			return null;
		}

		return (string)$value;
	}//end subjectFilter()

	/**
	 * Count entries attributed to each activity, optionally per register.
	 *
	 * Used by the compliance surface to expose the flagged-fallback gap.
	 *
	 * @param string|null $organisationId Tenant scope.
	 * @param string|null $registerId Optional register slice.
	 *
	 * @return array<string, int> Map of activity uuid => entry count.
	 */
	public function countByActivity(string|null $organisationId = null, string|null $registerId = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('activity_id')
			->selectAlias($qb->func()->count('*'), 'cnt')
			->from($this->getTableName())
			->groupBy('activity_id');

		$this->applyEqualsWhenSet(qb: $qb, values: ['organisation_id' => $organisationId, 'register_id' => $registerId]);

		$result = $qb->executeQuery();
		$counts = [];
		while (($row = $result->fetch()) !== false) {
			$counts[(string)$row['activity_id']] = (int)$row['cnt'];
		}

		$result->closeCursor();

		return $counts;
	}//end countByActivity()

	/**
	 * Hard-delete entries older than the cutoff (retention prune).
	 *
	 * @param DateTime $cutoff Entries with `created` strictly before this are deleted.
	 *
	 * @return int Number of rows deleted.
	 */
	public function deleteCreatedBefore(DateTime $cutoff): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->lt('created', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_DATETIME_MUTABLE)));

		return $qb->executeStatement();
	}//end deleteCreatedBefore()

	/**
	 * Apply a read's period, then its tenant and confidentiality scope.
	 *
	 * @param IQueryBuilder          $qb    Query under construction.
	 * @param ProcessingLogReadScope $scope What the read may see.
	 *
	 * @return void
	 */
	private function applyReadScope(IQueryBuilder $qb, ProcessingLogReadScope $scope): void {
		$this->applyPeriod(qb: $qb, scope: $scope);
		$this->applyScope(qb: $qb, scope: $scope);
	}//end applyReadScope()

	/**
	 * Apply the period filter shared by the read helpers.
	 *
	 * @param IQueryBuilder          $qb    Query under construction.
	 * @param ProcessingLogReadScope $scope Its inclusive from and to bounds.
	 *
	 * @return void
	 */
	private function applyPeriod(IQueryBuilder $qb, ProcessingLogReadScope $scope): void {
		$from = $scope->from;
		$to = $scope->to;
		if ($from !== null) {
			$qb->andWhere(
				$qb->expr()->gte('created', $qb->createNamedParameter($from, IQueryBuilder::PARAM_DATETIME_MUTABLE))
			);
		}

		if ($to !== null) {
			$qb->andWhere(
				$qb->expr()->lte('created', $qb->createNamedParameter($to, IQueryBuilder::PARAM_DATETIME_MUTABLE))
			);
		}

	}//end applyPeriod()

	/**
	 * Apply the tenant and confidentiality filters shared by the read helpers.
	 *
	 * @param IQueryBuilder          $qb    Query under construction.
	 * @param ProcessingLogReadScope $scope Its tenant scope and whether confidential entries are included.
	 *
	 * @return void
	 */
	private function applyScope(IQueryBuilder $qb, ProcessingLogReadScope $scope): void {
		$organisationId = $scope->organisationId;
		$includeConfidential = $scope->includeConfidential;
		if ($organisationId !== null && $organisationId !== '') {
			$qb->andWhere(
				$qb->expr()->eq('organisation_id', $qb->createNamedParameter($organisationId))
			);
		}

		if ($includeConfidential === false) {
			$qb->andWhere(
				$qb->expr()->eq('confidential', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
			);
		}

	}//end applyScope()
}//end class
