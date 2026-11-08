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
class ProcessingLogMapper extends QBMapper {
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
	 * Find entries by data-subject identifier within a period.
	 *
	 * Covers single reads (`subject_id_*`) and list reads (`subject_ids`); a
	 * list entry comes back reduced to this subject.
	 *
	 * @param string $idType Subject identifier type (e.g. `BSN`).
	 * @param string $idValue Subject identifier value.
	 * @param DateTime|null $from Inclusive lower bound.
	 * @param DateTime|null $to Inclusive upper bound.
	 * @param string|null $organisationId Tenant scope (null = all, admin only).
	 * @param bool $includeConfidential Whether to include confidential entries (FG only).
	 *
	 * @return ProcessingLogEntry[]
	 */
	public function findBySubject(
		string $idType,
		string $idValue,
		?DateTime $from = null,
		?DateTime $to = null,
		?string $organisationId = null,
		bool $includeConfidential = false,
	): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($this->subjectMatch(qb: $qb, idType: $idType, idValue: $idValue));

		$this->applyPeriod(qb: $qb, from: $from, to: $to);
		$this->applyScope(qb: $qb, organisationId: $organisationId, includeConfidential: $includeConfidential);

		$qb->orderBy('created', 'ASC');

		$entries = $this->findEntities(query: $qb);
		foreach ($entries as $entry) {
			$this->narrowToSubject(entry: $entry, idType: $idType, idValue: $idValue);
		}

		return $entries;
	}//end findBySubject()

	/**
	 * The predicate for one subject, on single reads and on list reads.
	 *
	 * Single reads carry the subject in `subject_id_*`, list reads in the
	 * `subject_ids` JSON. The pattern is a stretch of one element exactly as
	 * stored: its braces and quotes keep `111` from matching `1112`, and JSON
	 * escaping keeps a value from imitating an element.
	 *
	 * @param IQueryBuilder $qb      Query under construction.
	 * @param string|null   $idType  Subject identifier type, or null for any.
	 * @param string|null   $idValue Subject identifier value, or null for any.
	 *
	 * @return ICompositeExpression The expression.
	 */
	private function subjectMatch(IQueryBuilder $qb, ?string $idType, ?string $idValue): ICompositeExpression {
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
	 * @param ProcessingLogEntry $entry   The entry as read.
	 * @param string             $idType  Subject identifier type.
	 * @param string             $idValue Subject identifier value.
	 *
	 * @return void
	 */
	private function narrowToSubject(ProcessingLogEntry $entry, string $idType, string $idValue): void {
		if ($entry->getSubjectIds() === null) {
			return;
		}

		$entry->setSubjectIdType($idType);
		$entry->setSubjectIdValue($idValue);
		$entry->setSubjectIds([['type' => $idType, 'value' => $idValue]]);
		$entry->setObjectUuids(null);
		$entry->setQueryParams(null);
	}//end narrowToSubject()

	/**
	 * Find entries by an arbitrary filter set (FG inquiry / VNG API).
	 *
	 * A subject filter covers single reads and list reads. An entry stays whole
	 * here: unlike the per-subject extract, it goes to the FG or the auditor.
	 *
	 * @param array<string, mixed> $filters register_id, schema_id, activity_id, actor, action, subject_id_type, subject_id_value.
	 * @param DateTime|null $from Inclusive lower bound.
	 * @param DateTime|null $to Inclusive upper bound.
	 * @param string|null $organisationId Tenant scope (null = all, admin only).
	 * @param bool $includeConfidential Whether to include confidential entries (FG only).
	 * @param int $limit Page size.
	 * @param int $offset Page offset.
	 *
	 * @return ProcessingLogEntry[]
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	public function findFiltered(
		array $filters = [],
		?DateTime $from = null,
		?DateTime $to = null,
		?string $organisationId = null,
		bool $includeConfidential = false,
		int $limit = 100,
		int $offset = 0,
	): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());

		foreach (['register_id', 'schema_id', 'activity_id', 'actor', 'action'] as $column) {
			$value = ($filters[$column] ?? null);
			if ($value !== null && $value !== '') {
				$qb->andWhere($qb->expr()->eq($column, $qb->createNamedParameter((string)$value)));
			}
		}

		$idType = $this->subjectFilter(value: ($filters['subject_id_type'] ?? null));
		$idValue = $this->subjectFilter(value: ($filters['subject_id_value'] ?? null));
		if ($idType !== null || $idValue !== null) {
			$qb->andWhere($this->subjectMatch(qb: $qb, idType: $idType, idValue: $idValue));
		}

		$this->applyPeriod(qb: $qb, from: $from, to: $to);
		$this->applyScope(qb: $qb, organisationId: $organisationId, includeConfidential: $includeConfidential);

		$qb->orderBy('created', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);

		return $this->findEntities(query: $qb);
	}//end findFiltered()

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
	public function countByActivity(?string $organisationId = null, ?string $registerId = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('activity_id')
			->selectAlias($qb->func()->count('*'), 'cnt')
			->from($this->getTableName())
			->groupBy('activity_id');

		if ($organisationId !== null && $organisationId !== '') {
			$qb->andWhere($qb->expr()->eq('organisation_id', $qb->createNamedParameter($organisationId)));
		}

		if ($registerId !== null && $registerId !== '') {
			$qb->andWhere($qb->expr()->eq('register_id', $qb->createNamedParameter($registerId)));
		}

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
	 * Apply the period filter shared by the read helpers.
	 *
	 * @param IQueryBuilder $qb Query under construction.
	 * @param DateTime|null $from Inclusive lower bound.
	 * @param DateTime|null $to Inclusive upper bound.
	 *
	 * @return void
	 */
	private function applyPeriod(IQueryBuilder $qb, ?DateTime $from, ?DateTime $to): void {
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
	 * @param IQueryBuilder $qb Query under construction.
	 * @param string|null $organisationId Tenant scope.
	 * @param bool $includeConfidential Whether to include confidential entries.
	 *
	 * @return void
	 */
	private function applyScope(IQueryBuilder $qb, ?string $organisationId, bool $includeConfidential): void {
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
