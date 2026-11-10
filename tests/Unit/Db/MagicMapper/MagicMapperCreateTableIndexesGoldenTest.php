<?php

/**
 * Golden test for MagicMapper::createTableIndexes() and getMetadataColumns().
 *
 * Records every index statement and log line that index creation produces per
 * platform, pg_trgm availability, schema property shape and failing statement,
 * and the metadata column table, so a decomposition keeps the exact output.
 * Re-record with UPDATE_GOLDEN=1.
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

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumns;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableCreator
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexRun
 * @uses   \OCA\OpenRegister\Db\ObjectHandling
 */
class MagicMapperCreateTableIndexesGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/create-table-indexes-golden.json';

	private array $events = [];

	private static function properties(): array {
		return [
			'relatedObject' => ['type' => 'object', '$ref' => '#/x', 'objectConfiguration' => ['handling' => 'related-object']],
			'related_schema' => ['type' => 'object', '$ref' => '#/y', 'objectConfiguration' => ['handling' => 'related-schema']],
			'nestedObject' => ['type' => 'object', '$ref' => '#/z', 'objectConfiguration' => ['handling' => 'nested-object']],
			'noRef' => ['type' => 'object', 'objectConfiguration' => ['handling' => 'related-object']],
			'itemsRef' => ['type' => 'array', 'items' => ['$ref' => '#/a']],
			'itemsInversed' => ['type' => 'array', 'items' => ['inversedBy' => 'b']],
			'topInversed' => ['type' => 'array', 'inversedBy' => 'c'],
			'plainArray' => ['type' => 'array', 'items' => ['type' => 'string']],
			'facetField' => ['type' => 'string', 'facetable' => true],
			'facetFalse' => ['type' => 'string', 'facetable' => 'yes'],
			'Searchable Name' => ['type' => 'string', 'searchable' => true],
			'bothFlags' => ['type' => 'string', 'searchable' => true, 'facetable' => true],
			'untyped' => ['facetable' => true],
			'relatedFacet' => ['type' => 'object', '$ref' => '#/q', 'objectConfiguration' => ['handling' => 'related-object'], 'facetable' => true],
			'q"uote' => ['type' => 'string', 'facetable' => true],
		];
	}//end properties()

	private static function cases(): array {
		$platforms = ['postgres-trgm' => ['pg', 1], 'postgres-no-trgm' => ['pg', 0], 'mariadb' => ['maria', 0]];
		$failures = [null, '_rel_idx', '_arr_gin_idx', '_facet_idx', 'searchable_name_trgm_idx', '_name_trgm_idx', 'deleted_owner_idx', '_uuid_idx', '_relations_gin_idx', '_slug_idx'];
		$cases = [];
		foreach ($platforms as $pname => $platform) {
			foreach ($failures as $fail) {
				$cases[$pname . ' / fail ' . ($fail ?? 'none')] = [$platform[0], $platform[1], $fail, self::properties()];
			}

			$cases[$pname . ' / empty properties'] = [$platform[0], $platform[1], null, []];
		}

		return $cases;
	}//end cases()

	public function testIndexCreationMatchesGolden(): void {
		$actual = [];
		foreach (self::cases() as $name => [$platform, $trgm, $fail, $properties]) {
			$this->events = [];
			$mapper = $this->makeMapper(platform: $platform, trgm: $trgm, fail: $fail);
			$schema = $this->createMock(Schema::class);
			$schema->method('getProperties')->willReturn($properties);
			$method = new ReflectionMethod(MagicMapper::class, 'createTableIndexes');
			$method->setAccessible(true);
			$method->invokeArgs($mapper, ['openregister_table_1_2', $schema]);
			$actual[$name] = $this->events;
		}

		$actual['metadata columns'] = MagicMetadataColumns::ALL;

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $events) {
			$this->assertSame($events, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testIndexCreationMatchesGolden()

	private function makeMapper(string $platform, int $trgm, ?string $fail): MagicMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($this->platform(name: $platform));
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql) use ($fail): int {
				$this->events[] = ['sql' => $sql];
				if ($fail !== null && str_contains($sql, $fail) === true) {
					throw new \OCP\DB\Exception('refused: ' . $fail);
				}

				return 0;
			}
		);
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn($trgm);
		$stmt = $this->createMock(IPreparedStatement::class);
		$stmt->method('execute')->willReturn($result);
		$db->method('prepare')->willReturnCallback(
			function (string $sql) use ($stmt): IPreparedStatement {
				$this->events[] = ['prepare' => $sql];
				return $stmt;
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

		$reflection = new ReflectionClass(MagicMapper::class);
		$mapper = $reflection->newInstanceWithoutConstructor();
		foreach (['db' => $db, 'logger' => $logger, 'config' => $config] as $prop => $value) {
			$property = $reflection->getProperty($prop);
			$property->setAccessible(true);
			$property->setValue($mapper, $value);
		}

		return $mapper;
	}//end makeMapper()

	private function platform(string $name): AbstractPlatform {
		if ($name === 'pg') {
			return $this->createMock(PostgreSQLPlatform::class);
		}

		return $this->createMock(MariaDBPlatform::class);
	}//end platform()
}//end class
