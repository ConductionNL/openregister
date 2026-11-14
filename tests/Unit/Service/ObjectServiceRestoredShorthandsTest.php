<?php

/**
 * ObjectServiceRestoredShorthandsTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectMigration;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\ObjectServiceMapperAdapter;
use PHPUnit\Framework\TestCase;

/**
 * The published shorthands consuming apps call give the same call as the forms that replaced them.
 */
class ObjectServiceRestoredShorthandsTest extends TestCase {

	public function testMigrateObjectsIsMigrateWithOneObjectMigration(): void {
		$service = $this->getMockBuilder(ObjectService::class)->disableOriginalConstructor()->onlyMethods(['migrate'])->getMock();
		$service->expects($this->once())->method('migrate')
			->with(new ObjectMigration(
				source: new RegisterSchemaRef(register: 1, schema: 'from'),
				target: new RegisterSchemaRef(register: 'reg', schema: 2),
				objectIds: ['u-1'],
				mapping: ['a' => 'b']
			))
			->willReturn(['success' => true]);

		$this->assertSame(['success' => true], $service->migrateObjects(1, 'from', 'reg', 2, ['u-1'], ['a' => 'b']));
	}

	public function testUpdateFromArraySavesTheWholeObjectInTheBoundScope(): void {
		$saved   = [];
		$service = $this->createMock(ObjectService::class);
		$service->method('saveObject')->willReturnCallback(
			static function (array|ObjectEntity $object, mixed $options) use (&$saved): ObjectEntity {
				$saved[] = [$object, $options];
				return new ObjectEntity();
			}
		);
		$adapter = new ObjectServiceMapperAdapter(objectService: $service, register: 1, schema: 10);

		$adapter->updateFromArray(id: 'u-1', object: ['title' => 'x']);
		$adapter->replaceFromArray(id: 'u-1', object: ['title' => 'x']);

		$this->assertCount(2, $saved);
		$this->assertEquals($saved[1], $saved[0]);
		$this->assertSame(['title' => 'x', 'id' => 'u-1'], $saved[0][0]);
		$this->assertInstanceOf(ObjectSaveOptions::class, $saved[0][1]);
		$this->assertSame([1, 10], [$saved[0][1]->target->register, $saved[0][1]->target->schema]);
	}

	public function testFindAllShorthandsLandInTheConfig(): void {
		$configs = [];
		$service = $this->createMock(ObjectService::class);
		$service->expects($this->exactly(2))->method('findAll')->willReturnCallback(
			static function (array $config) use (&$configs): array {
				$configs[] = $config;
				return [];
			}
		);
		$adapter = new ObjectServiceMapperAdapter(objectService: $service, register: 1, schema: 10);

		$adapter->findAll(filters: ['status' => 'open'], limit: 5);
		$adapter->findAll(config: ['filters' => ['status' => 'open'], 'limit' => 5]);

		$this->assertSame($configs[1], $configs[0]);
	}
}//end class
