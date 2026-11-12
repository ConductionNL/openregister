<?php

/**
 * Golden tests for SharedSchemaDedupeService: the repair plan and one split.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/archive/2026-10-30-dedupe-shared-schemas/proposal.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Configuration\ImportedSchemaLookup;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\SchemaImportOptions;
use OCA\OpenRegister\Service\SharedSchema\OwnerCandidates;
use OCA\OpenRegister\Service\SharedSchema\RegisterConfigurationLocator;
use OCA\OpenRegister\Service\SharedSchema\SchemaAttribution;
use OCA\OpenRegister\Service\SharedSchema\SchemaIdSwap;
use OCA\OpenRegister\Service\SharedSchema\SchemaTableMigrator;
use OCA\OpenRegister\Service\SharedSchema\SharedSchemaOwnership;
use OCA\OpenRegister\Service\SharedSchema\SharedSchemaStore;
use OCA\OpenRegister\Service\SharedSchema\SplitTableColumns;
use OCA\OpenRegister\Service\SharedSchemaDedupeService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the current observable behaviour of inspect() and applySplit().
 */
class SharedSchemaDedupeServiceGoldenTest extends TestCase {

	/** @var IDBConnection&MockObject */
	private IDBConnection $db;

	/** @var RegisterMapperInterface&MockObject */
	private RegisterMapperInterface $registerMapper;

	/** @var SchemaMapperInterface&MockObject */
	private SchemaMapperInterface $schemaMapper;

	/** @var SchemaTableMigrator&MockObject */
	private SchemaTableMigrator $migrator;

	/** @var ImportHandler&MockObject */
	private ImportHandler $importHandler;

	/** @var RegisterConfigurationLocator&MockObject */
	private RegisterConfigurationLocator $locator;

	/** @var SchemaAttribution&MockObject */
	private SchemaAttribution $attribution;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	private SharedSchemaDedupeService $service;

	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->registerMapper = $this->createMock(RegisterMapperInterface::class);
		$this->schemaMapper = $this->createMock(SchemaMapperInterface::class);
		$this->migrator = $this->createMock(SchemaTableMigrator::class);
		$this->importHandler = $this->createMock(ImportHandler::class);
		$this->locator = $this->createMock(RegisterConfigurationLocator::class);
		$this->attribution = $this->createMock(SchemaAttribution::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new SharedSchemaDedupeService(
			new SharedSchemaStore($this->db, $this->registerMapper, $this->schemaMapper, $this->migrator),
			new SharedSchemaOwnership($this->importHandler, $this->locator, $this->attribution),
			$this->logger
		);
	}//end setUp()

	private function register(int $id, string $slug, array $schemas): Register {
		$register = new Register();
		$register->setId($id);
		$register->setSlug($slug);
		$register->setApplication('app-' . $slug);
		$register->setOwner('owner-' . $slug);
		$register->setSchemas($schemas);
		return $register;
	}//end register()

	private function schema(int $id, string $slug): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);
		return $schema;
	}//end schema()

	private function arrangeInspect(): Schema {
		$registers = [$this->register(1, 'one', [5]), $this->register(2, 'two', [5, 6]), $this->register(3, 'three', [7])];
		$this->registerMapper->method('findAll')->with(null, null, [], [], [], false, false)->willReturn($registers);
		$entity = $this->schema(5, 'Person');
		$this->attribution->method('indexShared')->with([1 => [5], 2 => [5, 6], 3 => [7]])->willReturn([5 => [1, 2], 9 => [2, 3]]);
		$this->schemaMapper->method('find')->willReturnCallback(
			function (int|string $id, ?array $extend = [], bool $_rbac = true, bool $_multitenancy = true) use ($entity): Schema {
				$this->assertSame([false, false], [$_rbac, $_multitenancy]);
				if ($id === 5) {
					return $entity;
				}

				throw new RuntimeException('gone');
			}
		);
		$this->locator->method('schemasFor')->willReturnCallback(
			fn (Register $r): array => $r->getId() === 1 ? ['person' => ['slug' => 'person', 'v' => 1]] : []
		);
		$this->attribution->method('classify')
			->with([1 => ['slug' => 'person', 'v' => 1], 2 => null], $entity->jsonSerialize())
			->willReturn(['status' => 'attributed', 'matches' => [1]]);
		$this->attribution->method('resolveOwner')
			->with(['status' => 'attributed', 'matches' => [1]], new OwnerCandidates(schemaId: 5, registerIds: [1, 2], keep: ['global' => null]))
			->willReturn(['owner' => 1, 'source' => 'evidence']);
		$this->migrator->method('tableNameFor')->with(2, 5)->willReturn('t_2_5');
		$this->migrator->method('countRows')->with('t_2_5')->willReturn(4);
		$this->migrator->method('planUnmapped')->with(new SplitTableColumns('t_2_5', null, $entity->jsonSerialize()))->willReturn(['x']);
		return $entity;
	}//end arrangeInspect()

	public function testInspectBuildsThePlanAndSkipsDanglingSchemas(): void {
		$this->arrangeInspect();
		$expected = [
			[
				'schemaId' => 5,
				'schemaSlug' => 'Person',
				'registerIds' => [1, 2],
				'status' => 'attributed',
				'matches' => [1],
				'owner' => 1,
				'ownerSource' => 'evidence',
				'splits' => [
					2 => [
						'registerSlug' => 'two',
						'application' => 'app-two',
						'path' => 'clone',
						'definition' => null,
						'table' => 't_2_5',
						'rows' => 4,
						'unmapped' => ['x'],
					],
				],
			],
		];
		$this->assertSame($expected, $this->service->inspect(null, ['global' => null]));
		$this->assertSame($expected, $this->service->inspect(1, ['global' => null]));
		$this->assertSame([], $this->service->inspect(3, ['global' => null]));
	}//end testInspectBuildsThePlanAndSkipsDanglingSchemas()

	public function testApplySplitRefusesRegisterOutsideThePlan(): void {
		$this->db->expects($this->never())->method('beginTransaction');
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Register 4 is not part of this plan.');
		$this->service->applySplit(['schemaId' => 5, 'owner' => 1, 'splits' => [2 => []]], 4);
	}//end testApplySplitRefusesRegisterOutsideThePlan()

	public function testApplySplitRefusesUnattributedPlan(): void {
		$this->db->expects($this->never())->method('beginTransaction');
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Schema 5 is unattributed; pass --keep to name the owner.');
		$this->service->applySplit(['schemaId' => '5', 'owner' => null, 'splits' => [2 => []]], 2);
	}//end testApplySplitRefusesUnattributedPlan()

	public function testApplySplitStrictRefusesUnmappedColumns(): void {
		$this->db->expects($this->never())->method('beginTransaction');
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Strict mode: 2 source column(s) have no destination (a, b).');
		$this->service->applySplitStrictly(['schemaId' => 5, 'owner' => 1, 'splits' => [2 => ['unmapped' => ['a', 'b']]]], 2);
	}//end testApplySplitStrictRefusesUnmappedColumns()

	public function testApplySplitClonePathRelinksMovesAndLogs(): void {
		$register = $this->register(2, 'two', [5, 6]);
		$source = $this->schema(5, 'person');
		$created = $this->schema(11, 'person');
		$this->registerMapper->expects($this->once())->method('find')->with(2, false, false)->willReturn($register);
		$this->schemaMapper->expects($this->once())->method('find')->with(5, [], false, false)->willReturn($source);
		$this->importHandler->expects($this->never())->method('importSchema');
		$expectedClone = $source->jsonSerialize();
		unset($expectedClone['id'], $expectedClone['uuid'], $expectedClone['uri'], $expectedClone['created'], $expectedClone['updated']);
		$expectedClone['application'] = 'app-two';
		$this->schemaMapper->expects($this->once())->method('createFromArray')->with($expectedClone)->willReturn($created);
		$this->attribution->method('replaceSchemaId')->with([5, 6], new SchemaIdSwap(oldId: 5, newId: 11))->willReturn([11, 6]);
		$this->registerMapper->expects($this->once())->method('update')->with($register)->willReturnArgument(0);
		$this->migrator->expects($this->once())->method('migrate')->with(new RegisterSchema(register: $register, schema: $created), 5)
			->willReturn(['rows' => 3, 'unmapped' => ['q'], 'backup' => 'bk']);
		$this->logger->expects($this->once())->method('warning')->with(
			'[SharedSchemaDedupe] Register 2 split off shared schema 5 onto 11 (3 row(s) moved).',
			$this->callback(fn (array $c): bool => $c['register'] === 2 && $c['schema'] === 5 && isset($c['file'], $c['line']))
		);
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');

		$outcome = $this->service->applySplit(['schemaId' => 5, 'owner' => 1, 'splits' => [2 => ['unmapped' => ['q']]]], 2);
		$this->assertSame(['newSchemaId' => 11, 'rows' => 3, 'unmapped' => ['q'], 'backup' => 'bk'], $outcome);
		$this->assertSame([11, 6], $register->getSchemas());
	}//end testApplySplitClonePathRelinksMovesAndLogs()

	public function testApplySplitConfigurationPathUsesImportHandler(): void {
		$register = $this->register(2, 'two', [5]);
		$created = $this->schema(12, 'person');
		$definition = ['slug' => 'person', 'version' => '2.0.0'];
		$this->registerMapper->method('find')->willReturn($register);
		$this->schemaMapper->method('getSlugToIdMap')->willReturn(['person' => 5]);
		$this->importHandler->expects($this->once())->method('importSchema')
			->with(
				new ImportedSchemaLookup(
					data: $definition,
					registerSchemaIds: [],
					options: new SchemaImportOptions(owner: 'owner-two', appId: 'app-two', version: '2.0.0', force: true)
				),
				['person' => 5]
			)
			->willReturn($created);
		$this->schemaMapper->expects($this->never())->method('createFromArray');
		$this->attribution->method('replaceSchemaId')->willReturn([12]);
		$this->migrator->method('migrate')->willReturn(['rows' => 0, 'unmapped' => [], 'backup' => null]);

		$outcome = $this->service->applySplit(['schemaId' => 5, 'owner' => 1, 'splits' => [2 => ['definition' => $definition]]], 2);
		$this->assertSame(['newSchemaId' => 12, 'rows' => 0, 'unmapped' => [], 'backup' => null], $outcome);
	}//end testApplySplitConfigurationPathUsesImportHandler()

	public function testApplySplitUnsavedImportFallsBackToCloneWithDefaultVersion(): void {
		$register = $this->register(2, 'two', [5]);
		$this->registerMapper->method('find')->willReturn($register);
		$this->schemaMapper->method('getSlugToIdMap')->willReturn([]);
		$this->importHandler->expects($this->once())->method('importSchema')
			->with(
				new ImportedSchemaLookup(
					data: ['slug' => 'p'],
					registerSchemaIds: [],
					options: new SchemaImportOptions(owner: 'owner-two', appId: 'app-two', version: '0.0.1', force: true)
				),
				[]
			)
			->willReturn(new Schema());
		$this->schemaMapper->method('find')->willReturn($this->schema(5, 'p'));
		$this->schemaMapper->expects($this->once())->method('createFromArray')->willReturn($this->schema(13, 'p'));
		$this->attribution->method('replaceSchemaId')->willReturn([13]);
		$this->migrator->method('migrate')->willReturn(['rows' => 0, 'unmapped' => [], 'backup' => null]);

		$outcome = $this->service->applySplit(['schemaId' => 5, 'owner' => 1, 'splits' => [2 => ['definition' => ['slug' => 'p']]]], 2);
		$this->assertSame(13, $outcome['newSchemaId']);
	}//end testApplySplitUnsavedImportFallsBackToCloneWithDefaultVersion()

	public function testApplySplitRollsBackAndRethrows(): void {
		$this->registerMapper->method('find')->willThrowException(new RuntimeException('db down'));
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('db down');
		$this->service->applySplit(['schemaId' => 5, 'owner' => 1, 'splits' => [2 => []]], 2);
	}//end testApplySplitRollsBackAndRethrows()
}//end class
