<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Exception;
use OCA\OpenRegister\Controller\SettingsController;
use OCA\OpenRegister\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden test for SettingsController::getDatabaseInfo().
 *
 * Every case records each collaborator call (request, app config, the
 * database's prepared statements and queries, the logger) and the response,
 * and compares the recording with fixtures/settings-database-info-golden.json.
 * The timestamp is replaced by a marker. Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Controller\SettingsController
 */
class SettingsControllerDatabaseInfoGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/settings-database-info-golden.json';

	private const VERSION_SQL = 'SELECT VERSION()';

	private const EXTENSIONS_SQL = 'SELECT extname, extversion FROM pg_extension ORDER BY extname';

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
		$database = ['database' => ['type' => 'PostgreSQL'], 'success' => true];
		$vector = [['extname' => 'plpgsql', 'extversion' => '1.0'], ['extname' => 'vector', 'extversion' => '0.7.0']];
		return [
			'cache hit' => [['refresh' => false, 'cache' => json_encode($database)]],
			'cache hit with string refresh false' => [['refresh' => 'false', 'cache' => json_encode($database)]],
			'cache without a database key' => [['refresh' => false, 'cache' => '{"success":true}', 'platform' => 'sqlite']],
			'cache not json' => [['refresh' => false, 'cache' => 'not json', 'platform' => 'sqlite']],
			'cache empty' => [['refresh' => false, 'cache' => '', 'platform' => 'sqlite']],
			'refresh skips the cache' => [['refresh' => 'true', 'cache' => json_encode($database), 'platform' => 'sqlite']],
			'mariadb' => [['refresh' => true, 'platform' => 'mariadb', 'sql' => [self::VERSION_SQL => ['one' => '10.11.6-MariaDB-1:10.11.6+maria~ubu2204']]]],
			'mysql' => [['refresh' => true, 'platform' => 'mysql', 'sql' => [self::VERSION_SQL => ['one' => '8.0.33']]]],
			'mysql without a semantic version' => [['refresh' => true, 'platform' => 'mysql', 'sql' => [self::VERSION_SQL => ['one' => 'weird']]]],
			'mysql version query fails' => [['refresh' => true, 'platform' => 'mysql', 'sql' => [self::VERSION_SQL => ['throw' => 'denied']]]],
			'postgres with pgvector' => [['refresh' => true, 'platform' => 'postgresql', 'sql' => [
				self::VERSION_SQL => ['one' => 'PostgreSQL 16.2 on x86_64'],
				self::EXTENSIONS_SQL => ['rows' => $vector],
			]]],
			'postgres without pgvector' => [['refresh' => true, 'platform' => 'postgresql', 'sql' => [
				self::VERSION_SQL => ['one' => 'something else'],
				self::EXTENSIONS_SQL => ['rows' => [['extname' => 'plpgsql', 'extversion' => '1.0']]],
			]]],
			'postgres queries fail' => [['refresh' => true, 'platform' => 'postgresql', 'sql' => [
				self::VERSION_SQL => ['throw' => 'no version'],
				self::EXTENSIONS_SQL => ['throw' => 'no catalog'],
			]]],
			'sqlite' => [['refresh' => true, 'platform' => 'sqlite']],
			'unknown platform' => [['refresh' => true, 'platform' => 'oracle']],
			'platform lookup throws' => [['refresh' => true, 'platformThrows' => 'connection lost']],
		];
	}

	#[DataProvider('cases')]
	public function testGetDatabaseInfoMatchesTheRecording(array $case): void {
		$this->calls = [];
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(function (...$args) use ($case) {
			$this->calls[] = ['request.getParam' => $args];
			return $case['refresh'];
		});

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(function (...$args) use ($case) {
			$this->calls[] = ['config.getValueString' => $args];
			return $case['cache'] ?? '';
		});
		$config->method('setValueString')->willReturnCallback(function (...$args) {
			$args[2] = $this->withoutTimestamp(json_decode($args[2], true));
			$this->calls[] = ['config.setValueString' => $args];
			return true;
		});

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error', 'debug', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(function (...$args) use ($level) {
				unset($args[1]['line'], $args[1]['trace'], $args[1]['file']);
				$this->calls[] = ['logger.' . $level => $args];
			});
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id) {
			$this->calls[] = ['container.get' => $id];
			throw new Exception('not in this test');
		});

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		$controller = new SettingsController(
			'openregister',
			$request,
			$config,
			$this->connection($case),
			$container,
			$this->createMock(IAppManager::class),
			$this->createMock(SettingsService::class),
			$logger,
			$l10n
		);

		$response = $controller->getDatabaseInfo();
		$actual = [
			'calls' => $this->calls,
			'status' => $response->getStatus(),
			'data' => $this->withoutTimestamp($response->getData()),
		];
		$this->compare($this->name() . ' ' . $this->dataName(), $actual);
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function connection(array $case): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturnCallback(function () use ($case) {
			$this->calls[] = ['db.getDatabasePlatform' => []];
			if (isset($case['platformThrows']) === true) {
				throw new Exception($case['platformThrows']);
			}

			$platform = $this->createMock(AbstractPlatform::class);
			$platform->method('getName')->willReturn($case['platform']);
			return $platform;
		});
		$db->method('prepare')->willReturnCallback(function (...$args) use ($case) {
			$this->calls[] = ['db.prepare' => $args];
			$answer = $case['sql'][$args[0]] ?? [];
			$statement = $this->createMock(IPreparedStatement::class);
			$statement->method('execute')->willReturnCallback(function () use ($answer) {
				$this->calls[] = ['statement.execute' => []];
				if (isset($answer['throw']) === true) {
					throw new Exception($answer['throw']);
				}

				return $this->queryResult($answer);
			});
			return $statement;
		});
		$db->method('executeQuery')->willReturnCallback(function (...$args) {
			$this->calls[] = ['db.executeQuery' => $args];
			return $this->queryResult([]);
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
		$result->method('fetch')->willReturnCallback(function () use (&$rows) {
			$this->calls[] = ['result.fetch' => []];
			return array_shift($rows) ?? false;
		});
		$result->method('closeCursor')->willReturnCallback(function () {
			$this->calls[] = ['result.closeCursor' => []];
			return true;
		});
		return $result;
	}

	/**
	 * @param mixed $data
	 *
	 * @return mixed
	 */
	private function withoutTimestamp($data) {
		if (is_array($data) === true && isset($data['database']['lastUpdated']) === true) {
			$data['database']['lastUpdated'] = preg_match('/^\d{4}-\d{2}-\d{2}T/', $data['database']['lastUpdated']) === 1 ? '(iso 8601)' : '(other)';
		}

		return $data;
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
