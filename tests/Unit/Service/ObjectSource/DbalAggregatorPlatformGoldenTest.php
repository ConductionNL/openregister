<?php

/**
 * Golden test for the SQL DbalAggregator builds per platform: the date-bucket
 * expression for every gap on PostgreSQL, MySQL and SQLite, and the operator
 * filters (in, notIn, comparisons, unknown operators) on every query shape,
 * with their bound parameters in order.
 *
 * The SQLite-file goldens cannot reach the PostgreSQL and MySQL bucket
 * branches; this test pins them through a connection that records the SQL.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers take positional args by convention.

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Result;
use OCA\OpenRegister\Service\ObjectSource\DbalAggregation;
use OCA\OpenRegister\Service\ObjectSource\DbalAggregationShape;
use OCA\OpenRegister\Service\ObjectSource\DbalAggregator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The aggregator's SQL per platform, pinned.
 */
class DbalAggregatorPlatformGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/dbal-aggregator-platform-golden.json';

	private const COLUMNS = ['status', 'amount', 'created'];

	private const FILTER = [
		'status' => ['in' => ['open', 'closed'], 'notIn' => [], 'like' => 'x'],
		'amount' => ['gt' => 1, 'gte' => 2, 'lt' => 9, 'lte' => 8, 'ne' => 5, 'eq' => 3],
		'created' => 'today',
		'secret' => 'never',
		7 => 'numeric key',
	];

	/**
	 * Run one aggregation on a recording connection.
	 *
	 * @param AbstractPlatform     $platform The platform the connection reports.
	 * @param array<string, mixed> $spec     metric, field, filter, groupBy, dateBucket.
	 *
	 * @return array<string, mixed> The answer and every statement executed.
	 */
	private function record(AbstractPlatform $platform, array $spec): array {
		$statements = [];
		$result = $this->createMock(Result::class);
		$result->method('fetchAllAssociative')->willReturn([['bucket' => '2026-01-05 00:00:00', 'grpkey' => 'open', 'value' => '3']]);
		$result->method('fetchOne')->willReturn('4');

		$connection = $this->getMockBuilder(Connection::class)
			->disableOriginalConstructor()
			->onlyMethods(['getDatabasePlatform', 'executeQuery'])
			->getMock();
		$connection->method('getDatabasePlatform')->willReturn($platform);
		$connection->method('executeQuery')->willReturnCallback(
			static function (string $sql, array $params = []) use (&$statements, $result): Result {
				$statements[] = ['sql' => $sql, 'params' => $params];
				return $result;
			}
		);

		$aggregator = new DbalAggregator(logger: new NullLogger());
		$query = $aggregator->query(
			connection: $connection,
			table: '"facts"',
			aggregation: new DbalAggregation(
				metric: $spec['metric'],
				field: ($spec['field'] ?? null),
				filter: ($spec['filter'] ?? []),
				columns: self::COLUMNS
			)
		);
		$answer = $aggregator->run(
			query: $query,
			shape: new DbalAggregationShape(groupBy: ($spec['groupBy'] ?? null), dateBucket: ($spec['dateBucket'] ?? null))
		);

		return ['answer' => $answer, 'statements' => $statements];
	}//end record()

	public function testThePlatformSqlMatchesTheGolden(): void {
		$platforms = [
			'postgresql' => new PostgreSQLPlatform(),
			'mysql' => new MySQL80Platform(),
			'sqlite' => new SqlitePlatform(),
		];
		$gaps = ['minute', 'hour', 'day', 'week', 'month', 'quarter', 'year', 'fortnight', 'DAY', ''];

		$actual = [];
		foreach ($platforms as $name => $platform) {
			foreach ($gaps as $gap) {
				$bucket = ['field' => 'created', 'start' => '2026-01-01', 'end' => '2026-02-01', 'gap' => $gap];
				$actual[$name . ' bucket ' . $gap] = $this->record(
					platform: $platform,
					spec: ['metric' => 'sum', 'field' => 'amount', 'filter' => self::FILTER, 'dateBucket' => $bucket]
				);
			}

			$actual[$name . ' bucket unknown field'] = $this->record(
				platform: $platform,
				spec: ['metric' => 'count', 'dateBucket' => ['field' => 'secret', 'gap' => 'day']]
			);
			$actual[$name . ' bucket without bounds'] = $this->record(
				platform: $platform,
				spec: ['metric' => 'count', 'dateBucket' => ['field' => 'created', 'gap' => 'month']]
			);
			$actual[$name . ' grouped'] = $this->record(
				platform: $platform,
				spec: ['metric' => 'avg', 'field' => 'amount', 'filter' => self::FILTER, 'groupBy' => ['field' => 'status']]
			);
			$actual[$name . ' scalar'] = $this->record(platform: $platform, spec: ['metric' => 'count', 'filter' => self::FILTER]);
			$actual[$name . ' scalar, empty in'] = $this->record(
				platform: $platform,
				spec: ['metric' => 'max', 'field' => 'amount', 'filter' => ['status' => ['in' => [], 'notIn' => ['a']]]]
			);
		}//end foreach

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$recorded = json_decode((string) json_encode($actual, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), true);
		$this->assertSame($expected, $recorded);
	}//end testThePlatformSqlMatchesTheGolden()
}//end class
