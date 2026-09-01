<?php

/**
 * The parallel-streams migration: additive, guarded on existence, and its
 * back-fill idempotent.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-firing-must-exclusively-claim-every-place-it-touches
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Migration;

use Doctrine\DBAL\Schema\Table;
use OCA\OpenRegister\Migration\Version1Date20260901120000;
use OCP\DB\IResult;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The migration.
 *
 * @covers \OCA\OpenRegister\Migration\Version1Date20260901120000
 */
class Version1Date20260901120000Test extends TestCase {

	private IDBConnection&MockObject $db;

	private IQueryBuilder&MockObject $qb;

	/** @var array<int, array<string, mixed>> rows `fetchAll()` answers */
	private array $rows = [];

	/** @var array<string, mixed> scalars `fetchOne()` answers, in call order */
	private array $scalars = [];

	private int $statements = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->qb = $this->createMock(IQueryBuilder::class);
		foreach (['insert', 'values', 'update', 'set', 'select', 'from', 'where', 'andWhere'] as $fluent) {
			$this->qb->method($fluent)->willReturnSelf();
		}

		$this->qb->method('createNamedParameter')->willReturn(':p');
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('in')->willReturn('in');
		$expr->method('isNull')->willReturn('isNull');
		$this->qb->method('expr')->willReturn($expr);
		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('count')->willReturn($this->createMock(IQueryFunction::class));
		$func->method('max')->willReturn($this->createMock(IQueryFunction::class));
		$this->qb->method('func')->willReturn($func);
		$this->qb->method('executeStatement')->willReturnCallback(function (): int {
			$this->statements++;
			return 1;
		});
		$this->qb->method('executeQuery')->willReturnCallback(function (): IResult {
			$result = $this->createMock(IResult::class);
			$result->method('fetchAll')->willReturn($this->rows);
			$result->method('fetchOne')->willReturnCallback(fn (): mixed => array_shift($this->scalars));
			return $result;
		});
		$this->db->method('getQueryBuilder')->willReturn($this->qb);
	}//end setUp()

	/**
	 * A table mock that reports nothing present and records what gets added.
	 *
	 * @return Table&MockObject The table.
	 */
	private function emptyTable(): Table&MockObject {
		$table = $this->createMock(Table::class);
		$table->method('hasColumn')->willReturn(false);
		$table->method('hasIndex')->willReturn(false);

		return $table;
	}//end emptyTable()

	public function testChangeSchemaCreatesBothTablesAndAddsTheFourColumns(): void {
		$claims = $this->emptyTable();
		$claims->expects($this->exactly(7))->method('addColumn');
		$claims->expects($this->once())->method('addUniqueIndex')->with(['run_uuid', 'place'], 'or_flowclaim_place_uq');
		$claims->expects($this->exactly(2))->method('addIndex');

		$streams = $this->emptyTable();
		$streams->expects($this->exactly(12))->method('addColumn');
		$streams->expects($this->once())->method('addUniqueIndex')->with(['run_uuid', 'stream_id'], 'or_flowstream_id_uq');

		$runs = $this->emptyTable();
		$runs->expects($this->exactly(2))->method('addColumn');
		$steps = $this->emptyTable();
		$steps->expects($this->exactly(2))->method('addColumn');
		$steps->expects($this->once())->method('addIndex')->with(['run_uuid', 'ordinal_path', 'sequence'], 'or_flowstep_ordinal_idx');

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(
			static fn (string $name): bool => in_array($name, ['openregister_flow_runs', 'openregister_flow_steps'], true)
		);
		$schema->method('createTable')->willReturnCallback(
			static fn (string $name): Table => ($name === 'openregister_flow_claims') ? $claims : $streams
		);
		$schema->method('getTable')->willReturnCallback(
			static fn (string $name): Table => ($name === 'openregister_flow_runs') ? $runs : $steps
		);

		$output = $this->createMock(IOutput::class);
		$migration = new Version1Date20260901120000($this->db);
		$this->assertSame($schema, $migration->changeSchema($output, static fn (): ISchemaWrapper => $schema, []));
	}//end testChangeSchemaCreatesBothTablesAndAddsTheFourColumns()

	public function testChangeSchemaIsANoOpWhenEverythingExists(): void {
		$present = $this->createMock(Table::class);
		$present->method('hasColumn')->willReturn(true);
		$present->method('hasIndex')->willReturn(true);
		$present->expects($this->never())->method('addColumn');

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$schema->expects($this->never())->method('createTable');
		$schema->method('getTable')->willReturn($present);

		$migration = new Version1Date20260901120000($this->db);
		$this->assertNull($migration->changeSchema($this->createMock(IOutput::class), static fn (): ISchemaWrapper => $schema, []));
	}//end testChangeSchemaIsANoOpWhenEverythingExists()

	public function testBackFillSeedsOneStreamPerMarkedPlaceAndStampsSteps(): void {
		// One in-flight run with two marked places and no streams yet; its
		// highest step sequence is 4, so the streams continue from 5.
		$this->rows = [['uuid' => 'run-1', 'status' => 'suspended', 'marking' => json_encode(['zeta' => 1, 'alpha' => 1]), 'resume_at' => '2026-09-02 08:00:00']];
		$this->scalars = ['0', '4'];

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with($this->stringContains('1 in-flight run(s) given streams'));

		$migration = new Version1Date20260901120000($this->db);
		$migration->postSchemaChange($output, static fn (): ?ISchemaWrapper => null, []);

		// Two stream inserts, one per-run step stamp, one global stamp.
		$this->assertSame(4, $this->statements);
	}//end testBackFillSeedsOneStreamPerMarkedPlaceAndStampsSteps()

	public function testBackFillIsIdempotentAndSkipsQueuedRunsWithoutAMarking(): void {
		$this->rows = [
			['uuid' => 'run-seeded', 'status' => 'running', 'marking' => json_encode(['a' => 1]), 'resume_at' => null],
			['uuid' => 'run-queued', 'status' => 'queued', 'marking' => null, 'resume_at' => null],
		];
		// run-seeded already has streams (count 1); run-queued has none but no marking either.
		$this->scalars = ['1', '0'];

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with($this->stringContains('0 in-flight run(s) given streams'));

		$migration = new Version1Date20260901120000($this->db);
		$migration->postSchemaChange($output, static fn (): ?ISchemaWrapper => null, []);

		// Only the global stamp of unstamped steps ran; nothing was seeded twice.
		$this->assertSame(1, $this->statements);
	}//end testBackFillIsIdempotentAndSkipsQueuedRunsWithoutAMarking()

	public function testStreamIdsAreDeterministicPerRunAndOrdinal(): void {
		$this->assertSame(
			Version1Date20260901120000::streamIdFor(runUuid: 'run-1', ordinal: 1),
			Version1Date20260901120000::streamIdFor(runUuid: 'run-1', ordinal: 1)
		);
		$this->assertNotSame(
			Version1Date20260901120000::streamIdFor(runUuid: 'run-1', ordinal: 1),
			Version1Date20260901120000::streamIdFor(runUuid: 'run-1', ordinal: 2)
		);
		$this->assertSame(32, strlen(Version1Date20260901120000::streamIdFor(runUuid: 'run-1', ordinal: 1)));
	}//end testStreamIdsAreDeterministicPerRunAndOrdinal()
}//end class
