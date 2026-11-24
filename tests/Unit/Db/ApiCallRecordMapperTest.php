<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\ApiCallKeys;
use OCA\OpenRegister\Db\ApiCallRecord;
use OCA\OpenRegister\Db\ApiCallRecordMapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The client is part of a caller record's key (api-client-libraries task 1.1).
 *
 * Runs the mapper against a stand-in table that keeps rows by the five keys
 * of `idx_or_apicall_unique`, so an increment only finds a row whose client
 * matches and an insert of an existing key is refused like the index would.
 *
 * @spec openspec/changes/api-client-libraries/tasks.md#task-1.1
 *
 * @covers \OCA\OpenRegister\Db\ApiCallRecordMapper
 * @uses \OCA\OpenRegister\Db\ApiCallKey
 * @uses \OCA\OpenRegister\Db\ApiCallKeys
 * @uses \OCA\OpenRegister\Db\ApiCallRecord
 */
final class ApiCallRecordMapperTest extends TestCase {

	/**
	 * Call counts by `principal|route|method|api_version|client`.
	 *
	 * @var array<string, int>
	 */
	private array $rows = [];

	public function testTwoClientsOnOneRouteAreTwoRows(): void {
		$mapper = $this->mapper();
		$moment = new DateTime('2026-11-13 12:00:00');

		$mapper->count(key: ApiCallKeys::of('leverancier', '/api/objects/{id}', 'GET', '1', 'openregister-client-ts/1.4.0'), moment: $moment);
		$mapper->count(key: ApiCallKeys::of('leverancier', '/api/objects/{id}', 'GET', '1', 'openregister-client-ts/1.2.0'), moment: $moment);
		$mapper->count(key: ApiCallKeys::of('leverancier', '/api/objects/{id}', 'GET', '1', 'openregister-client-ts/1.4.0'), moment: $moment);

		$this->assertSame(
			[
				'leverancier|/api/objects/{id}|GET|1|openregister-client-ts/1.4.0' => 2,
				'leverancier|/api/objects/{id}|GET|1|openregister-client-ts/1.2.0' => 1,
			],
			$this->rows
		);
	}

	public function testACallWithoutAClientIsItsOwnRow(): void {
		$mapper = $this->mapper();
		$moment = new DateTime('2026-11-13 12:00:00');

		$mapper->count(key: ApiCallKeys::of('medewerker', '/api/objects', 'GET', '1'), moment: $moment);
		$mapper->count(key: ApiCallKeys::of('medewerker', '/api/objects', 'GET', '1', 'openregister-client-python/2.0.0'), moment: $moment);

		$this->assertSame(
			[
				'medewerker|/api/objects|GET|1|' => 1,
				'medewerker|/api/objects|GET|1|openregister-client-python/2.0.0' => 1,
			],
			$this->rows
		);
	}

	public function testTheRecordSerialisesItsClient(): void {
		$record = new ApiCallRecord();
		$record->setPrincipal('leverancier');
		$record->setClient('openregister-client-ts/1.2.0');

		$this->assertSame('openregister-client-ts/1.2.0', $record->jsonSerialize()['client']);
		$this->assertSame('', (new ApiCallRecord())->jsonSerialize()['client']);
	}

	/**
	 * A mapper over the stand-in table.
	 *
	 * @return ApiCallRecordMapper
	 */
	private function mapper(): ApiCallRecordMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->builder());

		return new ApiCallRecordMapper(db: $db);
	}

	/**
	 * A query builder that runs the mapper's update and insert on `$rows`.
	 *
	 * @return IQueryBuilder
	 */
	private function builder(): IQueryBuilder {
		$state = (object)['kind' => '', 'params' => [], 'columns' => []];
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('update')->willReturnCallback(function () use ($qb, $state): IQueryBuilder {
			$state->kind = 'update';
			return $qb;
		});
		$qb->method('insert')->willReturnCallback(function () use ($qb, $state): IQueryBuilder {
			$state->kind = 'insert';
			return $qb;
		});
		foreach (['set', 'where', 'andWhere'] as $name) {
			$qb->method($name)->willReturn($qb);
		}

		$qb->method('setValue')->willReturnCallback(function (string $column, string $param) use ($qb, $state): IQueryBuilder {
			$state->columns[$column] = $state->params[$param];
			return $qb;
		});
		$qb->method('getColumnName')->willReturnArgument(0);
		$qb->method('createFunction')->willReturnArgument(0);
		$qb->method('createNamedParameter')->willReturnCallback(function (mixed $value) use ($state): string {
			$name = ':p'.count($state->params);
			$state->params[$name] = $value;
			return $name;
		});
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(function (string $column, string $param) use ($state): string {
			$state->columns[$column] = $state->params[$param];
			return $column;
		});
		$qb->method('expr')->willReturn($expr);
		$qb->method('executeStatement')->willReturnCallback(fn (): int => $this->execute(state: $state));
		$qb->method('getLastInsertId')->willReturn(1);

		return $qb;
	}

	/**
	 * Run one statement on the stand-in table.
	 *
	 * @param object $state The statement's kind and columns.
	 *
	 * @return int Rows affected.
	 */
	private function execute(object $state): int {
		$c = $state->columns;
		// Columns the insert leaves to their defaults, as the entity does
		// for values equal to its own defaults.
		$key = implode('|', [$c['principal'] ?? '', $c['route'] ?? '', $c['method'] ?? 'GET', $c['api_version'] ?? '1', $c['client'] ?? '']);
		if ($state->kind === 'update') {
			if (isset($this->rows[$key]) === false) {
				return 0;
			}

			$this->rows[$key]++;
			return 1;
		}

		if (isset($this->rows[$key]) === true) {
			throw new RuntimeException('duplicate key '.$key);
		}

		$this->rows[$key] = 1;
		return 1;
	}
}
