<?php

/**
 * Golden test for MagicBulkHandler::bulkUpsert().
 *
 * Records every statement the public bulk upsert prepares, the parameters it
 * binds, the transaction calls, the log lines and the classified rows, per
 * platform, pre-update-state flag, managed-slug schema, chunking and failing
 * statement. Re-record with UPDATE_GOLDEN=1.
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

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper\BulkUpsertCommand;
use OCA\OpenRegister\Db\MagicMapper\MagicBulkHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicTableSql;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicBulkHandler
 */
class MagicBulkHandlerBulkUpsertGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/magic-bulk-upsert-golden.json';

	private const TABLE_COLUMNS = ['_uuid', '_register', '_schema', '_slug', '_name', '_owner', '_created', '_updated', '_version', 'title'];

	private array $events = [];

	private static function objects(): array {
		return [
			'two objects' => [
				['@self' => ['uuid' => 'u-1', 'slug' => 'one', 'owner' => 'alice'], 'title' => 'One', 'ghost' => 'x'],
				['@self' => ['uuid' => 'u-2'], 'title' => 'Two', 'slug' => 'two-top'],
			],
			'duplicate uuid' => [
				['@self' => ['uuid' => 'u-1'], 'title' => 'first'],
				['@self' => ['uuid' => 'u-1'], 'title' => 'second'],
			],
			'empty' => [],
		];
	}//end objects()

	private static function cases(): array {
		$cases = [];
		foreach (['pg', 'mysql'] as $platform) {
			foreach (self::objects() as $objectsName => $objects) {
				foreach ([[true, false], [false, true]] as [$preUpdate, $managed]) {
					$name = "{$platform} / {$objectsName} / preUpdate " . var_export($preUpdate, true) . ' / managed ' . var_export($managed, true);
					$cases[$name] = [$platform, $objects, $preUpdate, $managed, null];
				}
			}

			$cases["{$platform} / upsert fails"] = [$platform, self::objects()['two objects'], true, false, 'INSERT INTO'];
		}

		return $cases;
	}//end cases()

	public function testBulkUpsertMatchesGolden(): void {
		$actual = [];
		foreach (array_values(self::cases()) as $index => [$platform, $objects, $preUpdate, $managed, $fail]) {
			$name = array_keys(self::cases())[$index];
			$this->events = [];
			$handler = $this->makeHandler(platform: $platform, fail: $fail);
			try {
				$result = $handler->upsert(new BulkUpsertCommand($objects, new RegisterSchema($this->register(), $this->schema(managed: $managed)), 'openregister_table_bulk_' . $index, $preUpdate));
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
	}//end testBulkUpsertMatchesGolden()

	public function testTheDefaultReadsThePreUpdateRows(): void {
		$handler = $this->makeHandler(platform: 'pg', fail: null);
		$handler->upsert(new BulkUpsertCommand(self::objects()['two objects'], new RegisterSchema($this->register(), $this->schema(managed: false)), 'openregister_table_bulk_default'));

		$selects = array_values(array_filter($this->events, static fn (array $e): bool => str_starts_with($e['prepare'] ?? '', 'SELECT * FROM')));
		$this->assertCount(2, $selects, 'the existence check and the read-back both select every column');
	}//end testTheDefaultReadsThePreUpdateRows()

	private function register(): Register {
		$register = new Register();
		$register->setId(3);
		return $register;
	}

	private function schema(bool $managed): Schema {
		$schema = new Schema();
		$schema->setId(4);
		$schema->setProperties(['title' => ['type' => 'string']]);
		$schema->setConfiguration($managed === true ? ['objectSlugField' => 'title'] : []);
		return $schema;
	}

	/**
	 * Replace clock-dependent stamps and generated UUIDs with stable markers.
	 */
	private function normalise(mixed $value): mixed {
		if (is_array($value) === true) {
			return array_map(fn (mixed $item): mixed => $this->normalise(value: $item), $value);
		}

		if (is_string($value) === true) {
			$value = preg_replace('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', '<stamp>', $value);
			return preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}/', '<generated-uuid>', $value);
		}

		return $value;
	}//end normalise()

	private function makeHandler(string $platform, ?string $fail): MagicBulkHandler {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(
			$platform === 'pg' ? $this->createMock(PostgreSQLPlatform::class) : $this->createMock(MySQLPlatform::class)
		);
		$db->method('getDatabaseProvider')->willReturn($platform === 'pg' ? IDBConnection::PLATFORM_POSTGRES : IDBConnection::PLATFORM_MYSQL);
		$db->method('executeQuery')->willReturnCallback(fn (): IResult => $this->createMock(IResult::class));
		foreach (['beginTransaction', 'commit', 'rollBack'] as $call) {
			$db->method($call)->willReturnCallback(
				function () use ($call): void {
					$this->events[] = ['transaction' => $call];
				}
			);
		}

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
		$columns = [false];
		if (str_contains($sql, 'numeric_precision') === false) {
			$columns = array_map(static fn (string $c): array => ['Field' => $c, 'column_name' => $c], self::TABLE_COLUMNS);
			$columns[] = false;
		}

		$statement->method('fetch')->willReturnOnConsecutiveCalls(...$columns);
		$statement->method('fetchAll')->willReturnCallback(
			function () use (&$params): array {
				$rows = [];
				foreach (array_values($params) as $uuid) {
					$updated = ($uuid === 'u-1') ? '2999-01-01 00:00:00' : '2000-01-01 00:00:00';
					$rows[] = ['_uuid' => $uuid, '_updated' => $updated, 'title' => 'stored ' . $uuid];
				}

				return $rows;
			}
		);

		return $statement;
	}//end statement()
}//end class
