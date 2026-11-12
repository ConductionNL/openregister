<?php

/**
 * The DBAL aggregator against a real in-memory SQLite table: the min metric,
 * a grouped null value, a group field that is not a column, a failing query,
 * and an unparseable bucket label kept as is.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Result;
use OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceException;
use OCA\OpenRegister\Service\ObjectSource\DbalAggregation;
use OCA\OpenRegister\Service\ObjectSource\DbalAggregationShape;
use OCA\OpenRegister\Service\ObjectSource\DbalAggregator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * SQLite-backed tests for {@see DbalAggregator}.
 */
class DbalAggregatorSqliteTest extends TestCase {

	/**
	 * The columns the aggregation may touch.
	 *
	 * @var array<int, string>
	 */
	private const COLUMNS = ['status', 'amount', 'note'];

	/**
	 * An in-memory table of facts.
	 *
	 * @return Connection The connection.
	 */
	private function facts(): Connection {
		$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
		$connection->executeStatement('CREATE TABLE facts (status TEXT, amount NUMERIC, note TEXT)');
		$connection->executeStatement("INSERT INTO facts VALUES ('open', 5, NULL), ('open', 2, NULL), ('closed', 9, 'x')");

		return $connection;
	}//end facts()

	/**
	 * Run one aggregation.
	 *
	 * @param Connection $connection The connection.
	 * @param string $metric The metric.
	 * @param string|null $field The value field.
	 * @param DbalAggregationShape $shape The shape.
	 * @param LoggerInterface|null $logger The logger.
	 * @param string $table The table.
	 *
	 * @return array<string, mixed>|null The answer.
	 */
	private function aggregate(Connection $connection, string $metric, ?string $field, DbalAggregationShape $shape, ?LoggerInterface $logger = null, string $table = 'facts'): ?array {
		$aggregator = new DbalAggregator(logger: ($logger ?? new NullLogger()));
		$query = $aggregator->query(
			from: new \OCA\OpenRegister\Service\ObjectSource\DbalFromClause(connection: $connection, table: $table),
			aggregation: new DbalAggregation(metric: $metric, field: $field, filter: [], columns: self::COLUMNS)
		);

		return $aggregator->run(query: $query, shape: $shape);
	}//end aggregate()

	/**
	 * The min metric reads the smallest value.
	 *
	 * @return void
	 */
	public function testMinReadsTheSmallestValue(): void {
		$this->assertSame(['value' => 2.0], $this->aggregate(connection: $this->facts(), metric: 'min', field: 'amount', shape: new DbalAggregationShape(groupBy: null, dateBucket: null)));
		$this->assertSame(['value' => 9.0], $this->aggregate(connection: $this->facts(), metric: 'max', field: 'amount', shape: new DbalAggregationShape(groupBy: null, dateBucket: null)));
	}//end testMinReadsTheSmallestValue()

	/**
	 * A group whose value metric is NULL reads as a null value, not zero.
	 *
	 * @return void
	 */
	public function testAGroupWithANullValueReadsNull(): void {
		$answer = $this->aggregate(connection: $this->facts(), metric: 'max', field: 'note', shape: new DbalAggregationShape(groupBy: ['field' => 'status'], dateBucket: null));

		$byKey = array_column($answer['groups'], 'value', 'key');
		$this->assertSame(['closed' => null, 'open' => null], $byKey);
	}//end testAGroupWithANullValueReadsNull()

	/**
	 * A group field that is not an allowed column answers null and runs nothing.
	 *
	 * @return void
	 */
	public function testAGroupFieldThatIsNotAColumnAnswersNull(): void {
		$this->assertNull($this->aggregate(connection: $this->facts(), metric: 'count', field: null, shape: new DbalAggregationShape(groupBy: ['field' => 'secret'], dateBucket: null)));
	}//end testAGroupFieldThatIsNotAColumnAnswersNull()

	/**
	 * A database error is logged and surfaces as a 502 source exception.
	 *
	 * @return void
	 */
	public function testADatabaseErrorSurfacesAsA502(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringStartsWith('[ObjectSource:dbal-source] aggregation query failed: '));

		try {
			$this->aggregate(connection: $this->facts(), metric: 'count', field: null, shape: new DbalAggregationShape(groupBy: null, dateBucket: null), logger: $logger, table: 'missing');
			$this->fail('A failing query answered.');
		} catch (DbalObjectSourceException $failure) {
			$this->assertSame(502, $failure->getStatusCode());
			$this->assertSame('The external database returned an error.', $failure->getMessage());
		}
	}//end testADatabaseErrorSurfacesAsA502()

	/**
	 * On MySQL, a bucket label that is not a date is kept verbatim while a
	 * date label is normalised to ISO-8601 UTC.
	 *
	 * @return void
	 */
	public function testAnUnparseableBucketLabelIsKept(): void {
		$result = $this->createMock(Result::class);
		$result->method('fetchAllAssociative')->willReturn([['bucket' => 'not a date', 'value' => '3'], ['bucket' => '2026-01-05 00:00:00', 'value' => '1']]);
		$connection = $this->getMockBuilder(Connection::class)
			->disableOriginalConstructor()
			->onlyMethods(['getDatabasePlatform', 'executeQuery'])
			->getMock();
		$connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
		$connection->method('executeQuery')->willReturn($result);
		$previous = date_default_timezone_get();
		date_default_timezone_set('UTC');

		try {
			$answer = $this->aggregate(
				connection: $connection,
				metric: 'count',
				field: null,
				shape: new DbalAggregationShape(groupBy: null, dateBucket: ['field' => 'note', 'gap' => 'day', 'start' => '2026-01-01', 'end' => '2026-02-01'])
			);
		} finally {
			date_default_timezone_set($previous);
		}

		$this->assertSame([['key' => 'not a date', 'value' => 3], ['key' => '2026-01-05T00:00:00Z', 'value' => 1]], $answer['groups']);
	}//end testAnUnparseableBucketLabelIsKept()

	/**
	 * A value metric needs a real allowlisted field; count needs none.
	 *
	 * @return void
	 */
	public function testAValueMetricLacksAFieldUnlessItIsAnAllowedColumn(): void {
		$this->assertFalse((new DbalAggregation(metric: 'count', field: null, filter: [], columns: self::COLUMNS))->lacksValueField());
		$this->assertTrue((new DbalAggregation(metric: 'sum', field: null, filter: [], columns: self::COLUMNS))->lacksValueField());
		$this->assertTrue((new DbalAggregation(metric: 'sum', field: '', filter: [], columns: self::COLUMNS))->lacksValueField());
		$this->assertTrue((new DbalAggregation(metric: 'sum', field: 'secret', filter: [], columns: self::COLUMNS))->lacksValueField());
		$this->assertFalse((new DbalAggregation(metric: 'sum', field: 'amount', filter: [], columns: self::COLUMNS))->lacksValueField());
	}//end testAValueMetricLacksAFieldUnlessItIsAnAllowedColumn()
}//end class
