<?php

/**
 * The column-existence cache also records each column's type, in the same query.
 *
 * The search handler sorts an empty date property by `_created` with
 * COALESCE(<column>, _created). That is only valid when the column really is
 * a date or timestamp: a property that gained its date format after its
 * table was created keeps a text column (the table sync adds columns and
 * never retypes them), and PostgreSQL rejects COALESCE(text, timestamp).
 *
 * The types come from the information_schema query columnExistsInTable()
 * already ran, so there is still one query per table per process, and
 * columnExistsInTable() answers exactly as before.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Locks columnExistsInTable() and getDateTimeColumns() over one shared query.
 */
class MagicMapperColumnTypeCacheTest extends TestCase {

	/**
	 * How many information_schema queries the connection double was asked to prepare.
	 */
	private int $prepareCount = 0;

	/**
	 * The SQL of the last prepared query.
	 */
	private string $lastSql = '';

	protected function setUp(): void {
		$this->resetStaticCaches();
		$this->prepareCount = 0;
		$this->lastSql = '';
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
	 * Build a mapper whose information_schema query returns the given rows.
	 *
	 * @param array<int, array<string, string>> $rows Rows as the query returns them.
	 * @param IDBConnection|null                $db   A connection double to use instead.
	 *
	 * @return MagicMapper The mapper with db, logger and config wired up.
	 */
	private function makeMapper(array $rows, ?IDBConnection $db = null): MagicMapper {
		$stmt = $this->createMock(IPreparedStatement::class);
		$stmt->method('execute')->willReturn($this->createMock(IResult::class));
		$queue = $rows;
		$stmt->method('fetch')->willReturnCallback(
			function () use (&$queue) {
				if ($queue === []) {
					return false;
				}

				return array_shift($queue);
			}
		);

		if ($db === null) {
			$db = $this->createMock(IDBConnection::class);
			$db->method('prepare')->willReturnCallback(
				function (string $sql) use ($stmt) {
					$this->prepareCount++;
					$this->lastSql = $sql;
					return $stmt;
				}
			);
		}

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
	 * Rows for a table with a real timestamp, a text date column and a plain column.
	 *
	 * @return array<int, array<string, string>> The information_schema rows.
	 */
	private function rows(): array {
		return [
			['col' => '_created', 'col_type' => 'timestamp without time zone'],
			['col' => 'occurred_at', 'col_type' => 'timestamp without time zone'],
			['col' => 'due_date', 'col_type' => 'date'],
			['col' => 'legacy_at', 'col_type' => 'text'],
			['col' => 'title', 'col_type' => 'character varying'],
		];
	}//end rows()

	/**
	 * Call a private mapper method.
	 *
	 * @param MagicMapper $mapper The mapper.
	 * @param string      $name   The method name.
	 * @param mixed       ...$args The arguments.
	 *
	 * @return mixed The method's return value.
	 */
	private function call(MagicMapper $mapper, string $name, mixed ...$args): mixed {
		$method = new ReflectionMethod(MagicMapper::class, $name);
		return $method->invoke($mapper, ...$args);
	}//end call()

	public function testColumnExistsInTableAnswersAsBefore(): void {
		$mapper = $this->makeMapper($this->rows());

		$this->assertTrue($this->call($mapper, 'columnExistsInTable', 'openregister_table_1_2', 'occurred_at'));
		$this->assertTrue($this->call($mapper, 'columnExistsInTable', 'openregister_table_1_2', 'TITLE'));
		$this->assertTrue($this->call($mapper, 'columnExistsInTable', 'oc_openregister_table_1_2', 'legacy_at'));
		$this->assertFalse($this->call($mapper, 'columnExistsInTable', 'openregister_table_1_2', 'missing'));
		$this->assertSame(1, $this->prepareCount);
	}//end testColumnExistsInTableAnswersAsBefore()

	public function testDateTimeColumnsComeFromTheSameQuery(): void {
		$mapper = $this->makeMapper($this->rows());

		$this->call($mapper, 'columnExistsInTable', 'openregister_table_1_2', 'title');
		$columns = $this->call($mapper, 'getDateTimeColumns', 'openregister_table_1_2');

		$this->assertSame(['_created', 'occurred_at', 'due_date'], $columns);
		$this->assertSame(1, $this->prepareCount);
		$this->assertStringContainsString('data_type', $this->lastSql);
	}//end testDateTimeColumnsComeFromTheSameQuery()

	public function testMySqlDateTypesAreRecognised(): void {
		$mapper = $this->makeMapper(
			[
				['col' => '_created', 'col_type' => 'DATETIME'],
				['col' => 'stamp', 'col_type' => 'timestamp'],
				['col' => 'day', 'col_type' => 'date'],
				['col' => 'note', 'col_type' => 'longtext'],
			]
		);

		$this->assertSame(['_created', 'stamp', 'day'], $this->call($mapper, 'getDateTimeColumns', 'openregister_table_3_4'));
	}//end testMySqlDateTypesAreRecognised()

	public function testFailedLookupGivesNoDateColumns(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('prepare')->willThrowException(new \Exception('information_schema unavailable'));
		$mapper = $this->makeMapper([], $db);

		$this->assertSame([], $this->call($mapper, 'getDateTimeColumns', 'openregister_table_5_6'));
		$this->assertFalse($this->call($mapper, 'columnExistsInTable', 'openregister_table_5_6', '_created'));
	}//end testFailedLookupGivesNoDateColumns()
}//end class
