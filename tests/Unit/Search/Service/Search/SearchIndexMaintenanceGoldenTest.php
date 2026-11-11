<?php

/**
 * Golden test for the search index maintenance service.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Search\Service\Search;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Search\Service\Search\SearchIndexMaintenance;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Throwable;
use OCA\OpenRegister\Search\Service\Search\SearchIndexPass;

/**
 * Pins every public answer of SearchIndexMaintenance, with the statements it
 * runs, the config it stores and the log lines it writes (file and line left
 * out, dates replaced). Compared with fixtures/search-index-maintenance-golden.json;
 * set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Search\Service\Search\SearchIndexMaintenance
 */
class SearchIndexMaintenanceGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/search-index-maintenance-golden.json';

	/**
	 * Index definitions by prefixed table name, as pg_indexes would report them.
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $catalogue = [];

	/**
	 * Row counts by prefixed table name; a table absent here cannot be counted.
	 *
	 * @var array<string, int>
	 */
	private array $rowCounts = [];

	/**
	 * Substrings of statements that throw when run.
	 *
	 * @var string[]
	 */
	private array $failOn = [];

	/**
	 * What the service did, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $trace = [];

	/**
	 * The stored last-run report.
	 *
	 * @var string
	 */
	private string $stored = '';

	private string $dir = '';

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/or-sim-golden-' . bin2hex(random_bytes(4));
		mkdir($this->dir);
	}//end setUp()

	protected function tearDown(): void {
		foreach (glob($this->dir . '/*') ?: [] as $file) {
			unlink($file);
		}

		rmdir($this->dir);
	}//end tearDown()

	public function testEveryAnswerMatchesTheGolden(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$this->trace = [];
			$this->stored = '';
			$actual[$name] = ['result' => $this->answer($case), 'trace' => $this->trace, 'stored' => $this->normalise(json_decode($this->stored, true))];
		}

		$json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, $json);
		}

		$this->assertFileExists(self::FIXTURE);
		$this->assertSame(file_get_contents(self::FIXTURE), $json);
	}//end testEveryAnswerMatchesTheGolden()

	/**
	 * Run one case and normalise its answer or the exception it threw.
	 *
	 * @param callable $case The case.
	 *
	 * @return mixed The normalised answer.
	 */
	private function answer(callable $case): mixed {
		try {
			return $this->normalise($case());
		} catch (Throwable $exception) {
			return ['threw' => $exception::class, 'message' => str_replace($this->dir, '<dir>', $exception->getMessage())];
		}
	}//end answer()

	/**
	 * Replace dates and the temporary directory so the golden is stable.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The value, normalised.
	 */
	private function normalise(mixed $value): mixed {
		if (is_array($value) === false) {
			return is_string($value) === true ? str_replace($this->dir, '<dir>', $value) : $value;
		}

		foreach ($value as $key => $item) {
			$value[$key] = $this->normalise($item);
			if (in_array($key, ['startedAt', 'finishedAt', 'takenAt'], true) === true) {
				$value[$key] = '<date>';
			}
		}

		return $value;
	}//end normalise()

	/**
	 * Case name => call.
	 *
	 * @return array<string, callable(): mixed>
	 */
	private function cases(): array {
		$pg = fn (): SearchIndexMaintenance => $this->service(true);
		$my = fn (): SearchIndexMaintenance => $this->service(false);
		$progress = function (string $table, string $index, int $position, int $total): void {
			$this->trace[] = ['progress' => [$table, $index, $position, $total]];
		};
		$snapshotOf = function (array $tables): string {
			$path = $this->dir . '/snap-' . count(glob($this->dir . '/*') ?: []) . '.json';
			file_put_contents($path, json_encode(['version' => 1, 'tables' => $tables]));
			return $path;
		};

		return [
			'supports pg' => fn () => $pg()->supportsConcurrentRebuild(),
			'supports mariadb' => fn () => $my()->supportsConcurrentRebuild(),
			'tables all' => fn () => $pg()->tablesInScope(),
			'tables register 1' => fn () => $pg()->tablesInScope(1),
			'tables register 5' => fn () => $pg()->tablesInScope(5),
			'tables register 99' => fn () => $pg()->tablesInScope(99),
			'row counts union fails' => fn () => $pg()->rowCounts(),
			'row counts union works' => function () use ($pg) {
				$this->rowCounts['oc_t_1_3'] = 4;
				$this->rowCounts['oc_t_5_6'] = 0;
				return $pg()->rowCounts();
			},
			'indexes pg' => fn () => $pg()->indexesFor('t_1_2'),
			'indexes pg empty table' => fn () => $pg()->indexesFor('t_none'),
			'indexes mariadb' => fn () => $my()->indexesFor('t_1_2'),
			'index names pg' => fn () => $pg()->indexNamesFor(['t_1_2', 't_1_3', 't_none', 7]),
			'index names pg none' => fn () => $pg()->indexNamesFor([]),
			'index names mariadb' => fn () => $my()->indexNamesFor(['t_1_2']),
			'rebuild refused dry' => fn () => $my()->rebuild(registerId: null, pass: new SearchIndexPass(apply: false, progress: $progress)),
			'rebuild refused apply' => fn () => $my()->rebuild(registerId: null, pass: new SearchIndexPass(apply: true, progress: null)),
			'rebuild dry run' => fn () => $pg()->rebuild(registerId: null, pass: new SearchIndexPass(apply: false, progress: $progress)),
			'rebuild dry run no progress' => fn () => $pg()->rebuild(registerId: 1),
			'rebuild apply' => fn () => $pg()->rebuild(registerId: 1, pass: new SearchIndexPass(apply: true, progress: $progress)),
			'rebuild apply with failures' => function () use ($pg, $progress) {
				$this->failOn = ['"idx_b"', 'idx_b_ccnew', '"idx_c"'];
				return $pg()->rebuild(registerId: null, pass: new SearchIndexPass(apply: true, progress: $progress));
			},
			'rebuild apply bad index name' => function () use ($pg) {
				$this->catalogue['oc_t_1_3']['bad name'] = 'CREATE INDEX "bad name"';
				return $pg()->rebuild(registerId: 1, pass: new SearchIndexPass(apply: true, progress: null));
			},
			'snapshot' => function () use ($pg) {
				$path = $this->dir . '/out.json';
				$report = $pg()->snapshot($path, null);
				return ['report' => $report, 'file' => $this->normalise(json_decode((string) file_get_contents($path), true))];
			},
			'snapshot register 5' => function () use ($pg) {
				$path = $this->dir . '/out5.json';
				$report = $pg()->snapshot($path, 5);
				return ['report' => $report, 'file' => $this->normalise(json_decode((string) file_get_contents($path), true))];
			},
			'snapshot unwritable' => function () use ($pg) {
				set_error_handler(static fn (): bool => true);
				try {
					return $pg()->snapshot($this->dir . '/missing/out.json');
				} finally {
					restore_error_handler();
				}
			},
			'restore dry run' => fn () => $pg()->restore($snapshotOf($this->snapshotTables()), false),
			'restore apply' => fn () => $pg()->restore($snapshotOf($this->snapshotTables()), true),
			'restore apply with failure' => function () use ($pg, $snapshotOf) {
				$this->failOn = ['idx_new_2'];
				return $pg()->restore($snapshotOf($this->snapshotTables()), true);
			},
			'restore empty tables' => fn () => $pg()->restore($snapshotOf([]), true),
			'restore unreadable' => function () use ($pg) {
				set_error_handler(static fn (): bool => true);
				try {
					return $pg()->restore($this->dir . '/nope.json');
				} finally {
					restore_error_handler();
				}
			},
			'restore not json' => function () use ($pg) {
				file_put_contents($this->dir . '/bad.json', 'nope');
				return $pg()->restore($this->dir . '/bad.json');
			},
			'restore no tables' => function () use ($pg) {
				file_put_contents($this->dir . '/bad2.json', '{"version":1,"tables":"x"}');
				return $pg()->restore($this->dir . '/bad2.json');
			},
			'restore scalar indexes' => fn () => $pg()->restore($snapshotOf(['t_1_2' => 'idx_x']), true),
			'last run empty' => fn () => $pg()->lastRun(),
			'last run garbage' => function () use ($pg) {
				$service = $pg();
				$this->stored = 'not json';
				return $service->lastRun();
			},
			'last run stored' => function () use ($pg) {
				$service = $pg();
				$this->stored = '{"state":"completed","rebuilt":3}';
				return $service->lastRun();
			},
		];
	}//end cases()

	/**
	 * The tables a snapshot holds for the restore cases.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function snapshotTables(): array {
		return [
			't_1_2' => ['idx_a' => 'CREATE INDEX idx_a', 'idx_new_1' => 'CREATE INDEX idx_new_1 ON oc_t_1_2 (x)'],
			't_1_3' => ['idx_new_2' => 'CREATE INDEX idx_new_2 ON oc_t_1_3 (y)', 'idx_new_3' => 'CREATE INDEX idx_new_3'],
			9 => [],
		];
	}//end snapshotTables()

	/**
	 * Build the service over a fresh catalogue.
	 *
	 * Registers: 1 (schemas 2 slug "alpha", 3 no slug, 4 unresolvable) and
	 * 5 (schema 6 slug "alpha"), plus a value that is not a register.
	 *
	 * @param bool $postgres Whether the platform is PostgreSQL.
	 *
	 * @return SearchIndexMaintenance The service.
	 */
	private function service(bool $postgres): SearchIndexMaintenance {
		if ($this->catalogue === []) {
			$this->catalogue = [
				'oc_t_1_2' => ['idx_a' => 'CREATE INDEX idx_a ON oc_t_1_2 (a)', 'idx_b' => 'CREATE INDEX idx_b ON oc_t_1_2 (b)'],
				'oc_t_1_3' => ['idx_c' => 'CREATE INDEX idx_c ON oc_t_1_3 (c)'],
				'oc_t_5_6' => ['idx_d' => 'CREATE INDEX idx_d ON oc_t_5_6 (d)'],
			];
			$this->rowCounts = ['oc_t_1_2' => 7];
		}

		$platformClass = $postgres === true ? PostgreSQLPlatform::class : MariaDBPlatform::class;
		$platform = $this->createMock($platformClass);
		$platform->method('quoteIdentifier')->willReturnCallback(static fn (string $name): string => '"' . $name . '"');

		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($platform);
		$db->method('prepare')->willReturnCallback(fn (string $sql): IPreparedStatement => $this->statement($sql));
		$db->method('executeQuery')->willReturnCallback(fn (string $sql): IResult => $this->countResult($sql));
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql): int {
				$this->trace[] = ['statement' => $sql];
				foreach ($this->failOn as $needle) {
					if (str_contains($sql, $needle) === true) {
						throw new RuntimeException('cannot run ' . $needle);
					}
				}

				return 1;
			}
		);

		return new SearchIndexMaintenance(
			stores: new \OCA\OpenRegister\Search\Service\Search\SearchIndexStores($db, new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->registerMapper(), schemas: $this->createMock(\OCA\OpenRegister\Db\SchemaMapperInterface::class), tables: $this->magicMapper())),
			appConfig: $this->appConfig(),
			config: $this->config(),
			logger: $this->logger()
		);
	}//end service()

	private function registerMapper(): RegisterMapperInterface {
		$registerOne = new Register();
		$registerOne->setId(1);
		$registerFive = new Register();
		$registerFive->setId(5);
		$schemas = [];
		foreach ([2 => 'alpha', 3 => null, 4 => 'gone', 6 => 'alpha'] as $id => $slug) {
			$schemas[$id] = new Schema();
			$schemas[$id]->setId($id);
			$schemas[$id]->setSlug($slug);
		}

		$mapper = $this->createMock(RegisterMapperInterface::class);
		$mapper->method('findAll')->willReturnCallback(
			function (...$args) use ($registerOne, $registerFive): array {
				$this->trace[] = ['findAll' => $args];
				return [$registerOne, 'not a register', $registerFive];
			}
		);
		$mapper->method('getSchemasByRegisterId')->willReturnCallback(
			function (...$args) use ($schemas): array {
				$this->trace[] = ['getSchemasByRegisterId' => $args];
				if ($args[0] === 1) {
					return [$schemas[2], $schemas[3], 'not a schema', $schemas[4]];
				}

				return [$schemas[6]];
			}
		);

		return $mapper;
	}//end registerMapper()

	private function magicMapper(): MagicMapperInterface {
		$mapper = $this->createMock(MagicMapperInterface::class);
		$mapper->method('getTableNameForRegisterSchema')->willReturnCallback(
			static function (Register $register, Schema $schema): string {
				if ($schema->getId() === 4) {
					throw new RuntimeException('no table for 4');
				}

				return 't_' . $register->getId() . '_' . $schema->getId();
			}
		);

		return $mapper;
	}//end magicMapper()

	private function appConfig(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (...$args): string => $this->stored);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->trace[] = ['setValueString' => [$app, $key]];
				$this->stored = $value;
				return true;
			}
		);

		return $appConfig;
	}//end appConfig()

	private function config(): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(
			function (string $key, mixed $default): string {
				$this->trace[] = ['getSystemValue' => [$key, $default]];
				return 'oc_';
			}
		);

		return $config;
	}//end config()

	private function logger(): AbstractLogger {
		$trace = function (array $entry): void {
			$this->trace[] = $entry;
		};

		return new class ($trace) extends AbstractLogger {
			/**
			 * @var callable
			 */
			private $trace;

			public function __construct(callable $trace) {
				$this->trace = $trace;
			}

			public function log($level, string|Stringable $message, array $context = []): void {
				unset($context['file'], $context['line']);
				($this->trace)(['log' => [$level, (string) $message, $context]]);
			}
		};
	}//end logger()

	/**
	 * A prepared statement over the fake catalogue.
	 *
	 * @param string $sql The SQL.
	 *
	 * @return IPreparedStatement The statement.
	 */
	private function statement(string $sql): IPreparedStatement {
		$rows = [];
		$statement = $this->createMock(IPreparedStatement::class);
		$statement->method('execute')->willReturnCallback(
			function (array $params) use ($sql, &$rows): IResult {
				$this->trace[] = ['prepare' => $sql, 'params' => $params];
				foreach ($params as $table) {
					foreach ($this->catalogue[$table] ?? [] as $name => $definition) {
						$rows[] = ['tablename' => $table, 'indexname' => $name, 'indexdef' => $definition];
					}
				}

				return $this->createMock(IResult::class);
			}
		);
		$statement->method('fetch')->willReturnCallback(
			static function () use (&$rows): array|false {
				return array_shift($rows) ?? false;
			}
		);

		return $statement;
	}//end statement()

	/**
	 * Answer a row count query, throwing when any table it names cannot be counted.
	 *
	 * @param string $sql The SQL.
	 *
	 * @return IResult The result.
	 */
	private function countResult(string $sql): IResult {
		$this->trace[] = ['query' => $sql];
		preg_match_all('/SELECT (\d+) AS t, COUNT\(\*\) AS n FROM "([^"]+)"/', $sql, $matches, PREG_SET_ORDER);
		$rows = [];
		foreach ($matches as [, $position, $table]) {
			if (isset($this->rowCounts[$table]) === false) {
				throw new RuntimeException('relation ' . $table . ' does not exist');
			}

			$rows[] = ['t' => $position, 'n' => (string) $this->rowCounts[$table]];
		}

		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($rows);
		return $result;
	}//end countResult()
}//end class
