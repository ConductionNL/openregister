<?php

/**
 * A plain text search across many magic tables leaves out the tables without rows.
 *
 * One `LIMIT 1` probe per table, all in one statement, says which tables hold
 * a row; only those get a union arm. A query with any key besides the plain
 * search keys keeps every arm, so a per-schema refusal still fires, and a
 * table this request does not know to exist keeps its arm.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/union-skips-empty-tables/specs/upgrade-performance/spec.md#requirement-a-plain-text-search-across-many-tables-leaves-out-the-tables-without-rows
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicTableHandler;
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
use RuntimeException;

/**
 * Locks pairsWithRows() and tablesHoldingRows().
 */
class MagicMapperUnionRowProbeTest extends TestCase {

	/**
	 * The SQL of every statement the connection double was asked to prepare.
	 *
	 * @var array<int, string>
	 */
	private array $prepared = [];

	/**
	 * Build a mapper whose probe answers with the given probe indexes.
	 *
	 * @param array<int, int>     $holding The probe indexes that hold a row.
	 * @param array<string, bool> $known   The table-exists memo.
	 * @param bool                $fail    Whether the probe throws.
	 *
	 * @return MagicMapper The mapper.
	 */
	private function makeMapper(array $holding, array $known, bool $fail = false): MagicMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($this->createMock(MySQLPlatform::class));
		$db->method('prepare')->willReturnCallback(
			function (string $sql) use ($holding, $fail) {
				$this->prepared[] = $sql;
				$queue = array_map(fn (int $index): array => ['probe_index' => (string)$index], $holding);
				$stmt  = $this->createMock(IPreparedStatement::class);
				$stmt->method('execute')->willReturnCallback(
					function () use ($fail) {
						if ($fail === true) {
							throw new RuntimeException('probe unavailable');
						}

						return $this->createMock(IResult::class);
					}
				);
				$stmt->method('fetch')->willReturnCallback(
					function () use (&$queue) {
						if ($queue === []) {
							return false;
						}

						return array_shift($queue);
					}
				);

				return $stmt;
			}
		);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturn('oc_');

		$tableHandler = $this->createMock(MagicTableHandler::class);
		$tableHandler->method('getTableNameForRegisterSchema')->willReturnCallback(
			fn (Register $register, Schema $schema): string => 'openregister_table_'.$register->getId().'_'.$schema->getId()
		);

		$reflection = new ReflectionClass(MagicMapper::class);
		$mapper     = $reflection->newInstanceWithoutConstructor();
		$values     = [
			'db'              => $db,
			'logger'          => $this->createMock(LoggerInterface::class),
			'config'          => $config,
			'tableHandler'    => $tableHandler,
			'tableExistsMemo' => $known,
		];
		foreach ($values as $name => $value) {
			$prop = $reflection->getProperty($name);
			$prop->setAccessible(true);
			$prop->setValue($mapper, $value);
		}

		return $mapper;
	}//end makeMapper()

	/**
	 * A register+schema pair.
	 *
	 * @param int $registerId The register id.
	 * @param int $schemaId   The schema id.
	 *
	 * @return array{register: Register, schema: Schema}
	 */
	private function pair(int $registerId, int $schemaId): array {
		$register = new Register();
		$register->setId($registerId);
		$schema = new Schema();
		$schema->setId($schemaId);

		return ['register' => $register, 'schema' => $schema];
	}//end pair()

	/**
	 * Three pairs whose tables all exist.
	 *
	 * @return array<int, array{register: Register, schema: Schema}>
	 */
	private function pairs(): array {
		return [$this->pair(1, 1), $this->pair(1, 2), $this->pair(2, 3)];
	}//end pairs()

	/**
	 * The exists memo for the three tables of pairs().
	 *
	 * @return array<string, bool>
	 */
	private function allKnown(): array {
		return ['openregister_table_1_1' => true, 'openregister_table_1_2' => true, 'openregister_table_2_3' => true];
	}//end allKnown()

	/**
	 * Call pairsWithRows().
	 *
	 * @param MagicMapper $mapper The mapper.
	 * @param array       $query  The query.
	 * @param array       $pairs  The pairs.
	 *
	 * @return array The kept pairs.
	 */
	private function pairsWithRows(MagicMapper $mapper, array $query, array $pairs): array {
		$method = new ReflectionMethod(MagicMapper::class, 'pairsWithRows');
		return $method->invoke($mapper, $query, $pairs);
	}//end pairsWithRows()

	/**
	 * The table names of pairs.
	 *
	 * @param array $pairs The pairs.
	 *
	 * @return array<int, string>
	 */
	private function tablesOf(array $pairs): array {
		return array_map(fn (array $pair): string => $pair['register']->getId().'_'.$pair['schema']->getId(), $pairs);
	}//end tablesOf()

	public function testOneProbeStatementKeepsOnlyTheTablesHoldingRows(): void {
		$mapper = $this->makeMapper(holding: [2, 0], known: $this->allKnown());

		$kept = $this->pairsWithRows($mapper, ['_search' => 'e', '_limit' => 20, '_offset' => 0, '_rbac' => true, '_multitenancy' => true, '@self' => []], $this->pairs());

		$this->assertSame(['1_1', '2_3'], $this->tablesOf($kept));
		$this->assertCount(1, $this->prepared);
		$this->assertSame(
			'SELECT probe_index FROM (SELECT 0 AS probe_index FROM `oc_openregister_table_1_1` LIMIT 1) probe_0'
			.' UNION ALL SELECT probe_index FROM (SELECT 1 AS probe_index FROM `oc_openregister_table_1_2` LIMIT 1) probe_1'
			.' UNION ALL SELECT probe_index FROM (SELECT 2 AS probe_index FROM `oc_openregister_table_2_3` LIMIT 1) probe_2',
			$this->prepared[0]
		);
	}//end testOneProbeStatementKeepsOnlyTheTablesHoldingRows()

	public function testNoTableHoldingRowsLeavesNoPair(): void {
		$mapper = $this->makeMapper(holding: [], known: $this->allKnown());

		$this->assertSame([], $this->pairsWithRows($mapper, ['_search' => 'zzz'], $this->pairs()));
	}//end testNoTableHoldingRowsLeavesNoPair()

	/**
	 * Keys that can make an arm builder refuse the query for one schema.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function refusableQueries(): array {
		return [
			'property filter' => [['_search' => 'e', 'secret' => 'x']],
			'order'           => [['_search' => 'e', '_order' => ['secret' => 'asc']]],
			'where tree'      => [['_search' => 'e', '_where' => ['secret' => 'x']]],
			'unknown flag'    => [['_search' => 'e', '_something' => true]],
		];
	}//end refusableQueries()

	/**
	 * @dataProvider refusableQueries
	 *
	 * @param array<string, mixed> $query The query.
	 */
	public function testAnyOtherQueryKeyKeepsEveryArm(array $query): void {
		$mapper = $this->makeMapper(holding: [], known: $this->allKnown());

		$this->assertSame(['1_1', '1_2', '2_3'], $this->tablesOf($this->pairsWithRows($mapper, $query, $this->pairs())));
		$this->assertSame([], $this->prepared);
	}//end testAnyOtherQueryKeyKeepsEveryArm()

	public function testATableNotKnownToExistKeepsItsArmAndIsNotProbed(): void {
		$known = $this->allKnown();
		unset($known['openregister_table_1_2']);
		$mapper = $this->makeMapper(holding: [], known: $known);

		$kept = $this->pairsWithRows($mapper, ['_search' => 'e'], $this->pairs());

		$this->assertSame(['1_2'], $this->tablesOf($kept));
		$this->assertStringNotContainsString('table_1_2', $this->prepared[0]);
	}//end testATableNotKnownToExistKeepsItsArmAndIsNotProbed()

	public function testAFailedProbeKeepsEveryPair(): void {
		$mapper = $this->makeMapper(holding: [], known: $this->allKnown(), fail: true);

		$this->assertSame(['1_1', '1_2', '2_3'], $this->tablesOf($this->pairsWithRows($mapper, ['_search' => 'e'], $this->pairs())));
	}//end testAFailedProbeKeepsEveryPair()

	public function testASingleTableIsNotProbed(): void {
		$mapper = $this->makeMapper(holding: [], known: $this->allKnown());

		$this->assertSame(['1_1'], $this->tablesOf($this->pairsWithRows($mapper, ['_search' => 'e'], [$this->pair(1, 1)])));
		$this->assertSame([], $this->prepared);
	}//end testASingleTableIsNotProbed()
}//end class
