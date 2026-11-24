<?php

/**
 * QualityAuditRunMapper — the kept runs of quality audits and their failing records.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

/**
 * Reads and writes `openregister_quality_audit_runs` and, beside it, the
 * per-run failing records in `openregister_quality_audit_failures`.
 *
 * The failures are a table of their own and are read a page at a time, so a
 * run over a large population is never assembled in memory (ADR-009).
 *
 * @template-extends QBMapper<QualityAuditRun>
 *
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md
 */
class QualityAuditRunMapper extends QBMapper implements QualityAuditRunMapperInterface {

	/**
	 * The failing-record table.
	 *
	 * @var string
	 */
	private const FAILURES = 'openregister_quality_audit_failures';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_quality_audit_runs', entityClass: QualityAuditRun::class);

	}//end __construct()

	/**
	 * Find a run by its numeric id.
	 *
	 * @param int $id The run id.
	 *
	 * @return QualityAuditRun The run.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such run exists.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md#requirement-a-quality-audit-run-is-kept-with-its-failures-and-the-tolerance-it-was-judged-against-req-rcn-004
	 */
	public function find(int $id): QualityAuditRun {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity(query: $qb);
	}//end find()

	/**
	 * One page of an audit's runs, oldest first, so the trend reads in sequence.
	 *
	 * @param int       $auditId The audit id.
	 * @param ListQuery $query   The page.
	 *
	 * @return QualityAuditRun[] The runs.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md#requirement-a-quality-audit-run-is-kept-with-its-failures-and-the-tolerance-it-was-judged-against-req-rcn-004
	 */
	public function findByAudit(int $auditId, ListQuery $query): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('audit_id', $qb->createNamedParameter($auditId, IQueryBuilder::PARAM_INT)))
			->orderBy('started_at', 'ASC')
			->addOrderBy('id', 'ASC');

		(new QueryPage(limit: $query->limitOrNull(), offset: $query->offset))->applyTo(qb: $qb);

		return $this->findEntities(query: $qb);
	}//end findByAudit()

	/**
	 * Store a new run.
	 *
	 * @param QualityAuditRun $run The run.
	 *
	 * @return QualityAuditRun The stored run.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md#requirement-a-quality-audit-run-is-kept-with-its-failures-and-the-tolerance-it-was-judged-against-req-rcn-004
	 */
	public function persist(QualityAuditRun $run): QualityAuditRun {
		if ($run->getId() === null) {
			if ($run->getUuid() === null) {
				$run->setUuid(Uuid::v4()->toRfc4122());
			}

			return $this->insert(entity: $run);
		}

		return $this->update(entity: $run);
	}//end persist()

	/**
	 * Store the failing records of a run.
	 *
	 * @param int                                                          $runId    The run id.
	 * @param array<int, array{record: string, rules: array<int, string>}> $failures The failures.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md#requirement-a-quality-audit-run-is-kept-with-its-failures-and-the-tolerance-it-was-judged-against-req-rcn-004
	 */
	public function addFailures(int $runId, array $failures): void {
		foreach ($failures as $failure) {
			$qb = $this->db->getQueryBuilder();
			$qb->insert(self::FAILURES)
				->values(
					[
						'run_id' => $qb->createNamedParameter($runId, IQueryBuilder::PARAM_INT),
						'record' => $qb->createNamedParameter((string)$failure['record']),
						'rules' => $qb->createNamedParameter((string)json_encode(array_values($failure['rules']))),
					]
				);
			$qb->executeStatement();
		}
	}//end addFailures()

	/**
	 * One page of a run's failing records, in the order they were found.
	 *
	 * @param int       $runId The run id.
	 * @param ListQuery $query The page.
	 *
	 * @return QualityAuditFailure[] The failures.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md#requirement-a-quality-audit-run-is-kept-with-its-failures-and-the-tolerance-it-was-judged-against-req-rcn-004
	 */
	public function findFailures(int $runId, ListQuery $query): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::FAILURES)
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');

		(new QueryPage(limit: $query->limitOrNull(), offset: $query->offset))->applyTo(qb: $qb);

		$result = $qb->executeQuery();
		$failures = [];
		while (($row = $result->fetch()) !== false) {
			$failures[] = QualityAuditFailure::fromRow($row);
		}

		$result->closeCursor();

		return $failures;
	}//end findFailures()

	/**
	 * Remove every run of an audit and their failing records.
	 *
	 * @param int $auditId The audit id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md#requirement-a-quality-audit-run-is-kept-with-its-failures-and-the-tolerance-it-was-judged-against-req-rcn-004
	 */
	public function deleteByAudit(int $auditId): void {
		$runIds = [];
		foreach ($this->findByAudit(auditId: $auditId, query: ListQuery::unbounded()) as $run) {
			$runIds[] = (int)$run->getId();
		}

		if ($runIds !== []) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete(self::FAILURES)
				->where($qb->expr()->in('run_id', $qb->createNamedParameter($runIds, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}

		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('audit_id', $qb->createNamedParameter($auditId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}//end deleteByAudit()
}//end class
