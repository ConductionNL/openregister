<?php

/**
 * Golden test for MagicBulkHandler::executeUpsertChunk().
 *
 * Records every statement the chunk upsert prepares, the parameters it binds,
 * the log lines it writes and the classified rows it returns, per platform,
 * pre-update-state flag, managed-slug flag, chunk shape and failing statement.
 * Re-record with UPDATE_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\MagicMapper\MagicTableSql;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper\MagicBulkHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicUpsertChunkRun;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicBulkHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicBulkUpsertSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicUpsertChunkRun
 */
class MagicBulkHandlerUpsertChunkGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/magic-bulk-upsert-chunk-golden.json';

	private const RECORDED_LATER_STAMP = '2999-01-01 00:00:00';

	private const TABLE_COLUMNS = ['_uuid', '_slug', '_name', '_created', '_updated', '_version', 'flag', 'title'];

	private array $events = [];

	private ?string $laterStamp = null;

	private static function chunks(): array {
		return [
			'mixed' => [
				['_uuid' => 'u-1', '_name' => 'One', 'flag' => true, 'title' => 'a', 'ghost' => 'x'],
				['_uuid' => 'u-2', '_slug' => 's-2', 'flag' => false, '_created' => '2020-01-01', '_version' => '1.0.0'],
				['_uuid' => 'u-3', 'title' => null, 'flag' => 0],
				['_uuid' => 'u-1', '_name' => 'One again', 'title' => 'b'],
				['_uuid' => 'u-3', 'title' => 'three again'],
				['ghost' => 'only unknown'],
				['_uuid' => '', 'title' => 'generated'],
				['title' => 'no uuid'],
			],
			'single new' => [['_uuid' => 'u-9', 'title' => 't']],
			'only unknown columns' => [['ghost' => 1], ['other' => 2]],
			'empty' => [],
		];
	}//end chunks()

	private static function cases(): array {
		$cases = [];
		foreach (['pg', 'mysql'] as $platform) {
			foreach (self::chunks() as $chunkName => $chunk) {
				foreach ([[true, false], [false, true]] as [$preUpdate, $freeze]) {
					$name = "{$platform} / {$chunkName} / preUpdate " . var_export($preUpdate, true) . ' / freeze ' . var_export($freeze, true);
					$cases[$name] = [$platform, $chunk, $preUpdate, $freeze, null];
				}
			}

			$cases["{$platform} / exists check fails"] = [$platform, self::chunks()['mixed'], true, false, 'IN ('];
			$cases["{$platform} / upsert fails"] = [$platform, self::chunks()['mixed'], true, false, 'INSERT INTO'];
		}

		return $cases;
	}//end cases()

	public function testUpsertChunkMatchesGolden(): void {
		$actual = [];
		foreach (self::cases() as $name => [$platform, $chunk, $preUpdate, $freeze, $fail]) {
			$this->events = [];
			$handler = $this->makeHandler(platform: $platform, fail: $fail);
			(new ReflectionProperty(MagicBulkHandler::class, 'managedSlugTables'))->setValue($handler, ['openregister_table_1_2' => $freeze]);
			$method = new ReflectionMethod(MagicBulkHandler::class, 'executeUpsertChunk');
			try {
				$run = new MagicUpsertChunkRun('openregister_table_1_2', 7);
				$run->needsPreUpdateState = $preUpdate;
				$result = $method->invoke($handler, $run, $chunk);
			} catch (\Exception $e) {
				$result = ['thrown' => get_class($e) . ': ' . $e->getMessage()];
			}

			$actual[$name] = $this->normalise(value: ['events' => $this->events, 'result' => $result]);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testUpsertChunkMatchesGolden()

	/**
	 * A row stamp after any start of the run, fixed for the length of the test.
	 *
	 * @return string
	 */
	private function laterStamp(): string {
		$this->laterStamp ??= date('Y-m-d H:i:s', strtotime('+10 years'));
		return $this->laterStamp;
	}

	/**
	 * Replace generated UUIDs and the operation start time with stable markers.
	 */
	private function normalise(mixed $value): mixed {
		if (is_array($value) === true) {
			$out = [];
			foreach ($value as $key => $item) {
				$out[$key] = $this->normalise(value: $item);
				if ($key === 'operation_start_time' || ($key === '_updated' && str_starts_with((string) $item, '2000') === false)) {
					$out[$key] = '<start>';
				}

				if ($key === '_updated' && $item === $this->laterStamp()) {
					$out[$key] = self::RECORDED_LATER_STAMP;
				}
			}

			return $out;
		}

		if (is_string($value) === true) {
			return preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}/', '<generated-uuid>', $value);
		}

		return $value;
	}//end normalise()

	private function makeHandler(string $platform, ?string $fail): MagicBulkHandler {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(
			$platform === 'pg' ? $this->createMock(PostgreSQLPlatform::class) : $this->createMock(MySQLPlatform::class)
		);
		$db->method('prepare')->willReturnCallback(
			function (string $sql) use ($fail): IPreparedStatement {
				$this->events[] = ['prepare' => $sql];
				return $this->statement(sql: $sql, fail: $fail);
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'debug', 'info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					unset($context['file'], $context['line']);
					$this->events[] = ['log' => $level, 'message' => $message, 'context' => $context];
				}
			);
		}

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturn('oc_');

		return new MagicBulkHandler(
			sql: new MagicTableSql(db: $db, config: $config, logger: $logger),
			eventDispatcher: $this->createMock(IEventDispatcher::class),
			dateTimeNormalizer: $this->createMock(DateTimeNormalizer::class)
		);
	}//end makeHandler()

	private function statement(string $sql, ?string $fail): IPreparedStatement {
		$statement = $this->createMock(IPreparedStatement::class);
		$params = [];
		$statement->method('execute')->willReturnCallback(
			function (?array $bound = null) use ($sql, $fail, &$params): IResult {
				$params = $bound ?? [];
				$this->events[] = ['execute' => $params];
				if ($fail !== null && str_contains($sql, $fail) === true) {
					throw new \Exception('refused: ' . $fail);
				}

				return $this->createMock(IResult::class);
			}
		);
		$columns = array_map(static fn (string $c): array => ['Field' => $c, 'column_name' => $c], self::TABLE_COLUMNS);
		$columns[] = false;
		$statement->method('fetch')->willReturnOnConsecutiveCalls(...$columns);
		$statement->method('fetchAll')->willReturnCallback(
			function () use ($sql, &$params): array {
				return $this->rowsFor(sql: $sql, uuids: array_values($params));
			}
		);

		return $statement;
	}//end statement()

	/**
	 * Rows for a SELECT: the existence check finds u-1 and u-2; the read-back
	 * returns every uuid, u-1 written during the operation and u-2 after it.
	 */
	private function rowsFor(string $sql, array $uuids): array {
		$rows = [];
		$readBack = str_starts_with($sql, 'SELECT * ') && str_contains($sql, '_uuid') && empty($this->upsertSeen()) === false;
		foreach ($uuids as $uuid) {
			if ($readBack === true) {
				$updated = ($uuid === 'u-2' || $uuid === 'u-9') ? $this->laterStamp() : '2000-01-01 00:00:00';
				if ($uuid === 'u-1') {
					$updated = (new \DateTime())->format('Y-m-d H:i:s');
				}

				$rows[] = ['_uuid' => $uuid, '_updated' => $updated];
				continue;
			}

			if (in_array($uuid, ['u-1', 'u-2'], true) === true) {
				$rows[] = ['_uuid' => $uuid, 'title' => 'old ' . $uuid];
			}
		}

		return $rows;
	}//end rowsFor()

	private function upsertSeen(): array {
		return array_filter($this->events, static fn (array $e): bool => isset($e['prepare']) && str_starts_with($e['prepare'], 'INSERT INTO'));
	}//end upsertSeen()
}//end class
