<?php

/**
 * Mapper for analytics link entities.
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
 * @version GIT: <git-id>
 * @link    https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class AnalyticsLinkMapper
 *
 * @template-extends QBMapper<AnalyticsLink>
 */
class AnalyticsLinkMapper extends QBMapper {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_analytics_links', entityClass: AnalyticsLink::class);
	}//end __construct()

	/**
	 * Find analytics links by object UUID.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return AnalyticsLink[] Array of analytics links.
	 */
	public function findByObjectUuid(string $objectUuid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->orderBy('linked_at', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findByObjectUuid()

	/**
	 * Find analytics links by report id.
	 *
	 * @param int $reportId The report id.
	 *
	 * @return AnalyticsLink[] Array of analytics links.
	 */
	public function findByReportId(int $reportId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('report_id', $qb->createNamedParameter($reportId, IQueryBuilder::PARAM_INT)))
			->orderBy('linked_at', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findByReportId()

	/**
	 * Find a specific analytics link by object UUID and report id.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $reportId The report id.
	 *
	 * @return AnalyticsLink|null The link or null if not found.
	 */
	public function findByObjectAndReport(string $objectUuid, int $reportId): ?AnalyticsLink {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->andWhere($qb->expr()->eq('report_id', $qb->createNamedParameter($reportId, IQueryBuilder::PARAM_INT)));

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}//end findByObjectAndReport()

	/**
	 * Delete all analytics links for an object UUID.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return int Number of deleted rows.
	 */
	public function deleteByObjectUuid(string $objectUuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));

		return $qb->executeStatement();
	}//end deleteByObjectUuid()

	/**
	 * Delete an analytics link by object UUID + report id (Tier-2 unlink
	 * path).
	 *
	 * Returns the number of rows actually deleted so callers can
	 * distinguish "no such link" (0) from "ok" (>=1).
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $reportId The report id.
	 *
	 * @return int Number of deleted rows.
	 */
	public function deleteByObjectAndReport(string $objectUuid, int $reportId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->andWhere($qb->expr()->eq('report_id', $qb->createNamedParameter($reportId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}//end deleteByObjectAndReport()
}//end class
