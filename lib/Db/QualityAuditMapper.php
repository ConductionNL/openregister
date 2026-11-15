<?php

/**
 * QualityAuditMapper — persistence of quality audit definitions.
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

use DateTime;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

/**
 * Reads and writes `openregister_quality_audits`.
 *
 * @template-extends QBMapper<QualityAudit>
 *
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md
 */
class QualityAuditMapper extends QBMapper implements QualityAuditMapperInterface {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_quality_audits', entityClass: QualityAudit::class);

	}//end __construct()

	/**
	 * Find an audit by its numeric id.
	 *
	 * @param int $id The audit id.
	 *
	 * @return QualityAudit The audit.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such audit exists.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md#requirement-a-quality-audit-declares-a-population-a-rule-set-and-a-tolerance-req-rcn-003
	 */
	public function find(int $id): QualityAudit {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity(query: $qb);
	}//end find()

	/**
	 * One page of audits, by name.
	 *
	 * @param ListQuery $query The page.
	 *
	 * @return QualityAudit[] The audits.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md#requirement-a-quality-audit-declares-a-population-a-rule-set-and-a-tolerance-req-rcn-003
	 */
	public function findPage(ListQuery $query): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('name', 'ASC')
			->addOrderBy('id', 'ASC');

		(new QueryPage(limit: $query->limitOrNull(), offset: $query->offset))->applyTo(qb: $qb);

		return $this->findEntities(query: $qb);
	}//end findPage()

	/**
	 * Every audit that declares a schedule.
	 *
	 * @return QualityAudit[] The scheduled audits.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md#requirement-a-quality-audit-declares-a-population-a-rule-set-and-a-tolerance-req-rcn-003
	 */
	public function findScheduled(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->isNotNull('schedule'))
			->orderBy('id', 'ASC');

		return $this->findEntities(query: $qb);
	}//end findScheduled()

	/**
	 * Persist an audit: insert a new one with a uuid, or update an existing one.
	 *
	 * @param QualityAudit $audit The audit.
	 *
	 * @return QualityAudit The persisted audit.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md#requirement-a-quality-audit-declares-a-population-a-rule-set-and-a-tolerance-req-rcn-003
	 */
	public function persist(QualityAudit $audit): QualityAudit {
		$now = new DateTime();
		$audit->setUpdated($now);

		if ($audit->getId() === null) {
			if ($audit->getUuid() === null) {
				$audit->setUuid(Uuid::v4()->toRfc4122());
			}

			$audit->setCreated($now);

			return $this->insert(entity: $audit);
		}

		return $this->update(entity: $audit);
	}//end persist()

	/**
	 * Remove an audit.
	 *
	 * @param QualityAudit $audit The audit.
	 *
	 * @return QualityAudit The removed audit.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md#requirement-a-quality-audit-declares-a-population-a-rule-set-and-a-tolerance-req-rcn-003
	 */
	public function remove(QualityAudit $audit): QualityAudit {
		return $this->delete(entity: $audit);
	}//end remove()
}//end class
