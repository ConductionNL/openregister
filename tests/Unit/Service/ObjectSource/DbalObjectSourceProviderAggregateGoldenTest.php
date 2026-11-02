<?php

/**
 * DbalObjectSourceProvider::aggregate() answers every metric, filter and grouping exactly as recorded.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapper;
use OCA\OpenRegister\Service\Dbal\DbalConnectionException;
use OCA\OpenRegister\Service\Dbal\DbalConnectionFactory;
use OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Golden record of aggregate() against a real SQLite table: the metric allowlist, the field
 * allowlist, source resolution, connection failure and a missing driver, the date-bucket route,
 * categorical groupBy (including an unknown group column), the date-bucket query on the MySQL dialect, the scalar value, filters (scalar,
 * operator, unknown and non-string columns) and a query error.
 * Set OR_GOLDEN_RECORD=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceProvider
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Source
 * @uses   \OCA\OpenRegister\Service\Dbal\DbalConnectionException
 * @uses   \OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceException
 * @uses   \OCA\OpenRegister\Service\ObjectSource\DbalAggregation
 * @uses   \OCA\OpenRegister\Service\ObjectSource\DbalAggregationQuery
 * @uses   \OCA\OpenRegister\Service\ObjectSource\DbalAggregationShape
 *
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 */
class DbalObjectSourceProviderAggregateGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/dbal-object-source-aggregate-golden.json';

	/** @var string */
	private static string $dbPath;

	/** @var array<int, string> */
	private array $logs = [];

	/**
	 * Build a small facts table once for the class.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		self::$dbPath = sys_get_temp_dir() . '/or-dbal-aggregate-golden-' . getmypid() . '.sqlite';
		if (file_exists(self::$dbPath) === true) {
			unlink(self::$dbPath);
		}

		$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => self::$dbPath]);
		$connection->executeStatement(
			'CREATE TABLE facts (id INTEGER PRIMARY KEY, status VARCHAR(16), amount INTEGER, score REAL, note VARCHAR(32), created VARCHAR(32))'
		);
		$rows = [
			[1, 'open', 10, 1.5, 'a', '2026-01-03T10:00:00Z'],
			[2, 'open', 20, null, 'b', '2026-01-04T11:00:00Z'],
			[3, 'closed', 5, 2.5, null, '2026-02-01T09:00:00Z'],
			[4, 'closed', null, 4.0, 'x', '2026-02-11T09:00:00Z'],
			[5, null, 7, 0.5, 'y', '2026-03-01T09:00:00Z'],
		];
		foreach ($rows as [$id, $status, $amount, $score, $note, $created]) {
			$connection->insert('facts', ['id' => $id, 'status' => $status, 'amount' => $amount, 'score' => $score, 'note' => $note, 'created' => $created]);
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
	 * Every scenario matches the recorded golden.
	 *
	 * @return void
	 */
	public function testScenariosMatchTheGolden(): void {
		$day = ['field' => 'created', 'start' => '2026-01-01T00:00:00Z', 'end' => '2026-04-01T00:00:00Z', 'gap' => 'day'];
		$actual = [
			'count all'                    => $this->runScenario(['metric' => 'count']),
			'count, field ignored'         => $this->runScenario(['metric' => 'count', 'field' => 'nope']),
			'count, empty field'           => $this->runScenario(['metric' => 'count', 'field' => '']),
			'sum'                          => $this->runScenario(['metric' => 'sum', 'field' => 'amount']),
			'avg'                          => $this->runScenario(['metric' => 'avg', 'field' => 'score']),
			'min'                          => $this->runScenario(['metric' => 'min', 'field' => 'amount']),
			'max'                          => $this->runScenario(['metric' => 'max', 'field' => 'score']),
			'max of text is null'          => $this->runScenario(['metric' => 'max', 'field' => 'note']),
			'sum of empty set is null'     => $this->runScenario(['metric' => 'sum', 'field' => 'amount', 'filter' => ['status' => 'none']]),
			'count of empty set'           => $this->runScenario(['metric' => 'count', 'filter' => ['status' => 'none']]),
			'unknown metric'               => $this->runScenario(['metric' => 'median', 'field' => 'amount']),
			'metric case matters'          => $this->runScenario(['metric' => 'COUNT']),
			'metric case matters, sum'     => $this->runScenario(['metric' => 'SUM', 'field' => 'amount']),
			'value metric without field'   => $this->runScenario(['metric' => 'sum']),
			'value metric, empty field'    => $this->runScenario(['metric' => 'sum', 'field' => '']),
			'value metric, unknown field'  => $this->runScenario(['metric' => 'avg', 'field' => 'secret']),
			'no source id'                 => $this->runScenario(['metric' => 'count', 'config' => ['sourceId' => '']]),
			'source lookup fails'          => $this->runScenario(['metric' => 'count', 'sourceFails' => true]),
			'not a database source'        => $this->runScenario(['metric' => 'count', 'sourceType' => 'api']),
			'unreachable database'         => $this->runScenario(['metric' => 'count', 'connectFails' => true]),
			'unreachable, driver missing'  => $this->runScenario(['metric' => 'count', 'connectFails' => true, 'driver' => 'oci8']),
			'driver missing, connects'     => $this->runScenario(['metric' => 'count', 'driver' => 'oci8']),
			'lazy connect error'           => $this->runScenario(['metric' => 'count', 'path' => sys_get_temp_dir()]),
			'driver missing'               => $this->runScenario(['metric' => 'count', 'driver' => 'oci8', 'connectFails' => true]),
			'scalar filter'                => $this->runScenario(['metric' => 'count', 'filter' => ['status' => 'open']]),
			'operator filter'              => $this->runScenario(['metric' => 'sum', 'field' => 'amount', 'filter' => ['amount' => ['gte' => 7]]]),
			'in filter'                    => $this->runScenario(['metric' => 'count', 'filter' => ['status' => ['in' => ['open', 'closed']]]]),
			'unknown filter column'        => $this->runScenario(['metric' => 'count', 'filter' => ['secret' => 'x', 'status' => 'closed']]),
			'int filter key skipped'       => $this->runScenario(['metric' => 'count', 'filter' => [0 => 'open']]),
			'group by status'              => $this->runScenario(['metric' => 'count', 'groupBy' => ['field' => 'status']]),
			'group by with sum'            => $this->runScenario(['metric' => 'sum', 'field' => 'amount', 'groupBy' => ['field' => 'status']]),
			'group by with avg and filter' => $this->runScenario(['metric' => 'avg', 'field' => 'score', 'groupBy' => ['field' => 'status'], 'filter' => ['amount' => ['gt' => 5]]]),
			'group by unknown column'      => $this->runScenario(['metric' => 'count', 'groupBy' => ['field' => 'secret']]),
			'group by empty field'         => $this->runScenario(['metric' => 'count', 'groupBy' => ['field' => '']]),
			'group by no field'            => $this->runScenario(['metric' => 'count', 'groupBy' => []]),
			'group by int field'           => $this->runScenario(['metric' => 'count', 'groupBy' => ['field' => 0]]),
			'date bucket (sqlite: none)'   => $this->runScenario(['metric' => 'count', 'dateBucket' => $day]),
			'date bucket wins over group'  => $this->runScenario(['metric' => 'count', 'dateBucket' => $day, 'groupBy' => ['field' => 'status']]),
			'date bucket unknown field'    => $this->runScenario(['metric' => 'count', 'dateBucket' => ['field' => 'secret'] + $day]),
			'date bucket bad gap'          => $this->runScenario(['metric' => 'count', 'dateBucket' => ['gap' => 'decade'] + $day]),
			'mysql: date bucket by day'    => $this->runScenario(['metric' => 'count', 'dateBucket' => $day, 'platform' => 'mysql']),
			'mysql: date bucket, month sum' => $this->runScenario(['metric' => 'sum', 'field' => 'amount', 'dateBucket' => ['gap' => 'month'] + $day, 'platform' => 'mysql']),
			'mysql: date bucket, filtered' => $this->runScenario(['metric' => 'avg', 'field' => 'score', 'dateBucket' => ['gap' => 'month'] + $day, 'filter' => ['status' => 'closed', 'secret' => 'x'], 'platform' => 'mysql']),
			'mysql: date bucket bounds'    => $this->runScenario(['metric' => 'count', 'dateBucket' => ['start' => '2026-01-04T00:00:00Z', 'end' => '2026-02-11T09:00:00Z'] + $day, 'platform' => 'mysql']),
			'mysql: date bucket wins'      => $this->runScenario(['metric' => 'count', 'dateBucket' => ['gap' => 'year'] + $day, 'groupBy' => ['field' => 'status'], 'platform' => 'mysql']),
			'mysql: date bucket unknown field' => $this->runScenario(['metric' => 'count', 'dateBucket' => ['field' => 'secret'] + $day, 'platform' => 'mysql']),
			'mysql: week falls back'       => $this->runScenario(['metric' => 'count', 'dateBucket' => ['gap' => 'week'] + $day, 'platform' => 'mysql']),
			'mysql: date bucket, no bounds' => $this->runScenario(['metric' => 'count', 'dateBucket' => ['field' => 'created', 'gap' => 'day'], 'platform' => 'mysql']),
			'mysql: date bucket query error' => $this->runScenario(['metric' => 'count', 'dateBucket' => $day, 'platform' => 'mysql', 'config' => ['table' => 'missing_table']]),
			'mysql: grouped and scalar'    => $this->runScenario(['metric' => 'max', 'field' => 'amount', 'groupBy' => ['field' => 'status'], 'platform' => 'mysql']),
			'query-backed source'          => $this->runScenario(['metric' => 'sum', 'field' => 'amount', 'config' => ['table' => null, 'query' => 'SELECT * FROM facts WHERE id > 1'], 'filter' => ['status' => 'open']]),
			'query error, scalar'          => $this->runScenario(['metric' => 'count', 'config' => ['table' => 'missing_table']]),
			'query error, grouped'         => $this->runScenario(['metric' => 'count', 'groupBy' => ['field' => 'status'], 'config' => ['table' => 'missing_table']]),
		];

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(expected: $expected, actual: json_decode((string) json_encode($actual, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), true));
	}//end testScenariosMatchTheGolden()

	/**
	 * Run one aggregate() call and record its answer and the warnings it logged.
	 *
	 * @param array<string, mixed> $setup The scenario.
	 *
	 * @return array<string, mixed>
	 */
	private function runScenario(array $setup): array {
		$this->logs = [];
		$provider = $this->provider(setup: $setup);
		$schema   = new Schema();
		$schema->setId(11);
		$schema->setSlug('facts');
		$schema->setProperties(
			[
				'id'      => ['type' => 'integer'],
				'status'  => ['type' => 'string'],
				'amount'  => ['type' => 'integer'],
				'score'   => ['type' => 'number'],
				'note'    => ['type' => 'string'],
				'created' => ['type' => 'string', 'format' => 'date-time'],
				'tags'    => ['type' => 'array'],
			]
		);
		$register = new Register();
		$register->setId(1);

		try {
			$answer = $provider->aggregate(
				register: $register,
				schema: $schema,
				metric: $setup['metric'],
				field: ($setup['field'] ?? null),
				filter: ($setup['filter'] ?? []),
				groupBy: ($setup['groupBy'] ?? null),
				dateBucket: ($setup['dateBucket'] ?? null),
				config: array_merge(['sourceId' => '9', 'table' => 'facts', 'idColumn' => 'id'], ($setup['config'] ?? []))
			);
		} catch (\Throwable $e) {
			$status = method_exists($e, 'getStatusCode') === true ? $e->getStatusCode() : null;
			$answer = ['exception' => [get_class($e), $e->getMessage(), $status, $e->getPrevious() === null ? null : get_class($e->getPrevious())]];
		}

		return ['answer' => $answer, 'logs' => $this->logs];
	}//end runScenario()

	/**
	 * The provider over a SQLite source; the logger records warnings without driver detail.
	 *
	 * @param array<string, mixed> $setup The scenario.
	 *
	 * @return DbalObjectSourceProvider
	 */
	private function provider(array $setup): DbalObjectSourceProvider {
		$source = new Source();
		$source->setId(9);
		$source->setUuid('00000000-0000-0000-0000-000000000000');
		$source->setType(($setup['sourceType'] ?? 'database'));
		$source->setAuthConfig(['driver' => ($setup['driver'] ?? 'pdo_sqlite'), 'path' => ($setup['path'] ?? self::$dbPath)]);

		$sourceMapper = $this->createMock(SourceMapper::class);
		if (($setup['sourceFails'] ?? false) === true) {
			$sourceMapper->method('findForSystem')->willThrowException(new \RuntimeException('source table gone'));
		} else {
			$sourceMapper->method('findForSystem')->willReturn($source);
		}

		$factory = $this->createMock(DbalConnectionFactory::class);
		$factory->method('isDriverAvailable')->willReturnCallback(fn (string $driver): bool => $driver === 'pdo_sqlite');
		$factory->method('getConnection')->willReturnCallback(
			function () use ($setup): Connection {
				if (($setup['connectFails'] ?? false) === true) {
					throw new DbalConnectionException('Unable to open a database connection for the configured source.');
				}

				$params = ['driver' => 'pdo_sqlite', 'path' => ($setup['path'] ?? self::$dbPath)];
				if (($setup['platform'] ?? 'sqlite') === 'mysql') {
					$params['wrapperClass'] = MySqlDialectSqliteConnection::class;
				}

				return DriverManager::getConnection($params);
			}
		);

		$logs   = &$this->logs;
		$logger = new class($logs) extends AbstractLogger {
			/** @var array<int, string> */
			private array $logs;

			/**
			 * @param array<int, string> $logs The shared log list.
			 */
			public function __construct(array &$logs) {
				$this->logs = &$logs;
			}

			/**
			 * {@inheritDoc}
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$text = (string) $message;
				$cut  = strpos($text, 'failed: ');
				if ($cut !== false) {
					$text = substr($text, 0, $cut + 7) . ' <driver message>';
				}

				$this->logs[] = $level . ': ' . $text;
			}
		};

		return new DbalObjectSourceProvider(
			sourceMapper: $sourceMapper,
			connectionFactory: $factory,
			logger: $logger
		);
	}//end provider()
}//end class

/**
 * A SQLite connection that reports the MySQL platform and answers MySQL's DATE_FORMAT, so the
 * provider's MySQL date-bucket query runs against the SQLite file.
 */
final class MySqlDialectSqliteConnection extends Connection {

	/** @var bool */
	private bool $dateFormatRegistered = false;

	/**
	 * {@inheritDoc}
	 */
	public function getDatabasePlatform(): AbstractPlatform {
		return new MySQLPlatform();
	}

	/**
	 * {@inheritDoc}
	 */
	public function connect(): bool {
		$connected = parent::connect();
		if ($this->dateFormatRegistered === false) {
			$this->dateFormatRegistered = true;
			$format = static function (?string $value, string $pattern): ?string {
				if ($value === null) {
					return null;
				}

				$tokens = ['%Y' => 'Y', '%m' => 'm', '%d' => 'd', '%H' => 'H', '%i' => 'i'];
				$parts  = preg_split('/(%[YmdHi])/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
				$format = implode('', array_map(fn (string $part): string => ($tokens[$part] ?? addcslashes($part, 'A..Za..z')), $parts));
				return gmdate($format, (int) strtotime($value));
			};
			$pdo = $this->getNativeConnection();
			if (method_exists($pdo, 'createFunction') === true) {
				$pdo->createFunction('DATE_FORMAT', $format, 2);
			} else {
				$pdo->sqliteCreateFunction('DATE_FORMAT', $format, 2);
			}
		}

		return $connected;
	}
}
