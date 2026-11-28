<?php

/**
 * A search across many magic tables reads their catalogue in one query.
 *
 * Before, each table of a cross-table search asked information_schema twice:
 * whether it exists and which columns it has. One information_schema.columns
 * read over all of them now answers both; a table the read does not return
 * keeps the one-table path, so a missing table is never recorded as missing.
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
 * @spec openspec/changes/union-catalogue-one-read/specs/upgrade-performance/spec.md#requirement-a-search-across-many-magic-tables-reads-their-catalogue-in-one-query
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use OCA\OpenRegister\Db\MagicMapper;
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
 * Locks primeTableCatalogue() against the one-table lookups it replaces.
 */
class MagicMapperCatalogueBatchTest extends TestCase {

	/**
	 * The SQL of every query the connection double was asked to prepare.
	 *
	 * @var array<int, string>
	 */
	private array $prepared = [];

	/**
	 * The parameters of every executed query.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $executed = [];

	protected function setUp(): void {
		$this->resetStaticCaches();
		$this->prepared = [];
		$this->executed = [];
	}//end setUp()

	protected function tearDown(): void {
		$this->resetStaticCaches();
	}//end tearDown()

	/**
	 * Clear the process-wide column caches so tests do not leak into each other.
	 *
	 * @return void
	 */
	private function resetStaticCaches(): void {
		$reflection = new ReflectionClass(MagicMapper::class);
		foreach (['columnExistsCache', 'columnTypeCache'] as $name) {
			$prop = $reflection->getProperty($name);
			$prop->setAccessible(true);
			$prop->setValue(null, []);
		}
	}//end resetStaticCaches()

	/**
	 * Build a mapper whose every query returns the given rows, in order, once.
	 *
	 * @param array<int, array<string, string>> $rows     Rows of the batched read.
	 * @param string                            $platform The platform class the connection reports.
	 * @param bool                              $fail     Whether the batched read throws.
	 *
	 * @return MagicMapper The mapper with db, logger and config wired up.
	 */
	private function makeMapper(array $rows, string $platform = MySQLPlatform::class, bool $fail = false): MagicMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($this->createMock($platform));
		$db->method('prepare')->willReturnCallback(
			function (string $sql) use ($rows, $fail) {
				$this->prepared[] = $sql;
				$queue = $rows;
				if (str_contains($sql, 'IN (') === false) {
					// A one-table query: answer it as an empty table.
					$queue = [];
				}

				$stmt = $this->createMock(IPreparedStatement::class);
				$stmt->method('execute')->willReturnCallback(
					function (array $params = []) use ($fail, $sql) {
						$this->executed[] = $params;
						if ($fail === true && str_contains($sql, 'IN (') === true) {
							throw new RuntimeException('catalogue unavailable');
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

		$reflection = new ReflectionClass(MagicMapper::class);
		$mapper = $reflection->newInstanceWithoutConstructor();
		$values = [
			'db'     => $db,
			'logger' => $this->createMock(LoggerInterface::class),
			'config' => $config,
		];
		foreach ($values as $name => $value) {
			$prop = $reflection->getProperty($name);
			$prop->setAccessible(true);
			$prop->setValue($mapper, $value);
		}

		return $mapper;
	}//end makeMapper()

	/**
	 * Catalogue rows for two of three asked tables.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function rows(): array {
		return [
			['tbl' => 'oc_openregister_table_1_2', 'col' => '_uuid', 'col_type' => 'varchar'],
			['tbl' => 'oc_openregister_table_1_2', 'col' => 'title', 'col_type' => 'text'],
			['tbl' => 'OC_OPENREGISTER_TABLE_3_4', 'col' => '_created', 'col_type' => 'datetime'],
		];
	}//end rows()

	/**
	 * Call a private mapper method.
	 *
	 * @param MagicMapper $mapper  The mapper.
	 * @param string      $name    The method name.
	 * @param mixed       ...$args The arguments.
	 *
	 * @return mixed The method's return value.
	 */
	private function call(MagicMapper $mapper, string $name, mixed ...$args): mixed {
		$method = new ReflectionMethod(MagicMapper::class, $name);
		return $method->invoke($mapper, ...$args);
	}//end call()

	/**
	 * The table-exists memo of a mapper.
	 *
	 * @param MagicMapper $mapper The mapper.
	 *
	 * @return array<string, bool>
	 */
	private function existsMemo(MagicMapper $mapper): array {
		$prop = (new ReflectionClass(MagicMapper::class))->getProperty('tableExistsMemo');
		$prop->setAccessible(true);
		return $prop->getValue($mapper);
	}//end existsMemo()

	public function testOneQueryAnswersColumnsAndExistenceOfEveryTable(): void {
		$mapper = $this->makeMapper(rows: $this->rows());

		$this->call($mapper, 'primeTableCatalogue', ['openregister_table_1_2', 'openregister_table_3_4', 'openregister_table_5_6']);

		$this->assertCount(1, $this->prepared);
		$this->assertStringContainsString('information_schema.columns', $this->prepared[0]);
		$this->assertStringContainsString('table_schema = DATABASE()', $this->prepared[0]);
		$this->assertStringNotContainsString('LOWER(table_name)', $this->prepared[0]);
		$this->assertSame(
			['oc_openregister_table_1_2', 'oc_openregister_table_3_4', 'oc_openregister_table_5_6'],
			$this->executed[0]
		);

		// Both found tables now answer from memory, with no further query.
		$this->assertTrue($this->call($mapper, 'columnExistsInTable', 'openregister_table_1_2', 'title'));
		$this->assertFalse($this->call($mapper, 'columnExistsInTable', 'openregister_table_1_2', 'missing'));
		$this->assertSame(['_created'], $this->call($mapper, 'getDateTimeColumns', 'openregister_table_3_4'));
		$this->assertTrue($this->call($mapper, 'checkTableExistsInDatabase', 'openregister_table_1_2'));
		$this->assertTrue($this->call($mapper, 'checkTableExistsInDatabase', 'openregister_table_3_4'));
		$this->assertCount(1, $this->prepared);
	}//end testOneQueryAnswersColumnsAndExistenceOfEveryTable()

	public function testATableTheReadDoesNotReturnIsNotRecordedAsMissing(): void {
		$mapper = $this->makeMapper(rows: $this->rows());

		$this->call($mapper, 'primeTableCatalogue', ['openregister_table_1_2', 'openregister_table_5_6']);

		$this->assertArrayNotHasKey('openregister_table_5_6', $this->existsMemo($mapper));

		// The missing table keeps the one-table path: it is probed again.
		$this->call($mapper, 'checkTableExistsInDatabase', 'openregister_table_5_6');
		$this->assertCount(2, $this->prepared);
		$this->assertStringContainsString('information_schema.tables', $this->prepared[1]);
	}//end testATableTheReadDoesNotReturnIsNotRecordedAsMissing()

	public function testTablesAlreadyReadAreNotReadAgain(): void {
		$mapper = $this->makeMapper(rows: $this->rows());

		$this->call($mapper, 'primeTableCatalogue', ['openregister_table_1_2', 'openregister_table_3_4']);
		$this->call($mapper, 'primeTableCatalogue', ['openregister_table_1_2', 'openregister_table_3_4']);

		$this->assertCount(1, $this->prepared);
	}//end testTablesAlreadyReadAreNotReadAgain()

	public function testASingleTableKeepsTheOneTableQuery(): void {
		$mapper = $this->makeMapper(rows: $this->rows());

		$this->call($mapper, 'primeTableCatalogue', ['openregister_table_1_2']);

		$this->assertSame([], $this->prepared);
	}//end testASingleTableKeepsTheOneTableQuery()

	public function testSqliteReadsNothing(): void {
		$mapper = $this->makeMapper(rows: $this->rows(), platform: SqlitePlatform::class);

		$this->call($mapper, 'primeTableCatalogue', ['openregister_table_1_2', 'openregister_table_3_4']);

		$this->assertSame([], $this->prepared);
	}//end testSqliteReadsNothing()

	public function testAFailedReadFallsBackToTheOneTablePath(): void {
		$mapper = $this->makeMapper(rows: $this->rows(), fail: true);

		$this->call($mapper, 'primeTableCatalogue', ['openregister_table_1_2', 'openregister_table_3_4']);

		$this->assertSame([], $this->existsMemo($mapper));
		$this->call($mapper, 'columnExistsInTable', 'openregister_table_1_2', 'title');
		$this->assertCount(2, $this->prepared);
		$this->assertMatchesRegularExpression('/WHERE\s+table_name\s*=\s*\?/', $this->prepared[1]);
	}//end testAFailedReadFallsBackToTheOneTablePath()
}//end class
