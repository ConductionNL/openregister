<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\SettingsController;
use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use stdClass;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden test for SettingsController::getHybridSearchDiagnostics().
 *
 * Every case answers the catalog and count queries from a table keyed by
 * query kind, records each database, container and logger call and the
 * diagnostics, and compares the recording with
 * fixtures/settings-hybrid-diagnostics-golden.json. Set UPDATE_GOLDEN=1 to
 * rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Controller\SettingsController
 */
class SettingsControllerHybridDiagnosticsGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/settings-hybrid-diagnostics-golden.json';

	/** @var list<array<string, mixed>> */
	private array $calls = [];

	/** @var array<string, mixed>|null */
	private static ?array $recorded = null;

	/** @var array<string, mixed> */
	private static array $golden = [];

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$healthy = [
			'typmod' => ['one' => '1536'],
			'indexes' => ['rows' => [['indexname' => 'idx_or_vec_ann_hnsw'], ['indexname' => 'idx_or_chunks_text_search_gin']]],
			'vectors' => ['rows' => [['total' => '40', 'populated' => '31']]],
		];
		return [
			'not postgres' => [['postgres' => false, 'chunks' => 'mapper']],
			'not postgres, chunk mapper missing' => [['postgres' => false, 'chunks' => 'throw']],
			'not postgres, container answers something else' => [['postgres' => false, 'chunks' => 'other']],
			'postgres, everything present' => [['postgres' => true, 'chunks' => 'mapper', 'sql' => $healthy]],
			'postgres, sidecar without a dimension' => [['postgres' => true, 'chunks' => 'mapper', 'sql' => ['typmod' => ['one' => '0']] + $healthy]],
			'postgres, sidecar column missing' => [['postgres' => true, 'chunks' => 'mapper', 'sql' => ['typmod' => ['one' => false]] + $healthy]],
			'postgres, sidecar query fails' => [['postgres' => true, 'chunks' => 'mapper', 'sql' => ['typmod' => ['throw' => 'no sidecar']] + $healthy]],
			'postgres, only the hnsw index' => [['postgres' => true, 'chunks' => 'mapper', 'sql' => ['indexes' => ['rows' => [['indexname' => 'idx_or_vec_ann_hnsw'], ['indexname' => 'other']]]] + $healthy]],
			'postgres, only the gin index' => [['postgres' => true, 'chunks' => 'mapper', 'sql' => ['indexes' => ['rows' => [['indexname' => 'idx_or_chunks_text_search_gin']]]] + $healthy]],
			'postgres, index query fails' => [['postgres' => true, 'chunks' => 'mapper', 'sql' => ['indexes' => ['throw' => 'no catalog']] + $healthy]],
			'postgres, vector row is not an array' => [['postgres' => true, 'chunks' => 'mapper', 'sql' => ['vectors' => ['rows' => []]] + $healthy]],
			'postgres, vector fetch answers null' => [['postgres' => true, 'chunks' => 'mapper', 'sql' => ['vectors' => ['fetchNull' => true]] + $healthy]],
			'postgres, vector join fails, plain count works' => [['postgres' => true, 'chunks' => 'mapper', 'sql' => ['vectors' => ['throw' => 'no join'], 'fallback' => ['one' => '12']] + $healthy]],
			'postgres, vector join fails, plain count empty' => [['postgres' => true, 'chunks' => 'mapper', 'sql' => ['vectors' => ['throw' => 'no join'], 'fallback' => ['one' => false]] + $healthy]],
			'postgres, every query fails' => [['postgres' => true, 'chunks' => 'throw', 'sql' => [
				'typmod' => ['throw' => 'a'],
				'indexes' => ['throw' => 'b'],
				'vectors' => ['throw' => 'c'],
				'fallback' => ['throw' => 'd'],
			]]],
		];
	}

	#[DataProvider('cases')]
	public function testHybridSearchDiagnosticsMatchTheRecording(array $case): void {
		$this->calls = [];

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error', 'debug', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(function (...$args) use ($level) {
				unset($args[1]['line'], $args[1]['trace'], $args[1]['file']);
				$this->calls[] = ['logger.' . $level => $args];
			});
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id) use ($case) {
			$this->calls[] = ['container.get' => $id];
			if ($case['chunks'] === 'throw') {
				throw new Exception('no chunk mapper');
			}

			if ($case['chunks'] === 'other') {
				return new stdClass();
			}

			$chunks = $this->createMock(ChunkMapper::class);
			$chunks->method('countAll')->willReturn(70);
			$chunks->method('countVectorized')->willReturn(55);
			return $chunks;
		});

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		$controller = new SettingsController(
			'openregister',
			$this->createMock(IRequest::class),
			$this->createMock(IAppConfig::class),
			$this->connection($case['sql'] ?? []),
			$container,
			$this->createMock(IAppManager::class),
			$this->createMock(SettingsService::class),
			$logger,
			$l10n
		);

		$method = new ReflectionMethod(SettingsController::class, 'getHybridSearchDiagnostics');
		$diagnostics = $method->invoke($controller, $case['postgres']);
		$this->compare($this->dataName(), ['calls' => $this->calls, 'diagnostics' => $diagnostics]);
	}

	/**
	 * @param array<string, array<string, mixed>> $answers
	 */
	private function connection(array $answers): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturnCallback(function (string $sql) use ($answers) {
			$this->calls[] = ['db.executeQuery' => $sql];
			$kind = 'fallback';
			if (str_contains($sql, 'atttypmod') === true) {
				$kind = 'typmod';
			} elseif (str_contains($sql, 'pg_indexes') === true) {
				$kind = 'indexes';
			} elseif (str_contains($sql, 'LEFT JOIN') === true) {
				$kind = 'vectors';
			}

			$answer = $answers[$kind] ?? [];
			if (isset($answer['throw']) === true) {
				throw new Exception($answer['throw']);
			}

			return $this->queryResult($answer);
		});
		return $db;
	}

	/**
	 * @param array<string, mixed> $answer
	 */
	private function queryResult(array $answer): IResult {
		$rows = $answer['rows'] ?? [];
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturnCallback(function () use ($answer) {
			$this->calls[] = ['result.fetchOne' => []];
			return $answer['one'] ?? false;
		});
		$result->method('fetch')->willReturnCallback(function () use (&$rows, $answer) {
			$this->calls[] = ['result.fetch' => []];
			if (($answer['fetchNull'] ?? false) === true) {
				return null;
			}

			return array_shift($rows) ?? false;
		});
		$result->method('closeCursor')->willReturnCallback(function () {
			$this->calls[] = ['result.closeCursor' => []];
			return true;
		});
		return $result;
	}

	/**
	 * @param array<string, mixed> $actual
	 */
	private function compare(string $key, array $actual): void {
		$actual = json_decode((string)json_encode($actual), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			if (self::$recorded === null) {
				self::$recorded = [];
			}

			self::$recorded[$key] = $actual;
			ksort(self::$recorded);
			file_put_contents(
				self::FIXTURE,
				json_encode(self::$recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
			);
			self::$golden = self::$recorded;
		}

		if (self::$golden === []) {
			self::$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$this->assertArrayHasKey($key, self::$golden);
		$this->assertSame(self::$golden[$key], $actual);
	}
}
