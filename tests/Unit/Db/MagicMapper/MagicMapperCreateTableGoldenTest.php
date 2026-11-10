<?php

/**
 * Golden test for MagicMapper::createTable().
 *
 * Records the CREATE TABLE statement and the log lines per platform, table
 * prefix and column set, so a decomposition keeps the exact DDL. Re-record
 * with UPDATE_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use OCA\OpenRegister\Db\MagicMapper;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableCreator
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableDdl
 */
class MagicMapperCreateTableGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/create-table-golden.json';

	private array $events = [];

	private static function columnSets(): array {
		$id = ['name' => '_id', 'type' => 'bigint', 'nullable' => false, 'autoincrement' => true, 'primary' => true];
		$uuid = ['name' => '_uuid', 'type' => 'string', 'length' => 36, 'nullable' => false, 'unique' => true];
		return [
			'none' => [],
			'metadata' => [
				$id,
				$uuid,
				['name' => '_name', 'type' => 'string', 'length' => 255],
				['name' => '_created', 'type' => 'datetime', 'nullable' => true],
				['name' => '_object', 'type' => 'json'],
			],
			'defaults' => [
				['name' => 'active', 'type' => 'boolean', 'default' => true],
				['name' => 'archived', 'type' => 'boolean', 'default' => false, 'nullable' => false],
				['name' => 'owner', 'type' => 'string', 'default' => "O'Brien"],
				['name' => 'note', 'type' => 'text', 'default' => null],
				['name' => 'count', 'type' => 'integer', 'default' => 0],
				['name' => 'ratio', 'type' => 'float', 'default' => 1.5],
			],
			'primary without autoincrement' => [
				['name' => 'code', 'type' => 'string', 'length' => 20, 'primary' => true, 'unique' => true],
				['name' => 'odd"name`', 'type' => 'string', 'unique' => true],
			],
			'autoincrement not primary' => [
				['name' => 'seq', 'type' => 'bigint', 'autoincrement' => true],
				['name' => 'other', 'type' => 'unknown-type'],
			],
		];
	}//end columnSets()

	public function testCreateTableMatchesGolden(): void {
		$actual = [];
		foreach (['pg', 'maria', 'sqlite'] as $platform) {
			foreach (['oc_', ''] as $prefix) {
				foreach (self::columnSets() as $setName => $columns) {
					foreach ([false, true] as $fail) {
						$name = "{$platform} / '{$prefix}' / {$setName}" . ($fail === true ? ' / fails' : '');
						$actual[$name] = $this->create(setup: [$platform, $prefix, $fail], columns: $columns);
					}
				}
			}
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
	}//end testCreateTableMatchesGolden()

	private function create(array $setup, array $columns): array {
		[$platformName, $prefix, $fail] = $setup;
		$this->events = [];

		$platform = match ($platformName) {
			'pg' => new PostgreSQLPlatform(),
			'sqlite' => new SqlitePlatform(),
			default => new MariaDBPlatform(),
		};

		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($platform);
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql) use ($fail): int {
				$this->events[] = ['sql' => $sql];
				if ($fail === true) {
					throw new \Exception('statement refused');
				}

				return 0;
			}
		);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(
			fn (string $key, mixed $default = '') => ($key === 'dbtableprefix' ? $prefix : $default)
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

		$reflection = new ReflectionClass(MagicMapper::class);
		$mapper = $reflection->newInstanceWithoutConstructor();
		foreach (['db' => $db, 'logger' => $logger, 'config' => $config] as $prop => $value) {
			$property = $reflection->getProperty($prop);
			$property->setAccessible(true);
			$property->setValue($mapper, $value);
		}

		$outcome = [];
		try {
			$reflection->getMethod('createTable')->invoke($mapper, 'openregister_table_1_2', $columns);
		} catch (\Throwable $e) {
			$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage();
		}

		$outcome['events'] = $this->events;
		return $outcome;
	}//end create()
}//end class
