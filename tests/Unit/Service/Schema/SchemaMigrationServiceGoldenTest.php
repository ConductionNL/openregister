<?php

/**
 * Golden test for SchemaMigrationService batch processing and rollback.
 *
 * Pins, on the code before it was split into small units, every recorded
 * run entry, every save call (and that it runs with RBAC and multitenancy
 * off), the report, the cursor, the processed count and the terminal state,
 * for a mixed batch, for both stop-on-error halts, and for each rollback
 * outcome: missing object, version conflict, restore and failed restore.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schema;

use OCA\OpenRegister\Service\Schema\SchemaPopulationRuns;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SchemaRun;
use OCA\OpenRegister\Db\SchemaRunEntry;
use OCA\OpenRegister\Db\SchemaRunEntryMapper;
use OCA\OpenRegister\Db\SchemaRunMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Schema\SchemaMigrationPlanner;
use OCA\OpenRegister\Service\Schema\SchemaMigrationService;
use OCA\OpenRegister\Tests\Unit\Support\FindCallShape;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SchemaMigrationServiceGoldenTest extends TestCase {
	private SchemaMigrationService $service;
	private SchemaRunMapper&MockObject $runMapper;
	private SchemaRunEntryMapper&MockObject $runEntryMapper;
	private ObjectService&MockObject $objectService;
	private Schema $schema;

	/** @var list<array<string, mixed>> */
	private array $entries = [];

	/** @var list<array<int, mixed>> */
	private array $saves = [];

	/** @var int */
	private int $runSaves = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->runMapper = $this->createMock(SchemaRunMapper::class);
		$this->runEntryMapper = $this->createMock(SchemaRunEntryMapper::class);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->schema = new Schema();
		$schemaMapper->method('find')->willReturn($this->schema);

		$this->runEntryMapper->method('createFromArray')->willReturnCallback(
			function (array $data): SchemaRunEntry {
				$this->entries[] = $data;
				return new SchemaRunEntry();
			}
		);
		$this->runMapper->method('save')->willReturnCallback(
			function (SchemaRun $run): SchemaRun {
				$this->runSaves++;
				return $run;
			}
		);

		$this->service = new SchemaMigrationService(
			new SchemaMigrationPlanner(),
			new SchemaPopulationRuns($this->runMapper, $this->runEntryMapper, $schemaMapper, $this->objectService)
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

	private function makeRun(array $options = [], string $state = SchemaRun::STATE_RUNNING): SchemaRun {
		$run = new SchemaRun();
		$run->setId(7);
		$run->setSchemaId(1);
		$run->setRegisterId(2);
		$run->setType(SchemaRun::TYPE_MIGRATION);
		$run->setState($state);
		$run->setPlan([['op' => 'cast', 'field' => 'age', 'to' => 'integer']]);
		$run->setOptions($options);
		$run->setReport(['migrated' => 0, 'unchanged' => 0, 'failed' => 0]);
		$run->setCursor(3);
		return $run;
	}

	/**
	 * A failing, an unchanged, a migrated and a save-refused object.
	 *
	 * @return list<ObjectEntity>
	 */
	private function mixedBatch(): array {
		return [
			$this->obj(4, 'u4', ['age' => 'unknown']),
			$this->obj(9, 'u9', ['age' => 5]),
			$this->obj(6, 'u6', ['age' => '7'], '1.0.0'),
			$this->obj(8, 'u8', ['age' => '8'], '2.0.0'),
		];
	}

	private function saveRefusesU8(): void {
		$this->objectService->method('saveObject')->willReturnCallback(
			function (...$args): ObjectEntity {
				$this->saves[] = $args;
				if ($args[4] === 'u8') {
					throw new RuntimeException('save refused');
				}

				return $this->obj(6, 'u6', ['age' => 7], '1.0.1');
			}
		);
	}

	public function testAMixedBatchRecordsEveryOutcomeAndContinues(): void {
		$run = $this->makeRun();
		$this->objectService->method('findAll')->willReturn($this->mixedBatch());
		$this->saveRefusesU8();

		$this->assertTrue($this->service->processBatch($run, 100));

		$this->assertSame(
			[
				['runId' => 7, 'objectUuid' => 'u4', 'outcome' => SchemaRunEntry::OUTCOME_FAILED, 'message' => $this->entries[0]['message']],
				[
					'runId' => 7, 'objectUuid' => 'u6', 'outcome' => SchemaRunEntry::OUTCOME_MIGRATED,
					'preVersion' => '1.0.0', 'postVersion' => '1.0.1', 'preData' => $this->entries[1]['preData'],
				],
				['runId' => 7, 'objectUuid' => 'u8', 'outcome' => SchemaRunEntry::OUTCOME_FAILED, 'message' => 'save refused'],
			],
			$this->entries
		);
		$this->assertIsString($this->entries[0]['message']);
		$this->assertSame('7', $this->entries[1]['preData']['age']);
		$this->assertCount(2, $this->saves);
		foreach ($this->saves as $args) {
			$this->assertSame(2, $args[2]);
			$this->assertSame($this->schema, $args[3]);
			$this->assertFalse($args[5]);
			$this->assertFalse($args[6]);
		}

		$this->assertSame(7, $this->saves[0][0]['age']);
		$this->assertSame('u6', $this->saves[0][4]);
		$this->assertSame(['migrated' => 1, 'unchanged' => 1, 'failed' => 2], $run->getReport());
		$this->assertSame(9, $run->getCursor());
		$this->assertSame(4, $run->getProcessed());
		$this->assertSame(SchemaRun::STATE_RUNNING, $run->getState());
		$this->assertSame(1, $this->runSaves);
	}

	public function testStopOnErrorHaltsOnAPlanFailure(): void {
		$run = $this->makeRun(['stopOnError' => true]);
		$this->objectService->method('findAll')->willReturn($this->mixedBatch());
		$this->objectService->expects($this->never())->method('saveObject');

		$this->assertFalse($this->service->processBatch($run, 100));

		$this->assertCount(1, $this->entries);
		$this->assertSame(['migrated' => 0, 'unchanged' => 0, 'failed' => 1], $run->getReport());
		$this->assertSame(4, $run->getCursor());
		$this->assertSame(1, $run->getProcessed());
		$this->assertSame(SchemaRun::STATE_FAILED, $run->getState());
		$this->assertSame(1, $this->runSaves);
	}

	public function testStopOnErrorHaltsOnARefusedSave(): void {
		$run = $this->makeRun(['stopOnError' => true]);
		$batch = $this->mixedBatch();
		$this->objectService->method('findAll')->willReturn([$batch[1], $batch[3], $batch[2]]);
		$this->saveRefusesU8();

		$this->assertFalse($this->service->processBatch($run, 100));

		$this->assertSame(
			[['runId' => 7, 'objectUuid' => 'u8', 'outcome' => SchemaRunEntry::OUTCOME_FAILED, 'message' => 'save refused']],
			$this->entries
		);
		$this->assertSame(['migrated' => 0, 'unchanged' => 1, 'failed' => 1], $run->getReport());
		$this->assertSame(9, $run->getCursor());
		$this->assertSame(2, $run->getProcessed());
		$this->assertSame(SchemaRun::STATE_FAILED, $run->getState());
	}

	public function testAnEmptyBatchCompletesWithoutTouchingTheReport(): void {
		$run = $this->makeRun();
		$this->objectService->method('findAll')->willReturn([]);

		$this->assertFalse($this->service->processBatch($run, 100));
		$this->assertSame(SchemaRun::STATE_COMPLETED, $run->getState());
		$this->assertSame(3, $run->getCursor());
		$this->assertSame([], $this->entries);
	}

	private function entry(string $uuid, string $postVersion): SchemaRunEntry {
		$entry = new SchemaRunEntry();
		$entry->setObjectUuid($uuid);
		$entry->setOutcome(SchemaRunEntry::OUTCOME_MIGRATED);
		$entry->setPreData(['name' => $uuid]);
		$entry->setPostVersion($postVersion);
		return $entry;
	}

	public function testRollbackReportsEachOutcome(): void {
		$run = $this->makeRun([], SchemaRun::STATE_COMPLETED);
		$run->setReport(['migrated' => 4]);
		$this->runMapper->method('find')->willReturn($run);
		$this->runEntryMapper->method('findByRun')->willReturn(
			[$this->entry('gone', '1'), $this->entry('edited', '1.0.1'), $this->entry('ok', '1.0.1'), $this->entry('refused', '1.0.1')]
		);
		$finds = [];
		$this->objectService->method('find')->willReturnCallback(
			function (...$args) use (&$finds): ?ObjectEntity {
				$args    = FindCallShape::find(args: $args);
				$finds[] = $args;
				return match ($args[0]) {
					'gone' => null,
					'edited' => $this->obj(1, 'edited', [], '1.0.2'),
					default => $this->obj(1, $args[0], [], '1.0.1'),
				};
			}
		);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (...$args): ObjectEntity {
				$this->saves[] = $args;
				if ($args[4] === 'refused') {
					throw new RuntimeException('restore refused');
				}

				return $this->obj(1, 'ok', [], '1.0.3');
			}
		);

		$result = $this->service->rollback(7, 'admin');

		$this->assertSame($run, $result);
		$this->assertSame(
			[
				['runId' => 7, 'objectUuid' => 'gone', 'outcome' => SchemaRunEntry::OUTCOME_CONFLICT, 'message' => 'Object no longer exists.'],
				[
					'runId' => 7, 'objectUuid' => 'edited', 'outcome' => SchemaRunEntry::OUTCOME_CONFLICT,
					'message' => 'Object modified after migration (current version 1.0.2).',
				],
				['runId' => 7, 'objectUuid' => 'ok', 'outcome' => SchemaRunEntry::OUTCOME_RESTORED, 'message' => null],
				['runId' => 7, 'objectUuid' => 'refused', 'outcome' => SchemaRunEntry::OUTCOME_CONFLICT, 'message' => 'restore refused'],
			],
			$this->entries
		);
		$this->assertCount(4, $finds);
		foreach ($finds as $args) {
			$this->assertSame(2, $args[3]);
			$this->assertSame($this->schema, $args[4]);
			$this->assertFalse($args[5]);
			$this->assertFalse($args[6]);
		}

		$this->assertCount(2, $this->saves);
		$this->assertSame(['name' => 'ok'], $this->saves[0][0]);
		$this->assertFalse($this->saves[0][5]);
		$this->assertFalse($this->saves[0][6]);
		$this->assertSame(['migrated' => 4, 'rollback' => ['restored' => 1, 'conflicts' => 3]], $run->getReport());
		$this->assertSame(SchemaRun::STATE_ROLLED_BACK, $run->getState());
		$this->assertSame(1, $this->runSaves);
	}
}
