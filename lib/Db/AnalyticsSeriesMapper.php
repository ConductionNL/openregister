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

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Class AnalyticsSeriesMapper
 *
 * @template-extends QBMapper<AnalyticsSeries>
 */
class AnalyticsSeriesMapper extends QBMapper implements AnalyticsSeriesMapperInterface {

	use SelectByColumnTrait;
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
		$qb = $this->selectWhere(column: new LinkColumn(name: 'series_key', value: $seriesKey));

		$found = $this->findOneOrNull(query: $qb);
		if ($found instanceof AnalyticsSeries) {
			return $found;
		}

		return null;
	}//end findByKey()
}//end class
