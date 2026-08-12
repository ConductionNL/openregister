<?php

/**
 * Mapper for ScheduledReport entities (recurring ExportService export configs).
 *
 * Owner-scoped by surface: every write is keyed to the caller-supplied owner
 * uid; read scoping (own rows vs admin-all) is enforced by
 * `ScheduledReportService`/`ScheduledReportsController`, not this mapper.
 * Backed by `openregister_scheduled_reports`.
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
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/specs/scheduled-report-jobs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class ScheduledReportMapper
 *
 * @template-extends QBMapper<ScheduledReport>
 *
 * @spec openspec/specs/scheduled-report-jobs/spec.md
 */
class ScheduledReportMapper extends QBMapper {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_scheduled_reports', entityClass: ScheduledReport::class);
	}//end __construct()

	/**
	 * Find a scheduled report by id.
	 *
	 * @param int $id The scheduled report id.
	 *
	 * @return ScheduledReport
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no row matches.
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException When more than one row matches (should never happen on a PK lookup).
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	public function find(int $id): ScheduledReport {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity(query: $qb);
	}//end find()

	/**
	 * Find every scheduled report owned by a user.
	 *
	 * @param string $owner The owning Nextcloud user id.
	 *
	 * @return ScheduledReport[]
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	public function findByOwner(string $owner): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner', $qb->createNamedParameter($owner)))
			->orderBy('id', 'ASC');

		return $this->findEntities(query: $qb);
	}//end findByOwner()

	/**
	 * Find every scheduled report (admin listing).
	 *
	 * @return ScheduledReport[]
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('id', 'ASC');

		return $this->findEntities(query: $qb);
	}//end findAll()

	/**
	 * Find every enabled scheduled report, regardless of owner — the candidate
	 * set `ScheduledReportJob` narrows further via `ScheduledReportService::isDue()`.
	 *
	 * @return ScheduledReport[]
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	public function findEnabled(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('enabled', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

		return $this->findEntities(query: $qb);
	}//end findEnabled()
}//end class
