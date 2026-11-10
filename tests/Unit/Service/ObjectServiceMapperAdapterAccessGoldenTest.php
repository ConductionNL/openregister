<?php

/**
 * Golden test pinning ObjectServiceMapperAdapter's findAll() config and its PATCH guard.
 *
 * findAll() shapes the config handed to ObjectService::findAll() and forwards no
 * _rbac/_multitenancy flags, so the service defaults (enforced) apply. The PATCH
 * path of updateFromArray() reads the existing object as an audit lookup and
 * refuses, without writing, when the scoped read finds nothing. Both are pinned
 * here before the methods are restructured.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\ObjectServiceMapperAdapter;
use OCA\OpenRegister\Service\WriteCause;
use OCA\OpenRegister\Tests\Unit\Support\FindCallShape;
use PHPUnit\Framework\TestCase;

/**
 * findAll() config shaping and the updateFromArray() PATCH guard, as they are.
 */
class ObjectServiceMapperAdapterAccessGoldenTest extends TestCase {

	/**
	 * Clear any cause frame a test left behind.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		WriteCause::reset();
	}//end tearDown()

	/**
	 * The adapter under test, with ObjectService::findAll() recording its arguments.
	 *
	 * @param array $captured Receives each call's arguments.
	 * @param int|string|null $register The bound register.
	 * @param int|string|null $schema The bound schema.
	 *
	 * @return ObjectServiceMapperAdapter
	 */
	private function findAllAdapter(array &$captured, int|string|null $register, int|string|null $schema): ObjectServiceMapperAdapter {
		$service = $this->createMock(ObjectService::class);
		$service->method('findAll')->willReturnCallback(
			static function (...$args) use (&$captured): array {
				$captured[] = $args;
				return ['r'];
			}
		);

		return new ObjectServiceMapperAdapter($service, $register, $schema);
	}//end findAllAdapter()

	/**
	 * Every argument lands in the config in order, the bound scope fills the filters, no flag is passed.
	 *
	 * @return void
	 */
	public function testFindAllAppliesEveryArgumentAndTheBoundScope(): void {
		$captured = [];
		$adapter = $this->findAllAdapter($captured, 3, 'sch');

		$result = $adapter->findAll(
			['limit' => 1, 'keep' => 'x', 'filters' => ['a' => 1]],
			['b' => 2],
			[7, 8],
			20,
			40,
			['title' => 'asc'],
			['owner'],
			'term'
		);

		$this->assertSame(['r'], $result);
		$this->assertCount(1, $captured);
		$this->assertSame(
			[
				[
					'limit' => 20,
					'keep' => 'x',
					'filters' => ['b' => 2, 'register' => 3, 'schema' => 'sch'],
					'ids' => [7, 8],
					'offset' => 40,
					'sort' => ['title' => 'asc'],
					'extend' => ['owner'],
					'search' => 'term',
				],
				true,
				true,
			],
			$captured[0]
		);
	}//end testFindAllAppliesEveryArgumentAndTheBoundScope()

	/**
	 * A caller's own register/schema filter wins over the bound scope.
	 *
	 * @return void
	 */
	public function testFindAllKeepsTheCallersScope(): void {
		$captured = [];
		$adapter = $this->findAllAdapter($captured, 3, 4);

		$adapter->findAll(['filters' => ['register' => 9, 'schema' => 8]]);

		$this->assertSame([['filters' => ['register' => 9, 'schema' => 8]], true, true], $captured[0]);
	}//end testFindAllKeepsTheCallersScope()

	/**
	 * Unbound, the config only gains empty filters; a null filters key becomes empty too.
	 *
	 * @return void
	 */
	public function testFindAllUnboundOnlyAddsEmptyFilters(): void {
		$captured = [];
		$adapter = $this->findAllAdapter($captured, null, null);

		$adapter->findAll();
		$adapter->findAll(['filters' => null, 'limit' => 5]);

		$this->assertSame([['filters' => []], true, true], $captured[0]);
		$this->assertSame([['filters' => [], 'limit' => 5], true, true], $captured[1]);
	}//end testFindAllUnboundOnlyAddsEmptyFilters()

	/**
	 * A register-only binding leaves the schema filter alone, and the reverse.
	 *
	 * @return void
	 */
	public function testFindAllAppliesOnlyTheBoundHalf(): void {
		$captured = [];
		$this->findAllAdapter($captured, 3, null)->findAll(['filters' => ['schema' => 1]]);
		$this->findAllAdapter($captured, null, 4)->findAll(['filters' => ['register' => 2]]);

		$this->assertSame(['schema' => 1, 'register' => 3], $captured[0][0]['filters']);
		$this->assertSame(['register' => 2, 'schema' => 4], $captured[1][0]['filters']);
	}//end testFindAllAppliesOnlyTheBoundHalf()

	/**
	 * PATCH: the scoped read runs as a lookup, then the merge is saved in the bound scope.
	 *
	 * @return void
	 */
	public function testPatchAllowedMergesOverTheExistingObject(): void {
		$existing = new ObjectEntity();
		$existing->setObject(['a' => 1, 'b' => 2]);

		$service = $this->createMock(ObjectService::class);
		$findArgs = null;
		$cause = null;
		$service->expects($this->once())->method('find')->willReturnCallback(
			static function (...$args) use (&$findArgs, &$cause, $existing): ObjectEntity {
				$findArgs = FindCallShape::find(args: $args);
				$cause = WriteCause::current()['cause'];
				return $existing;
			}
		);
		$saved = new ObjectEntity();
		$service->expects($this->once())->method('saveObject')
			->with(['a' => 1, 'b' => 3, 'c' => 4, 'id' => '7'], [], 1, 10)
			->willReturn($saved);

		$adapter = new ObjectServiceMapperAdapter($service, 1, 10);

		$this->assertSame($saved, $adapter->updateFromArray(7, ['b' => 3, 'c' => 4], true, true));
		$this->assertSame(['7', [], false, 1, 10, true, true, true, true], $findArgs);
		$this->assertSame(WriteCause::LOOKUP, $cause);
	}//end testPatchAllowedMergesOverTheExistingObject()

	/**
	 * PATCH: an object the scoped read cannot see is refused and nothing is written.
	 *
	 * @return void
	 */
	public function testPatchRefusedWhenTheScopedReadFindsNothing(): void {
		$service = $this->createMock(ObjectService::class);
		$service->expects($this->once())->method('find')->willReturn(null);
		$service->expects($this->never())->method('saveObject');

		$adapter = new ObjectServiceMapperAdapter($service, 1, 10);

		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('Object "abc" not found or not accessible');

		$adapter->updateFromArray('abc', ['b' => 3], true, true);
	}//end testPatchRefusedWhenTheScopedReadFindsNothing()

	/**
	 * PUT: no read, the object is saved as given with its id in the bound scope.
	 *
	 * @return void
	 */
	public function testPutSavesWithoutReading(): void {
		$service = $this->createMock(ObjectService::class);
		$service->expects($this->never())->method('find');
		$saved = new ObjectEntity();
		$service->expects($this->once())->method('saveObject')
			->with(['b' => 3, 'id' => '5'], [], 2, 20)
			->willReturn($saved);

		$adapter = new ObjectServiceMapperAdapter($service, 2, 20);

		$this->assertSame($saved, $adapter->updateFromArray(5, ['b' => 3, 'id' => 'ignored']));
	}//end testPutSavesWithoutReading()
}//end class
