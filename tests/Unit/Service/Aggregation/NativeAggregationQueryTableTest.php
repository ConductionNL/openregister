<?php

/**
 * The native aggregation statement names its table through the prefix
 * placeholder the connection resolves, so it works on any `dbtableprefix`.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Aggregation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Aggregation;

use OCA\OpenRegister\Service\Aggregation\NativeAggregationQuery;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Aggregation\NativeAggregationQuery
 */
class NativeAggregationQueryTableTest extends TestCase {

	/**
	 * The table is quoted for the platform and carries the placeholder.
	 *
	 * @return void
	 */
	public function testTheTableCarriesThePrefixPlaceholder(): void {
		$query = new NativeAggregationQuery(platform: 'mysql', quote: '`', tableName: 'openregister_table_1_2', metric: 'count');

		$this->assertSame('`*PREFIX*openregister_table_1_2`', $query->table());
	}//end testTheTableCarriesThePrefixPlaceholder()
}//end class
