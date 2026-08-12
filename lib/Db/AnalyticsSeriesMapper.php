<?php

/**
 * Mapper for AnalyticsSeries entities (page-level pre-computed chart
 * series).
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
 *
 * @spec openspec/specs/integration-leaf-foundation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class AnalyticsSeriesMapper
 *
 * @template-extends QBMapper<AnalyticsSeries>
 */
class AnalyticsSeriesMapper extends QBMapper {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_analytics_series', entityClass: AnalyticsSeries::class);
	}//end __construct()

	/**
	 * Find a series by its stable key.
	 *
	 * @param string $seriesKey The series key.
	 *
	 * @return AnalyticsSeries|null The series, or null when unknown.
	 */
	public function findByKey(string $seriesKey): ?AnalyticsSeries {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('series_key', $qb->createNamedParameter($seriesKey)));

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}//end findByKey()

	/**
	 * Find all series scoped to a register (and optionally a schema).
	 *
	 * @param int $registerId The register id.
	 * @param int|null $schemaId Optional schema id filter.
	 *
	 * @return AnalyticsSeries[] Matching series.
	 */
	public function findByScope(int $registerId, ?int $schemaId = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('register_id', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)));

		if ($schemaId !== null) {
			$qb->andWhere($qb->expr()->eq('schema_id', $qb->createNamedParameter($schemaId, IQueryBuilder::PARAM_INT)));
		}

		$qb->orderBy('updated_at', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findByScope()
}//end class
