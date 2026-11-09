<?php

declare(strict_types=1);

/**
 * Golden tests pinning what the retention scan reads, keeps, skips and reports.
 *
 * Written against the code before a maintainability refactor. The scan feeds
 * every retention sweep (destruction, transfer, legal-hold listing), so which
 * tables it reads, in which order, what it pages over, where it stops and what
 * it says when it stops must not move.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Archival;

use Exception;
use OCA\OpenRegister\Service\Archival\RetentionScanStores;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Archival\RetentionRowScanner;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden outcomes of RetentionRowScanner::scan().
 */
class RetentionRowScannerGoldenTest extends TestCase {

	/** @var array<string, array<int, array<string, mixed>>> Rows per table. */
	private array $rows = [];

	/** @var array<int, string> Every query, as "table select retentionColumn offset limit". */
	private array $queries = [];

	/** @var array<int, array{0: string, 1: string, 2: array<string, mixed>}> Every log call. */
	private array $logged = [];

	/** @var array<string, bool> Tables whose query throws. */
	private array $broken = [];

	/**
	 * A scanner over $this->rows.
	 *
	 * @param array<int, mixed>|null $registers The registers findAll returns, or null to throw.
	 *
	 * @return RetentionRowScanner The scanner.
	 */
	private function scanner(?array $registers): RetentionRowScanner {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->queryBuilder());

		$registerMapper = $this->createMock(RegisterMapperInterface::class);
		if ($registers === null) {
			$registerMapper->method('findAll')->willThrowException(new Exception('registers down'));
		} else {
			$registerMapper->method('findAll')->willReturn($registers);
		}

		$schemaMapper = $this->createMock(SchemaMapperInterface::class);
		$schemaMapper->method('find')->willReturnCallback(
			static function ($id): Schema {
				if ((int)$id === 99) {
					throw new Exception('schema 99 gone');
				}

				$schema = new Schema();
				$schema->setId((int)$id);
				return $schema;
			}
		);

		$objectMapper = $this->createMock(MagicMapperInterface::class);
		$objectMapper->method('tableExistsForRegisterSchema')->willReturnCallback(
			static fn (Register $register, Schema $schema): bool => $schema->getId() !== 3
		);
		$objectMapper->method('getTableNameForRegisterSchema')->willReturnCallback(
			static fn (Register $register, Schema $schema): string => 'magic_' . min($schema->getId(), 4)
		);
		$objectMapper->method('find')->willReturnCallback(
			function ($id, $register, $schema, ...$flags): ObjectEntity {
				$this->assertSame([false, false, false], $flags);
				if ((int)$id % 7 === 0) {
					throw new Exception('unreadable');
				}

				$object = new ObjectEntity();
				$object->setUuid('m' . $schema->getId() . '-' . $id);
				return $object;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'info', 'error', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function ($message, array $context = []) use ($level): void {
					unset($context['exception']);
					$this->logged[] = [$level, (string)$message, $context];
				}
			);
		}

		return new RetentionRowScanner(stores: new RetentionScanStores(db: $db, registerMapper: $registerMapper, schemaMapper: $schemaMapper, objectMapper: $objectMapper), logger: $logger);
	}//end scanner()

	/**
	 * A query builder answering from $this->rows and recording each query.
	 *
	 * @return IQueryBuilder The builder.
	 */
	private function queryBuilder(): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$state = ['select' => '', 'table' => '', 'where' => '', 'offset' => 0, 'limit' => 0];
		$expr->method('isNotNull')->willReturnCallback(static fn (string $column): string => $column);
		$qb->method('expr')->willReturn($expr);
		foreach (['select', 'from', 'where', 'setFirstResult', 'setMaxResults'] as $method) {
			$qb->method($method)->willReturnCallback(
				static function ($value) use ($qb, $method, &$state): IQueryBuilder {
					$keys = ['select' => 'select', 'from' => 'table', 'where' => 'where', 'setFirstResult' => 'offset', 'setMaxResults' => 'limit'];
					$state[$keys[$method]] = $value;
					return $qb;
				}
			);
		}

		$qb->method('executeQuery')->willReturnCallback(
			function () use (&$state): IResult {
				$this->queries[] = implode(' ', [$state['table'], $state['select'], $state['where'], $state['offset'], $state['limit']]);
				if (isset($this->broken[$state['table']]) === true) {
					throw new Exception('table ' . $state['table'] . ' missing');
				}

				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn(array_slice(($this->rows[$state['table']] ?? []), $state['offset'], $state['limit']));
				return $result;
			}
		);

		return $qb;
	}//end queryBuilder()

	/**
	 * Registers: one with schemas 1, 3 (no table), 99 (unreadable), 4, 5 (same table as 4); a non-register.
	 *
	 * @return array<int, mixed> The registers.
	 */
	private function registers(): array {
		$register = new Register();
		$register->setId(8);
		$register->setSchemas([1, 3, 99, 4, 5]);
		$empty = new Register();
		$empty->setId(9);

		return [$register, 'not a register', $empty];
	}//end registers()

	/**
	 * $count magic rows with consecutive ids from 1.
	 *
	 * @param int $count How many.
	 *
	 * @return array<int, array<string, int>> The rows.
	 */
	private function magicRows(int $count): array {
		return array_map(static fn (int $id): array => ['_id' => $id], range(1, $count));
	}//end magicRows()

	public function testItReadsEveryTableOnceAndPagesThroughThem(): void {
		$this->rows['openregister_objects'] = [['id' => 1, 'uuid' => 'legacy-1', 'retention' => '{"a":1}'], ['id' => 2, 'uuid' => 'legacy-2']];
		$this->rows['magic_1'] = $this->magicRows(1001);
		$this->rows['magic_4'] = $this->magicRows(500);

		$result = $this->scanner($this->registers())->scan(
			static fn (ObjectEntity $object): bool => str_ends_with((string)$object->getUuid(), '0') === false,
			5000
		);

		$this->assertFalse($result['truncated']);
		$this->assertSame(1503, $result['scanned']);
		$uuids = array_map(static fn (ObjectEntity $o): string => (string)$o->getUuid(), $result['objects']);
		$this->assertSame(['legacy-1', 'legacy-2', 'm1-1', 'm1-2'], array_slice($uuids, 0, 4));
		$this->assertSame('m4-499', end($uuids));
		// 1001 rows, minus every 7th (unreadable) and every 10th (refused), and likewise for 500.
		$expected = static fn (int $n): int => count(array_filter(range(1, $n), static fn (int $i): bool => $i % 7 !== 0 && $i % 10 !== 0));
		$this->assertCount(2 + $expected(1001) + $expected(500), $uuids);

		$this->assertSame(
			[
				'openregister_objects * retention 0 500',
				'magic_1 _id _retention 0 500',
				'magic_1 _id _retention 500 500',
				'magic_1 _id _retention 1000 500',
				'magic_4 _id _retention 0 500',
				'magic_4 _id _retention 500 500',
			],
			$this->queries
		);
		$this->assertSame(
			[['warning', '[RetentionRowScanner] Skipping register #8 schema #99 in the retention scan: schema 99 gone', ['app' => 'openregister']]],
			$this->logged
		);
	}//end testItReadsEveryTableOnceAndPagesThroughThem()

	public function testTheCapStopsTheRunMidPageAndSaysSo(): void {
		$this->rows['magic_1'] = $this->magicRows(20);
		$this->rows['magic_4'] = $this->magicRows(20);

		$result = $this->scanner($this->registers())->scan(static fn (): bool => true, 5);

		$this->assertTrue($result['truncated']);
		$this->assertSame(5, $result['scanned']);
		$this->assertSame(['m1-1', 'm1-2', 'm1-3', 'm1-4', 'm1-5'], array_map(static fn (ObjectEntity $o): string => (string)$o->getUuid(), $result['objects']));
		$this->assertSame(['openregister_objects * retention 0 500', 'magic_1 _id _retention 0 500'], $this->queries);
		$this->assertSame(
			[
				'warning',
				'[RetentionRowScanner] Retention scan stopped at its cap: 5 matches found, cap is 5 per run, 5 rows inspected. The remainder is picked up by the next run.',
				['app' => 'openregister', 'matched' => 5, 'cap' => 5, 'scanned' => 5, 'truncated' => true],
			],
			end($this->logged)
		);
	}//end testTheCapStopsTheRunMidPageAndSaysSo()

	public function testACapReachedAtTheEndOfASourceStillReadsAsTruncated(): void {
		$this->rows['openregister_objects'] = [['id' => 1, 'uuid' => 'l1'], ['id' => 2, 'uuid' => 'l2']];
		$this->rows['magic_1'] = $this->magicRows(3);

		$result = $this->scanner($this->registers())->scan(static fn (): bool => true, 2);
		$this->assertTrue($result['truncated']);
		$this->assertSame(2, $result['scanned']);
		$this->assertSame(['openregister_objects * retention 0 500'], $this->queries);

		$this->queries = [];
		$this->logged = [];
		$none = $this->scanner([])->scan(static fn (): bool => true, 0);
		$this->assertSame(['objects' => [], 'scanned' => 0, 'truncated' => true], $none);
		$this->assertSame([], $this->queries);
		$this->assertSame('[RetentionRowScanner] Retention scan stopped at its cap: 0 matches found, cap is 0 per run, 0 rows inspected. The remainder is picked up by the next run.', $this->logged[0][1]);
	}//end testACapReachedAtTheEndOfASourceStillReadsAsTruncated()

	public function testAnUnreadableTableOrRegisterListIsSkippedNotFatal(): void {
		$this->broken['openregister_objects'] = true;
		$this->rows['magic_1'] = $this->magicRows(2);
		$this->rows['magic_4'] = $this->magicRows(1);

		$result = $this->scanner($this->registers())->scan(static fn (): bool => true);
		$this->assertFalse($result['truncated']);
		$this->assertSame(3, $result['scanned']);
		$this->assertSame(
			[
				['warning', '[RetentionRowScanner] Skipping register #8 schema #99 in the retention scan: schema 99 gone', ['app' => 'openregister']],
				['warning', '[RetentionRowScanner] Retention scan skipped source "openregister_objects": table openregister_objects missing', ['app' => 'openregister']],
			],
			$this->logged
		);

		$this->logged = [];
		$this->queries = [];
		$this->broken = [];
		$this->rows['openregister_objects'] = [['id' => 3, 'uuid' => 'l3']];
		$only = $this->scanner(null)->scan(static fn (): bool => true);
		$this->assertSame(['l3'], array_map(static fn (ObjectEntity $o): string => (string)$o->getUuid(), $only['objects']));
		$this->assertSame(['openregister_objects * retention 0 500'], $this->queries);
		$this->assertSame(
			[['warning', '[RetentionRowScanner] Could not enumerate registers for the retention scan: registers down', ['app' => 'openregister']]],
			$this->logged
		);
	}//end testAnUnreadableTableOrRegisterListIsSkippedNotFatal()
}//end class
