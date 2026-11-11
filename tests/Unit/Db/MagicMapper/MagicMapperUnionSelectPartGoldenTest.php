<?php

/**
 * Golden test for MagicMapper::buildUnionSelectPart().
 *
 * Records the SELECT each UNION arm builds and the question it hands the
 * search handler, per platform, pg_trgm availability, projection mode, search
 * term and column presence. Re-record with UPDATE_GOLDEN=1.
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
use OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\MagicMapper\WhereClauseRequest;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\DB\QueryBuilder\IQueryBuilder;
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
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicUnionArm
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicUnionScore
 */
class MagicMapperUnionSelectPartGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/union-select-part-golden.json';

	private const TABLE = 'openregister_table_3_4';

	private array $events = [];

	protected function tearDown(): void {
		$this->resetCaches();
		parent::tearDown();
	}//end tearDown()

	private static function cases(): array {
		$schemaProps = [
			'title' => ['type' => 'string'],
			'Body Text' => ['type' => 'text'],
			'count' => ['type' => 'integer'],
			'missingCol' => ['type' => 'string'],
			'untyped' => [],
		];
		$superset = ['title' => true, 'body_text' => true, 'other_schema_col' => true, 'count' => true];
		$queries = [
			'no search' => ['color' => 'red'],
			'search' => ['_search' => "it's", '@self' => ['schema' => [1, 2, 3]]],
			'empty search' => ['_search' => '', '@self' => ['register' => 3]],
			'self schema null' => ['@self' => ['schema' => null]],
			'self not array' => ['@self' => 'x', '_search' => 'q'],
		];
		$cases = [];
		foreach (['pg-trgm' => ['pg', true], 'pg' => ['pg', false], 'maria' => ['maria', false]] as $pname => [$platform, $trgm]) {
			foreach ($queries as $qname => $query) {
				foreach ([false, true] as $metadataOnly) {
					$cases["{$pname} / {$qname} / metadataOnly " . var_export($metadataOnly, true)] = [$platform, $trgm, $query, $schemaProps, $superset, $metadataOnly, ['a = 1']];
				}
			}

			$cases["{$pname} / search without properties"] = [$platform, $trgm, ['_search' => 'q'], [], $superset, false, []];
			$cases["{$pname} / search, no text columns"] = [$platform, $trgm, ['_search' => 'q'], ['count' => ['type' => 'integer']], [], false, ['a', 'b']];
		}

		return $cases;
	}//end cases()

	public function testUnionSelectPartMatchesGolden(): void {
		$actual = [];
		foreach (self::cases() as $name => [$platform, $trgm, $query, $props, $superset, $metadataOnly, $where]) {
			$this->events = [];
			$mapper = $this->makeMapper(platform: $platform, trgm: $trgm, where: $where);
			$schema = new Schema();
			$schema->setId(4);
			$schema->setProperties($props);
			$register = new Register();
			$register->setId(3);

			$method = new ReflectionMethod(MagicMapper::class, 'buildUnionSelectPart');
			$method->setAccessible(true);
			$sql = $method->invoke($mapper, ...$this->arguments(method: $method, values: [self::TABLE, $query, $schema, $register, $superset, $metadataOnly]));
			$actual[$name] = ['sql' => $sql, 'events' => $this->events];
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
	}//end testUnionSelectPartMatchesGolden()

	/**
	 * The method's arguments: the six values, or one union arm built from them.
	 */
	private function arguments(ReflectionMethod $method, array $values): array {
		if ($method->getNumberOfParameters() === 6) {
			return $values;
		}

		$arm = new \OCA\OpenRegister\Db\MagicMapper\MagicUnionArm(
			tableName: $values[0],
			query: $values[1],
			schema: $values[2],
			register: $values[3]
		);
		$arm->allPropertyColumns = $values[4];
		$arm->metadataOnly = $values[5];
		return [$arm];
	}//end arguments()

	private function makeMapper(string $platform, bool $trgm, array $where): MagicMapper {
		$this->resetCaches();
		$full = 'oc_' . self::TABLE;
		$columns = ['_id' => true, '_uuid' => true, '_name' => true, '_register' => true, '_schema' => true, 'title' => true, 'body_text' => true, 'count' => true];
		$this->setStatic(name: 'columnExistsCache', value: [$full => $columns]);
		$this->setStatic(name: 'columnTypeCache', value: [$full => array_map(static fn (): string => 'text', $columns)]);

		$connection = $this->createMock(IDBConnection::class);
		$connection->method('quote')->willReturnCallback(static fn ($v): string => "'" . str_replace("'", "''", (string) $v) . "'");
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('getConnection')->willReturn($connection);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(
			$platform === 'pg' ? $this->createMock(PostgreSQLPlatform::class) : $this->createMock(MariaDBPlatform::class)
		);
		$db->method('getQueryBuilder')->willReturn($qb);

		$search = $this->createMock(MagicSearchHandler::class);
		$search->method('buildWhereClause')->willReturnCallback(
			function (WhereClauseRequest $request) use ($where): array {
				$named = [
					'query'           => $request->query,
					'schema'          => $request->table->schema->getId(),
					'existingColumns' => $request->existingColumns,
					'registerId'      => $request->table->registerId,
					'tableName'       => $request->table->tableName,
				];
				$this->events[] = ['where' => $named];
				return $where;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturn('oc_');

		$probe = new MagicPgTrgmProbe(db: $db, logger: $logger);
		$available = new \ReflectionProperty(MagicPgTrgmProbe::class, 'available');
		$available->setAccessible(true);
		$available->setValue($probe, $trgm);

		$reflection = new ReflectionClass(MagicMapper::class);
		$mapper = $reflection->newInstanceWithoutConstructor();
		foreach (['db' => $db, 'logger' => $logger, 'config' => $config, 'searchHandler' => $search, 'pgTrgmProbe' => $probe] as $prop => $value) {
			$property = $reflection->getProperty($prop);
			$property->setAccessible(true);
			$property->setValue($mapper, $value);
		}

		return $mapper;
	}//end makeMapper()

	private function setStatic(string $name, array $value): void {
		$property = new \ReflectionProperty(MagicMapper::class, $name);
		$property->setAccessible(true);
		$property->setValue(null, $value);
	}//end setStatic()

	private function resetCaches(): void {
		$this->setStatic(name: 'columnExistsCache', value: []);
		$this->setStatic(name: 'columnTypeCache', value: []);
	}//end resetCaches()
}//end class
