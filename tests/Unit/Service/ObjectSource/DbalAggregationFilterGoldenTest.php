<?php

/**
 * Golden test: the filter operators of a DBAL pushed-down aggregation.
 *
 * Pins what each `{column: {op: value}}` criterion matches, including the empty
 * `in` / `notIn` lists, a non-list operand, an unknown operator, several operators
 * on one column and a filter combined with groupBy. Recorded against the provider
 * before the aggregation moved into DbalAggregator.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use Doctrine\DBAL\DriverManager;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapper;
use OCA\OpenRegister\Service\Dbal\DbalConnectionFactory;
use OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceProvider
 */
final class DbalAggregationFilterGoldenTest extends TestCase {

	/**
	 * The SQLite file the facts table lives in.
	 *
	 * @var string
	 */
	private static string $dbPath;

	/**
	 * Build the facts table once for the class.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		self::$dbPath = sys_get_temp_dir() . '/or-dbal-aggregation-filter-golden-' . getmypid() . '.sqlite';
		if (file_exists(self::$dbPath) === true) {
			unlink(self::$dbPath);
		}

		$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => self::$dbPath]);
		$connection->executeStatement('CREATE TABLE facts (id INTEGER PRIMARY KEY, status VARCHAR(16), amount INTEGER)');
		$rows = [[1, 'open', 10], [2, 'open', 20], [3, 'closed', 5], [4, 'closed', null], [5, null, 7]];
		foreach ($rows as $row) {
			$connection->insert('facts', ['id' => $row[0], 'status' => $row[1], 'amount' => $row[2]]);
		}

		$connection->close();
	}//end setUpBeforeClass()

	/**
	 * Remove the database after the class.
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void {
		if (file_exists(self::$dbPath) === true) {
			unlink(self::$dbPath);
		}
	}//end tearDownAfterClass()

	/**
	 * Scenario => [metric, field, filter, groupBy, expected answer].
	 *
	 * @return array<string, array{string, ?string, array<string, mixed>, ?array<string, mixed>, array<string, mixed>}>
	 */
	public static function scenarios(): array {
		return [
			'empty in matches nothing'          => ['count', null, ['status' => ['in' => []]], null, ['value' => 0]],
			'empty notIn excludes nothing'      => ['count', null, ['status' => ['notIn' => []]], null, ['value' => 5]],
			'notIn drops the listed and null'   => ['count', null, ['status' => ['notIn' => ['open']]], null, ['value' => 2]],
			'in with a scalar operand is empty' => ['count', null, ['status' => ['in' => 'open']], null, ['value' => 0]],
			'in reads the list values'          => ['count', null, ['status' => ['in' => ['a' => 'open']]], null, ['value' => 2]],
			'lt'                                => ['count', null, ['amount' => ['lt' => 10]], null, ['value' => 2]],
			'lte'                               => ['count', null, ['amount' => ['lte' => 10]], null, ['value' => 3]],
			'ne skips null'                     => ['count', null, ['amount' => ['ne' => 10]], null, ['value' => 3]],
			'two operators on one column'       => ['count', null, ['amount' => ['gte' => 5, 'lt' => 20]], null, ['value' => 3]],
			'unknown operator is ignored'       => ['count', null, ['amount' => ['like' => 1]], null, ['value' => 5]],
			'sum with in'                       => ['sum', 'amount', ['status' => ['in' => ['closed']]], null, ['value' => 5.0]],
			'grouped with gt'                   => [
				'count',
				null,
				['amount' => ['gt' => 5]],
				['field' => 'status'],
				['groups' => [['key' => null, 'value' => 1], ['key' => 'open', 'value' => 2]]],
			],
		];
	}//end scenarios()

	/**
	 * Each scenario answers what the provider answered before the refactor.
	 *
	 * @param string                    $metric   The metric.
	 * @param string|null               $field    The value field.
	 * @param array<string, mixed>      $filter   The filter.
	 * @param array<string, mixed>|null $groupBy  The groupBy.
	 * @param array<string, mixed>      $expected The recorded answer.
	 *
	 * @return void
	 */
	#[DataProvider('scenarios')]
	public function testTheFilterMatchesTheGolden(string $metric, ?string $field, array $filter, ?array $groupBy, array $expected): void {
		$schema = new Schema();
		$schema->setId(11);
		$schema->setSlug('facts');
		$schema->setProperties(['id' => ['type' => 'integer'], 'status' => ['type' => 'string'], 'amount' => ['type' => 'integer']]);
		$register = new Register();
		$register->setId(1);

		$answer = $this->provider()->aggregate(
			register: $register,
			schema: $schema,
			metric: $metric,
			field: $field,
			filter: $filter,
			groupBy: $groupBy,
			dateBucket: null,
			config: ['sourceId' => '9', 'table' => 'facts', 'idColumn' => 'id']
		);

		$this->assertSame($expected, $answer);
	}//end testTheFilterMatchesTheGolden()

	/**
	 * The provider over the SQLite source.
	 *
	 * @return DbalObjectSourceProvider
	 */
	private function provider(): DbalObjectSourceProvider {
		$source = new Source();
		$source->setId(9);
		$source->setUuid('00000000-0000-0000-0000-000000000000');
		$source->setType('database');
		$source->setAuthConfig(['driver' => 'pdo_sqlite', 'path' => self::$dbPath]);

		$sourceMapper = $this->createMock(SourceMapper::class);
		$sourceMapper->method('findForSystem')->willReturn($source);

		$factory = $this->createMock(DbalConnectionFactory::class);
		$factory->method('isDriverAvailable')->willReturn(true);
		$factory->method('getConnection')->willReturnCallback(
			static fn () => DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => self::$dbPath])
		);

		return new DbalObjectSourceProvider(sourceMapper: $sourceMapper, connectionFactory: $factory, logger: new NullLogger());
	}//end provider()
}//end class
