<?php

/**
 * Unit tests for SchemaMigrationService.
 *
 * Covers (with mocked mappers + ObjectService): concurrent-run refusal,
 * plan-validation refusal at start, preview non-persistence, a migration
 * batch that persists changed objects through the save pipeline while
 * leaving unchanged objects untouched, the MANDATORY no-data-loss guard
 * (an uncastable value is recorded as a failure and the object is never
 * saved), the stopOnError policy, and rollback conflict-skip + double
 * rollback 409.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schema;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SchemaRun;
use OCA\OpenRegister\Db\SchemaRunEntry;
use OCA\OpenRegister\Db\SchemaRunEntryMapper;
use OCA\OpenRegister\Db\SchemaRunMapper;
use OCA\OpenRegister\Exception\SchemaRunConcurrencyException;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Schema\SchemaMigrationPlanner;
use OCA\OpenRegister\Service\Schema\SchemaMigrationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SchemaMigrationServiceTest extends TestCase {
	private SchemaMigrationService $service;
	private SchemaRunMapper&MockObject $runMapper;
	private SchemaRunEntryMapper&MockObject $runEntryMapper;
	private SchemaMapper&MockObject $schemaMapper;
	private ObjectService&MockObject $objectService;

	protected function setUp(): void {
		parent::setUp();
		$this->runMapper = $this->createMock(SchemaRunMapper::class);
		$this->runEntryMapper = $this->createMock(SchemaRunEntryMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->objectService = $this->createMock(ObjectService::class);

		$this->schemaMapper->method('find')->willReturn(new Schema());

		$this->service = new SchemaMigrationService(
			new SchemaMigrationPlanner(),
			$this->runMapper,
			$this->runEntryMapper,
			$this->schemaMapper,
			$this->objectService,
			$this->createMock(LoggerInterface::class)
		);
	}

	private function obj(int $id, string $uuid, array $data, string $version = '1.0.0'): ObjectEntity {
		$o = new ObjectEntity();
		$o->setId($id);
		$o->setUuid($uuid);
		$o->setObject($data);
		$o->setVersion($version);
		return $o;
	}

	private function makeRun(string $type, array $plan = [], array $options = [], string $state = SchemaRun::STATE_RUNNING): SchemaRun {
		$run = new SchemaRun();
		$run->setId(7);
		$run->setSchemaId(1);
		$run->setRegisterId(2);
		$run->setType($type);
		$run->setState($state);
		$run->setPlan($plan);
		$run->setOptions($options);
		$run->setReport(['migrated' => 0, 'unchanged' => 0, 'failed' => 0]);
		return $run;
	}

	public function testStartRefusesConcurrentRun(): void {
		$active = new SchemaRun();
		$active->setId(99);
		$this->runMapper->method('findActiveForSchema')->willReturn($active);

		$this->expectException(SchemaRunConcurrencyException::class);
		$this->service->start(1, 2, [['op' => 'drop', 'field' => 'x']]);
	}

	public function testStartRefusesInvalidPlan(): void {
		$this->runMapper->method('findActiveForSchema')->willReturn(null);
		$this->expectException(\InvalidArgumentException::class);
		$this->service->start(1, 2, [['op' => 'frobnicate']]);
	}

	public function testPreviewDoesNotPersist(): void {
		$this->objectService->expects($this->never())->method('saveObject');
		$this->objectService->method('findAll')->willReturn([
			$this->obj(1, 'u1', ['fullname' => 'Ada']),
		]);

		$pairs = $this->service->preview(1, 2, [['op' => 'rename', 'from' => 'fullname', 'to' => 'name']]);

		$this->assertCount(1, $pairs);
		// getObject() merges the uuid in as `id`; the rename moves fullname -> name.
		$this->assertArrayHasKey('fullname', $pairs[0]['before']);
		$this->assertArrayNotHasKey('fullname', $pairs[0]['after']);
		$this->assertSame('Ada', $pairs[0]['after']['name']);
		$this->assertTrue($pairs[0]['changed']);
	}

	public function testBatchMigratesChangedAndSkipsUnchanged(): void {
		$run = $this->makeRun(SchemaRun::TYPE_MIGRATION, [['op' => 'setDefault', 'field' => 'status', 'value' => 'active']]);
		$objs = [
			$this->obj(1, 'u1', ['name' => 'Ada']),                    // missing status -> changes
			$this->obj(2, 'u2', ['name' => 'Bob', 'status' => 'done']), // already has status -> unchanged
		];
		$this->objectService->method('findAll')->willReturnOnConsecutiveCalls($objs, []);

		$saved = $this->obj(1, 'u1', ['name' => 'Ada', 'status' => 'active'], '1.0.1');
		$this->objectService->expects($this->once())
			->method('saveObject')
			->willReturn($saved);

		// One migrated entry recorded.
		$this->runEntryMapper->expects($this->once())
			->method('createFromArray')
			->with($this->callback(fn (array $d) => $d['outcome'] === SchemaRunEntry::OUTCOME_MIGRATED))
			->willReturn(new SchemaRunEntry());

		$more = $this->service->processBatch($run, 100);
		$this->assertTrue($more);

		$report = $run->getReport();
		$this->assertSame(1, $report['migrated']);
		$this->assertSame(1, $report['unchanged']);
		$this->assertSame(0, $report['failed']);
	}

	public function testNoDataLossGuardOnUncastableValue(): void {
		// MANDATORY: an uncastable value must be recorded as a failure and the
		// object must NEVER be saved (no partial / corrupt write).
		$run = $this->makeRun(SchemaRun::TYPE_MIGRATION, [['op' => 'cast', 'field' => 'age', 'to' => 'integer']]);
		$objs = [$this->obj(1, 'u1', ['age' => 'unknown'])];
		$this->objectService->method('findAll')->willReturnOnConsecutiveCalls($objs, []);

		$this->objectService->expects($this->never())->method('saveObject');

		$this->runEntryMapper->expects($this->once())
			->method('createFromArray')
			->with($this->callback(fn (array $d) => $d['outcome'] === SchemaRunEntry::OUTCOME_FAILED))
			->willReturn(new SchemaRunEntry());

		$this->service->processBatch($run, 100);

		$report = $run->getReport();
		$this->assertSame(1, $report['failed']);
		$this->assertSame(0, $report['migrated']);
	}

	public function testStopOnErrorHaltsRun(): void {
		$run = $this->makeRun(
			SchemaRun::TYPE_MIGRATION,
			[['op' => 'cast', 'field' => 'age', 'to' => 'integer']],
			['stopOnError' => true]
		);
		$objs = [
			$this->obj(1, 'u1', ['age' => 'unknown']),
			$this->obj(2, 'u2', ['age' => '5']),
		];
		$this->objectService->method('findAll')->willReturn($objs);
		$this->runEntryMapper->method('createFromArray')->willReturn(new SchemaRunEntry());
		$this->runMapper->expects($this->atLeastOnce())->method('save');

		$more = $this->service->processBatch($run, 100);

		$this->assertFalse($more);
		$this->assertSame(SchemaRun::STATE_FAILED, $run->getState());
		$this->assertSame(1, $run->getProcessed(), 'Halted after the first failing object');
	}

	public function testRollbackConflictSkipsModifiedObject(): void {
		$run = $this->makeRun(SchemaRun::TYPE_MIGRATION, [], [], SchemaRun::STATE_COMPLETED);
		$this->runMapper->method('find')->willReturn($run);

		$entry = new SchemaRunEntry();
		$entry->setObjectUuid('u1');
		$entry->setOutcome(SchemaRunEntry::OUTCOME_MIGRATED);
		$entry->setPreData(['name' => 'Ada']);
		$entry->setPostVersion('1.0.1');
		$this->runEntryMapper->method('findByRun')->willReturn([$entry]);

		// The object was edited after the migration (version moved on).
		$current = $this->obj(1, 'u1', ['name' => 'Edited'], '1.0.2');
		$this->objectService->method('find')->willReturn($current);

		// Conflict => never restore-saved.
		$this->objectService->expects($this->never())->method('saveObject');
		$this->runEntryMapper->expects($this->once())
			->method('createFromArray')
			->with($this->callback(fn (array $d) => $d['outcome'] === SchemaRunEntry::OUTCOME_CONFLICT))
			->willReturn(new SchemaRunEntry());

		$result = $this->service->rollback(7);

		$this->assertSame(SchemaRun::STATE_ROLLED_BACK, $result->getState());
		$this->assertSame(1, $result->getReport()['rollback']['conflicts']);
		$this->assertSame(0, $result->getReport()['rollback']['restored']);
	}

	public function testRollbackRestoresUnmodifiedObject(): void {
		$run = $this->makeRun(SchemaRun::TYPE_MIGRATION, [], [], SchemaRun::STATE_COMPLETED);
		$this->runMapper->method('find')->willReturn($run);

		$entry = new SchemaRunEntry();
		$entry->setObjectUuid('u1');
		$entry->setOutcome(SchemaRunEntry::OUTCOME_MIGRATED);
		$entry->setPreData(['name' => 'Ada']);
		$entry->setPostVersion('1.0.1');
		$this->runEntryMapper->method('findByRun')->willReturn([$entry]);

		// Object untouched since migration (version matches postVersion).
		$current = $this->obj(1, 'u1', ['name' => 'Ada', 'status' => 'x'], '1.0.1');
		$this->objectService->method('find')->willReturn($current);

		$this->objectService->expects($this->once())
			->method('saveObject')
			->willReturn($this->obj(1, 'u1', ['name' => 'Ada'], '1.0.2'));
		$this->runEntryMapper->method('createFromArray')->willReturn(new SchemaRunEntry());

		$result = $this->service->rollback(7);

		$this->assertSame(1, $result->getReport()['rollback']['restored']);
	}

	public function testDoubleRollbackRefused(): void {
		$run = $this->makeRun(SchemaRun::TYPE_MIGRATION, [], [], SchemaRun::STATE_ROLLED_BACK);
		$this->runMapper->method('find')->willReturn($run);

		$this->expectException(SchemaRunConcurrencyException::class);
		$this->service->rollback(7);
	}

	public function testRollbackRefusesNonMigrationRun(): void {
		$run = $this->makeRun(SchemaRun::TYPE_REVALIDATION, [], [], SchemaRun::STATE_COMPLETED);
		$this->runMapper->method('find')->willReturn($run);

		$this->expectException(\InvalidArgumentException::class);
		$this->service->rollback(7);
	}
}
