<?php

/**
 * Golden test for DedupeConfigurationsCommand::execute().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\DedupeConfigurationsCommand;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Pins the console output, the exit code and every query-builder call the command makes.
 *
 * @covers \OCA\OpenRegister\Command\DedupeConfigurationsCommand
 */
class DedupeConfigurationsCommandGoldenTest extends TestCase {

	/**
	 * The recorded query-builder calls.
	 *
	 * @var array<int, array>
	 */
	private array $calls = [];

	private function builder(int $index, array $rows): IQueryBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(
			function (string $column, $value) use ($index): string {
				$this->calls[] = [$index, 'eq', $column, $value];
				return 'EQ(' . $column . ')';
			}
		);
		$expr->method('in')->willReturnCallback(
			function (string $column, $value) use ($index): string {
				$this->calls[] = [$index, 'in', $column, $value];
				return 'IN(' . $column . ')';
			}
		);

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		foreach (['select', 'from', 'orderBy', 'where', 'delete'] as $method) {
			$qb->method($method)->willReturnCallback(
				function (...$args) use ($qb, $index, $method): IQueryBuilder {
					while ($args !== [] && end($args) === null) {
						array_pop($args);
					}

					$this->calls[] = array_merge([$index, $method], $args);
					return $qb;
				}
			);
		}

		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value, $type) use ($index): string {
				$this->calls[] = [$index, 'param', $value, $type];
				return ':p' . $index;
			}
		);

		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($rows);
		$result->method('closeCursor')->willReturnCallback(
			function () use ($index): bool {
				$this->calls[] = [$index, 'closeCursor'];
				return true;
			}
		);
		$qb->method('executeQuery')->willReturnCallback(
			function () use ($index, $result): IResult {
				$this->calls[] = [$index, 'executeQuery'];
				return $result;
			}
		);
		$qb->method('executeStatement')->willReturnCallback(
			function () use ($index): int {
				$this->calls[] = [$index, 'executeStatement'];
				// A distinct count per chunk, so the reported total is visibly the sum of the returns.
				return 100 + $index;
			}
		);

		return $qb;
	}

	private function act(array $rows, array $options): array {
		$index = 0;
		$db    = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(
			function () use (&$index, $rows): IQueryBuilder {
				return $this->builder($index++, $rows);
			}
		);

		$tester = new CommandTester(new DedupeConfigurationsCommand($db));
		$code   = $tester->execute($options);

		return ['code' => $code, 'output' => $tester->getDisplay(), 'calls' => $this->calls];
	}

	private const SELECT = [
		[0, 'select', 'id', 'app', 'title'],
		[0, 'from', 'openregister_configurations'],
		[0, 'orderBy', 'id', 'DESC'],
	];

	public function testNoRows(): void {
		$this->assertSame(
			[
				'code' => 0,
				'output' => "No duplicate configuration rows found.\n",
				'calls' => array_merge(self::SELECT, [[0, 'executeQuery'], [0, 'closeCursor']]),
			],
			$this->act([], [])
		);
	}

	public function testAppFilterWithoutDuplicates(): void {
		$rows = [['id' => 9, 'app' => 'solo'], ['id' => 8, 'app' => ''], ['id' => 7, 'app' => null]];
		$this->assertSame(
			[
				'code' => 0,
				'output' => "No duplicate configuration rows found.\n",
				'calls' => array_merge(
					self::SELECT,
					[
						[0, 'param', 'solo', IQueryBuilder::PARAM_STR],
						[0, 'eq', 'app', ':p0'],
						[0, 'where', 'EQ(app)'],
						[0, 'executeQuery'],
						[0, 'closeCursor'],
					]
				),
			],
			$this->act($rows, ['--app' => 'solo'])
		);
	}

	public function testDryRunReportsWithoutDeleting(): void {
		$rows = [];
		for ($id = 30; $id >= 18; $id--) {
			$rows[] = ['id' => $id, 'app' => 'big'];
		}

		$rows[] = ['id' => 5, 'app' => '42'];
		$rows[] = ['id' => 4, 'app' => '42'];
		$rows[] = ['id' => 3, 'app' => 'single'];

		$this->assertSame(
			[
				'code' => 0,
				'output' => "app=big: keep id=30, 12 duplicate(s): 29, 28, 27, 26, 25, 24, 23, 22, 21, 20, …\n"
					. "app=42: keep id=5, 1 duplicate(s): 4\n"
					. "2 app(s), 13 duplicate row(s) would be deleted. Re-run with --apply.\n",
				'calls' => array_merge(self::SELECT, [[0, 'executeQuery'], [0, 'closeCursor']]),
			],
			$this->act($rows, [])
		);
	}

	public function testApplyDeletesInChunksOf500(): void {
		$rows = [];
		for ($id = 1200; $id >= 600; $id--) {
			$rows[] = ['id' => $id, 'app' => 'a'];
		}

		for ($id = 10; $id >= 1; $id--) {
			$rows[] = ['id' => $id, 'app' => 'b'];
		}

		$result = $this->act($rows, ['--apply' => true]);

		$expectA = range(1199, 600);
		$expectB = range(9, 1);
		$all     = array_merge($expectA, $expectB);
		$chunks  = array_chunk($all, 500);

		$this->assertSame(0, $result['code']);
		$this->assertSame(
			"app=a: keep id=1200, 600 duplicate(s): 1199, 1198, 1197, 1196, 1195, 1194, 1193, 1192, 1191, 1190, …\n"
			. "app=b: keep id=10, 9 duplicate(s): 9, 8, 7, 6, 5, 4, 3, 2, 1\n"
			. "Deleted 203 duplicate configuration row(s) across 2 app(s).\n",
			$result['output']
		);

		$expected = array_merge(self::SELECT, [[0, 'executeQuery'], [0, 'closeCursor']]);
		foreach ($chunks as $offset => $chunk) {
			$index    = $offset + 1;
			$expected = array_merge(
				$expected,
				[
					[$index, 'delete', 'openregister_configurations'],
					[$index, 'param', $chunk, IQueryBuilder::PARAM_INT_ARRAY],
					[$index, 'in', 'id', ':p' . $index],
					[$index, 'where', 'IN(id)'],
					[$index, 'executeStatement'],
				]
			);
		}

		$this->assertSame($expected, $result['calls']);
	}
}
