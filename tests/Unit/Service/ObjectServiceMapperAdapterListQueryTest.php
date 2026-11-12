<?php

/**
 * Tests for ObjectServiceMapperAdapter::findAllFor(), the ListQuery form of findAll().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\ObjectServiceMapperAdapter;
use PHPUnit\Framework\TestCase;

/**
 * findAllFor() lays the query over the config and fills the bound scope.
 */
class ObjectServiceMapperAdapterListQueryTest extends TestCase {
	/**
	 * The query's fields replace the config's, other keys stay, and the bound scope fills the filters.
	 *
	 * @return void
	 */
	public function testQueryOverlaysConfigAndBoundScope(): void {
		$captured = [];
		$service  = $this->createMock(ObjectService::class);
		$service->method('findAll')->willReturnCallback(
			static function (...$args) use (&$captured): array {
				$captured[] = $args[0];
				return ['r'];
			}
		);
		$adapter = new ObjectServiceMapperAdapter($service, 3, 'sch');

		$result = $adapter->findAllFor(
			new ListQuery(limit: 5, offset: 10, filters: ['a' => 1], sort: ['x' => 'asc'], search: 'term'),
			['limit' => 1, 'ids' => [7], 'extend' => ['e']]
		);

		$this->assertSame(['r'], $result);
		$this->assertSame(
			[
				[
					'limit'   => 5,
					'ids'     => [7],
					'extend'  => ['e'],
					'filters' => ['a' => 1, 'register' => 3, 'schema' => 'sch'],
					'offset'  => 10,
					'sort'    => ['x' => 'asc'],
					'search'  => 'term',
				],
			],
			$captured
		);
	}//end testQueryOverlaysConfigAndBoundScope()

	/**
	 * An unset offset and search leave the config's values in place.
	 *
	 * @return void
	 */
	public function testUnsetOffsetAndSearchKeepTheConfig(): void {
		$captured = [];
		$service  = $this->createMock(ObjectService::class);
		$service->method('findAll')->willReturnCallback(
			static function (...$args) use (&$captured): array {
				$captured[] = $args[0];
				return [];
			}
		);
		$adapter = new ObjectServiceMapperAdapter($service);

		$adapter->findAllFor(new ListQuery(), ['offset' => 4, 'search' => 'kept']);

		$this->assertSame(
			['offset' => 4, 'search' => 'kept', 'filters' => [], 'limit' => ListQuery::DEFAULT_LIMIT, 'sort' => []],
			$captured[0]
		);
	}//end testUnsetOffsetAndSearchKeepTheConfig()
}//end class
