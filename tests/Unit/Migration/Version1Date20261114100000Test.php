<?php

/**
 * Unit tests for the calendar link dedup + unique index migration.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/calendar-leaf-inbound/specs/integration-calendar/spec.md#requirement-linking-is-idempotent-req-cal-inb-003
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Db\CalendarLinkDuplicates;
use OCA\OpenRegister\Migration\Version1Date20261114100000;
use OCP\DB\IResult;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Schema\IIndex;
use OCP\DB\Schema\ITable;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Version1Date20261114100000::class)]
#[UsesClass(CalendarLinkDuplicates::class)]
class Version1Date20261114100000Test extends TestCase {

	/** @var array<int, array<int, int>> Every id list a DELETE was given. */
	private array $deleted = [];

	private function index(array $columns, bool $unique): IIndex {
		$index = $this->createMock(IIndex::class);
		$index->method('getColumns')->willReturn($columns);
		$index->method('isUnique')->willReturn($unique);
		return $index;
	}

	private function schema(?ITable $table): ISchemaWrapper {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_calendar_links')->willReturn($table !== null);
		if ($table !== null) {
			$schema->method('getTable')->with('openregister_calendar_links')->willReturn($table);
		}

		return $schema;
	}

	private function table(array $indexes): ITable {
		$table = $this->createMock(ITable::class);
		$table->method('getIndexes')->willReturn($indexes);
		return $table;
	}

	private function connection(array $rows): IDBConnection {
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));

		$select = $this->createMock(IQueryBuilder::class);
		$select->method('select')->with('id', 'object_uuid', 'event_uid', 'calendar_uri', 'linked_at')->willReturnSelf();
		$select->method('from')->with('openregister_calendar_links')->willReturnSelf();
		$select->method('executeQuery')->willReturn($result);

		$connection = $this->createMock(IDBConnection::class);
		$builders   = [$select];
		$connection->method('getQueryBuilder')->willReturnCallback(
			function () use (&$builders): IQueryBuilder {
				return array_shift($builders) ?? $this->deleteBuilder();
			}
		);

		return $connection;
	}

	private function deleteBuilder(): IQueryBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('in')->willReturnCallback(static fn (string $column, string $param): string => $column.' IN '.$param);

		$delete = $this->createMock(IQueryBuilder::class);
		$delete->method('delete')->with('openregister_calendar_links')->willReturnSelf();
		$delete->method('expr')->willReturn($expr);
		$delete->method('createNamedParameter')->willReturnCallback(
			function (array $ids, int $type): string {
				$this->assertSame(IQueryBuilder::PARAM_INT_ARRAY, $type);
				$this->deleted[] = $ids;
				return ':ids';
			}
		);
		$delete->method('where')->with('id IN :ids')->willReturnSelf();
		$delete->method('executeStatement')->willReturnCallback(fn (): int => count(end($this->deleted)));

		return $delete;
	}

	private static function row(int $id, string $linkedAt): array {
		return ['id' => $id, 'object_uuid' => 'o1', 'event_uid' => 'e1', 'calendar_uri' => 'personal', 'linked_at' => $linkedAt];
	}

	public function testAnInstallWithTheIndexIsLeftAlone(): void {
		$table = $this->table([$this->index(['id'], true), $this->index(['object_uuid', 'calendar_uri', 'event_uid'], true)]);
		$table->expects($this->never())->method('addUniqueIndex');
		$connection = $this->createMock(IDBConnection::class);
		$connection->expects($this->never())->method('getQueryBuilder');
		$output    = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('info');
		$migration = new Version1Date20261114100000($connection);

		$schema = $this->schema($table);
		$migration->preSchemaChange($output, fn () => $schema, []);
		$this->assertSame($schema, $migration->changeSchema($output, fn () => $schema, []));
	}

	public function testAUniqueIndexUnderAnotherNameAndColumnOrderCounts(): void {
		$table = $this->table([$this->index(['EVENT_UID', 'object_uuid', 'calendar_uri'], true)]);
		$table->expects($this->never())->method('addUniqueIndex');
		$migration = new Version1Date20261114100000($this->createMock(IDBConnection::class));

		$schema    = $this->schema($table);
		$this->assertSame($schema, $migration->changeSchema($this->createMock(IOutput::class), fn () => $schema, []));
	}

	public function testANonUniqueOrWiderIndexDoesNotCount(): void {
		$table = $this->table(
			[
				$this->index(['object_uuid', 'calendar_uri', 'event_uid'], false),
				$this->index(['object_uuid', 'calendar_uri', 'event_uid', 'id'], true),
			]
		);
		$table->expects($this->once())->method('addUniqueIndex')
			->with(['object_uuid', 'calendar_uri', 'event_uid'], 'idx_cal_link_unique')
			->willReturnSelf();
		$schema = $this->schema($table);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Added idx_cal_link_unique on openregister_calendar_links.');

		$migration = new Version1Date20261114100000($this->createMock(IDBConnection::class));

		$this->assertSame($schema, $migration->changeSchema($output, fn () => $schema, []));
	}

	public function testAMissingTableIsLeftAlone(): void {
		$connection = $this->createMock(IDBConnection::class);
		$connection->expects($this->never())->method('getQueryBuilder');
		$migration = new Version1Date20261114100000($connection);

		$schema    = $this->schema(null);
		$migration->preSchemaChange($this->createMock(IOutput::class), fn () => $schema, []);
		$this->assertSame($schema, $migration->changeSchema($this->createMock(IOutput::class), fn () => $schema, []));
	}

	public function testWithoutTheIndexTheSurplusRowsAreDeletedKeepingTheOldest(): void {
		$table  = $this->table([]);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')
			->with('Removed 2 duplicate calendar link row(s), keeping the oldest of each.');

		$rows = [
			self::row(4, '2026-02-01 00:00:00'),
			self::row(2, '2026-01-01 00:00:00'),
			self::row(9, '2026-03-01 00:00:00'),
			['id' => 5, 'object_uuid' => 'o2', 'event_uid' => 'e1', 'calendar_uri' => 'personal', 'linked_at' => '2026-09-01 00:00:00'],
		];

		(new Version1Date20261114100000($this->connection($rows)))
			->preSchemaChange($output, fn () => $this->schema($table), []);

		$this->assertSame([[4, 9]], $this->deleted);
	}

	public function testATableWithoutDuplicatesDeletesNothing(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')
			->with('Removed 0 duplicate calendar link row(s), keeping the oldest of each.');

		(new Version1Date20261114100000($this->connection([self::row(1, '2026-01-01 00:00:00')])))
			->preSchemaChange($output, fn () => $this->schema($this->table([])), []);

		$this->assertSame([], $this->deleted);
	}

	public function testSurplusIsDeletedInChunks(): void {
		$rows = [];
		for ($id = 1; $id <= 1201; $id++) {
			$rows[] = self::row($id, '2026-01-01 00:00:00');
		}

		(new Version1Date20261114100000($this->connection($rows)))
			->preSchemaChange($this->createMock(IOutput::class), fn () => $this->schema($this->table([])), []);

		$this->assertSame([500, 500, 200], array_map('count', $this->deleted));
		$this->assertSame(2, $this->deleted[0][0]);
		$this->assertSame(1201, $this->deleted[2][199]);
	}
}
