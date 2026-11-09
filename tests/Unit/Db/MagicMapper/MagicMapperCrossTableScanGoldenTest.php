<?php

/**
 * Golden test for MagicMapper::findMultipleAcrossAllMagicTables() and
 * findByRelationAcrossAllMagicTables().
 *
 * Records the statements each cross-table scan prepares and binds, the query
 * it builds per matching table, the register and schema lookups, the log lines
 * and the objects it returns, per platform, deleted flag, register scope and
 * failing step. Re-record with UPDATE_GOLDEN=1.
 *
 * The register and schema ids (91xx) are unique to this test, because both
 * methods keep a process-wide cache of the lookups.
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
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
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
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnNameClaims
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicSchemaColumns
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumn
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicCrossTableScan
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class MagicMapperCrossTableScanGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/cross-table-scan-golden.json';

	private array $events = [];

	private static function tables(): array {
		return [
			['table_name' => 'oc_openregister_table_9101_9201'],
			['TABLE_NAME' => 'oc_openregister_table_9102_9202'],
			['table_name' => 'oc_openregister_table_9101_9203'],
			['table_name' => 'oc_openregister_table_9103_9204'],
			['table_name' => 'oc_openregister_table_9101_x'],
			['table_name' => 'oc_openregister_tables_meta'],
			['other' => 'no name'],
		];
	}//end tables()

	private static function unionMatches(): array {
		return [
			['_source_table' => 'oc_openregister_table_9101_9201', 'found_uuid' => 'u-1'],
			['_source_table' => 'oc_openregister_table_9101_9201', 'found_uuid' => 'u-2'],
			['_source_table' => 'oc_openregister_table_9102_9202', 'found_uuid' => 'u-3'],
			['_source_table' => 'oc_openregister_table_9103_9204', 'found_uuid' => 'u-4'],
			['_source_table' => 'oc_unknown', 'found_uuid' => 'u-5'],
		];
	}//end unionMatches()

	private static function cases(): array {
		$cases = [];
		foreach (['pg', 'maria'] as $platform) {
			foreach ([false, true] as $deleted) {
				$d = var_export($deleted, true);
				$cases["{$platform} / multiple / deleted {$d}"] = [$platform, 'multiple', [['u-1', 'u-2', 'u-1', 'u-3'], $deleted, null], null];
				$cases["{$platform} / multiple scoped / deleted {$d}"] = [$platform, 'multiple', [['u-1', 'u-3'], $deleted, 9101], null];
				$cases["{$platform} / relation / deleted {$d}"] = [$platform, 'relation', ['target-uuid', $deleted], null];
			}

			$cases["{$platform} / multiple / union fails"] = [$platform, 'multiple', [['u-1'], false, null], 'UNION'];
			$cases["{$platform} / relation / union fails"] = [$platform, 'relation', ['t', false], 'UNION'];
			$cases["{$platform} / multiple / no tables"] = [$platform, 'multiple', [['u-1'], false, null], 'no-tables'];
			$cases["{$platform} / relation / no tables"] = [$platform, 'relation', ['t', false], 'no-tables'];
			$cases["{$platform} / multiple / scope matches nothing"] = [$platform, 'multiple', [['u-1'], false, 4242], null];
		}

		$cases['multiple / empty'] = ['pg', 'multiple', [[], false, null], null];
		$cases['relation / empty'] = ['pg', 'relation', ['', false], null];

		return $cases;
	}//end cases()

	public function testCrossTableScansMatchGolden(): void {
		$actual = [];
		foreach (self::cases() as $name => [$platform, $method, $args, $fail]) {
			$this->events = [];
			$this->queryLog = [];
			$this->builderCount = 0;
			$this->parameterCount = 0;
			$this->queuedRows = [
				[['_uuid' => 'u-1', '_name' => 'One'], ['_uuid' => 'u-2']],
				[['_uuid' => 'u-3', '_name' => 'Three']],
				[['_uuid' => 'u-4']],
			];
			$mapper = $this->makeMapper(platform: $platform, fail: $fail);
			if ($method === 'multiple') {
				$found = $mapper->findMultipleAcrossAllMagicTables($args[0], $args[1], $args[2]);
			} else {
				$found = $mapper->findByRelationAcrossAllMagicTables($args[0], $args[1]);
			}

			$actual[$name] = [
				'events' => $this->events,
				'queries' => $this->queryLog,
				'found' => array_map(
					static fn ($o): array => [$o?->getUuid(), $o?->getRegister(), $o?->getSchema(), $o?->getName()],
					$found
				),
			];
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
	}//end testCrossTableScansMatchGolden()

	private function makeMapper(string $platform, ?string $fail): MagicMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(
			$platform === 'pg' ? $this->createMock(PostgreSQLPlatform::class) : $this->createMock(MariaDBPlatform::class)
		);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
		$db->method('prepare')->willReturnCallback(
			function (string $sql) use ($fail): IPreparedStatement {
				$this->events[] = ['prepare' => $sql];
				$statement = $this->createMock(IPreparedStatement::class);
				$statement->method('execute')->willReturnCallback(
					function (?array $params = null) use ($sql, $fail): IResult {
						$this->events[] = ['execute' => $params];
						if ($fail === 'UNION' && str_contains($sql, 'UNION ALL') === true) {
							throw new \Exception('union refused');
						}

						$rows = self::unionMatches();
						if (str_contains($sql, 'information_schema') === true) {
							$rows = $fail === 'no-tables' ? [] : self::tables();
						}

						$result = $this->createMock(IResult::class);
						$result->method('fetchAll')->willReturn($rows);
						return $result;
					}
				);
				return $statement;
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

		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturnCallback(
			function (...$args): Register {
				$this->events[] = ['registerFind' => $args];
				if ($args[0] === 9103) {
					throw new DoesNotExistException('gone');
				}

				return new Register();
			}
		);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(
			function (...$args): Schema {
				$this->events[] = ['schemaFind' => $args];
				return new Schema();
			}
		);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturn('oc_');

		$reflection = new ReflectionClass(MagicMapper::class);
		$mapper = $reflection->newInstanceWithoutConstructor();
		foreach (['db' => $db, 'logger' => $logger, 'config' => $config, 'registerMapper' => $registers, 'schemaMapper' => $schemas] as $prop => $value) {
			$property = $reflection->getProperty($prop);
			$property->setAccessible(true);
			$property->setValue($mapper, $value);
		}

		return $mapper;
	}//end makeMapper()
}//end class
