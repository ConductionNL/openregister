<?php

/**
 * ObjectServiceLockAndTableDeleteFacadeTest
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

use OCA\OpenRegister\Service\TableDeletionOptions;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Service\Object\LockHandler;
use OCA\OpenRegister\Service\Object\LockRequest;
use OCA\OpenRegister\Service\Object\LockRunStep;
use OCA\OpenRegister\Service\Object\UnlockRequest;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\SchemaDeletionService;
use OCP\AppFramework\IAppContainer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * lockObject() and deleteObjectsBySchema() forward every argument they were given, unchanged.
 */
class ObjectServiceLockAndTableDeleteFacadeTest extends TestCase {

	/**
	 * An ObjectService with only the lock handler and the container set.
	 *
	 * @param LockHandler   $lockHandler The lock handler.
	 * @param IAppContainer $container   The container.
	 *
	 * @return ObjectService
	 */
	private function service(LockHandler $lockHandler, IAppContainer $container): ObjectService {
		$service = (new ReflectionClass(ObjectService::class))->newInstanceWithoutConstructor();
		(function () use ($lockHandler, $container): void {
			$this->lockHandler = $lockHandler;
			$this->container   = $container;
		})->call($service);

		return $service;
	}

	public function testLockObjectForwardsAllSixArguments(): void {
		$lock = $this->createMock(LockHandler::class);
		$lock->expects($this->once())->method('lock')
			->with(
				new LockRequest(identifier: 'obj-1', process: 'import', duration: 600, runStep: new LockRunStep(runUuid: 'run-1', nodeId: 'node-1')),
				advisory: true
			)
			->willReturn(['locked' => true]);

		$result = $this->service($lock, $this->createMock(IAppContainer::class))
			->lockObject('obj-1', 'import', 600, true, 'run-1', 'node-1');

		$this->assertSame(['locked' => true], $result);
	}

	public function testLockObjectDefaultsStayOff(): void {
		$lock = $this->createMock(LockHandler::class);
		$lock->expects($this->once())->method('lock')
			->with(
				new LockRequest(identifier: 'obj-1', process: null, duration: null, runStep: new LockRunStep(runUuid: null, nodeId: null)),
				advisory: false
			)
			->willReturn([]);

		$this->service($lock, $this->createMock(IAppContainer::class))->lockObject('obj-1');
	}

	public function testDeleteObjectsBySchemaForwardsIdsAndHardDeleteAsBulkDelete(): void {
		$deletion = $this->createMock(SchemaDeletionService::class);
		$deletion->expects($this->once())->method('deleteObjectsBySchema')
			->with(new RegisterSchemaRef(7, 42), new TableDeletionOptions(true, SchemaDeletionService::TRIGGER_BULK_DELETE))
			->willReturn(['deleted_count' => 1, 'deleted_uuids' => ['u'], 'schema_id' => 42]);
		$container = $this->createMock(IAppContainer::class);
		$container->method('get')->with(SchemaDeletionService::class)->willReturn($deletion);

		$result = $this->service($this->createMock(LockHandler::class), $container)->deleteObjectsBySchema(7, 42, true);

		$this->assertSame(['deleted_count' => 1, 'deleted_uuids' => ['u'], 'schema_id' => 42], $result);
	}

	public function testDeleteObjectsBySchemaIsSoftByDefault(): void {
		$deletion = $this->createMock(SchemaDeletionService::class);
		$deletion->expects($this->once())->method('deleteObjectsBySchema')
			->with(new RegisterSchemaRef(7, 42), new TableDeletionOptions(false, SchemaDeletionService::TRIGGER_BULK_DELETE))
			->willReturn(['deleted_count' => 0, 'deleted_uuids' => [], 'schema_id' => 42]);
		$container = $this->createMock(IAppContainer::class);
		$container->method('get')->willReturn($deletion);

		$this->service($this->createMock(LockHandler::class), $container)->deleteObjectsBySchema(7, 42);
	}
}
