<?php

/**
 * The multi-table UUID lookup can be held to one register's tables.
 *
 * Extend preloads look a reference up in the referring object's register
 * first: one UNION over every magic table costs seconds on an instance with
 * 1.7k of them, a register holds a few dozen.
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
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Locks the register scope of findMultipleAcrossAllMagicTables().
 */
class MagicMapperFindMultipleRegisterScopeTest extends TestCase {

	/**
	 * The SQL of every prepared query, in order.
	 *
	 * @var array<int, string>
	 */
	private array $sql = [];

	/**
	 * A mapper whose table listing returns three magic tables in two registers.
	 *
	 * @return MagicMapper The mapper.
	 */
	private function makeMapper(): MagicMapper {
		$tables = [
			['table_name' => 'oc_openregister_table_23_165'],
			['table_name' => 'oc_openregister_table_23_172'],
			['table_name' => 'oc_openregister_table_25_663'],
		];

		$db = $this->createMock(originalClassName: IDBConnection::class);
		$db->method('prepare')->willReturnCallback(
			function (string $sql) use ($tables) {
				$this->sql[] = $sql;
				$result = $this->createMock(originalClassName: IResult::class);
				$rows = [];
				if (str_contains(haystack: $sql, needle: 'information_schema.tables') === true) {
					$rows = $tables;
				}

				$result->method('fetchAll')->willReturn($rows);
				$stmt = $this->createMock(originalClassName: IPreparedStatement::class);
				$stmt->method('execute')->willReturn($result);
				return $stmt;
			}
		);

		$config = $this->createMock(originalClassName: IConfig::class);
		$config->method('getSystemValue')->willReturn('oc_');

		$reflection = new ReflectionClass(objectOrClass: MagicMapper::class);
		$mapper = $reflection->newInstanceWithoutConstructor();
		$values = [
			'db'     => $db,
			'logger' => $this->createMock(originalClassName: LoggerInterface::class),
			'config' => $config,
			'registerMapper' => $this->createMock(originalClassName: RegisterMapper::class),
			'schemaMapper'   => $this->createMock(originalClassName: SchemaMapper::class),
		];
		foreach ($values as $name => $value) {
			$prop = $reflection->getProperty(name: $name);
			$prop->setValue(objectOrValue: $mapper, value: $value);
		}

		return $mapper;
	}//end makeMapper()

	/**
	 * With a register scope, the union only names that register's tables.
	 *
	 * @return void
	 */
	public function testTheScopedLookupNamesOnlyTheRegistersTables(): void {
		$this->makeMapper()->findMultipleAcrossAllMagicTables(uuids: ['3c0f5a00-0000-4000-a000-00000000a002'], registerIdScope: 23);

		$union = $this->sql[1] ?? '';
		$this->assertStringContainsString(needle: 'oc_openregister_table_23_165', haystack: $union);
		$this->assertStringContainsString(needle: 'oc_openregister_table_23_172', haystack: $union);
		$this->assertStringNotContainsString(needle: 'oc_openregister_table_25_663', haystack: $union);
	}//end testTheScopedLookupNamesOnlyTheRegistersTables()

	/**
	 * Without a scope, every magic table is still searched.
	 *
	 * @return void
	 */
	public function testTheUnscopedLookupNamesEveryTable(): void {
		$this->makeMapper()->findMultipleAcrossAllMagicTables(uuids: ['3c0f5a00-0000-4000-a000-00000000a002']);

		$union = $this->sql[1] ?? '';
		$this->assertStringContainsString(needle: 'oc_openregister_table_25_663', haystack: $union);
		$this->assertStringContainsString(needle: 'oc_openregister_table_23_165', haystack: $union);
	}//end testTheUnscopedLookupNamesEveryTable()
}//end class
