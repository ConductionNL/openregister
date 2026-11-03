<?php

/**
 * DbalObjectSourceProvider::aggregate() answers every metric, filter and grouping exactly as recorded.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use Doctrine\DBAL\DriverManager;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapper;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Dbal\DbalConnectionFactory;
use OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

/**
 * Golden record of aggregate() against a real SQLite table: the metric allowlist, the field
 * allowlist, source resolution, connection failure and a missing driver, the date-bucket route,
 * categorical groupBy (including an unknown group column), the scalar value, filters (scalar,
 * operator, unknown and non-string columns) and a query error.
 * Set OR_GOLDEN_RECORD=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceProvider
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Source
 * @uses   \OCA\OpenRegister\Service\Dbal\DbalConnectionFactory
 * @uses   \OCA\OpenRegister\Service\Dbal\DbalConnectionException
 * @uses   \OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceException
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
			'value metric without field'   => $this->runScenario(['metric' => 'sum']),
			'value metric, empty field'    => $this->runScenario(['metric' => 'sum', 'field' => '']),
			'value metric, unknown field'  => $this->runScenario(['metric' => 'avg', 'field' => 'secret']),
			'no source id'                 => $this->runScenario(['metric' => 'count', 'config' => ['table' => 'facts']]),
			'source lookup fails'          => $this->runScenario(['metric' => 'count', 'sourceFails' => true]),
			'not a database source'        => $this->runScenario(['metric' => 'count', 'sourceType' => 'api']),
			'unreachable database'         => $this->runScenario(['metric' => 'count', 'path' => sys_get_temp_dir()]),
			'driver missing'               => $this->runScenario(['metric' => 'count', 'driver' => 'oci8']),
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
			'query error, scalar'          => $this->runScenario(['metric' => 'count', 'config' => ['table' => 'missing_table']]),
			'query error, grouped'         => $this->runScenario(['metric' => 'count', 'groupBy' => ['field' => 'status'], 'config' => ['table' => 'missing_table']]),
		];

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(expected: $expected, actual: json_decode((string) json_encode($actual, JSON_UNESCAPED_SLASHES), true));
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
			$answer = ['exception' => [get_class($e), $e->getMessage(), $e->getCode(), $e->getPrevious() === null ? null : get_class($e->getPrevious())]];
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

		$store = $this->createMock(CredentialStore::class);
		$store->method('get')->willReturn(null);

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
			connectionFactory: new DbalConnectionFactory(credentialStore: $store, logger: new NullLogger()),
			logger: $logger
		);
	}//end provider()
}//end class
