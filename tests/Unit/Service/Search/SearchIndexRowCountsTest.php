<?php

/**
 * The search index status says how many rows the tables in scope hold, in all
 * and per schema slug, and leaves out a count it could not make.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-the-search-index-status-names-how-many-rows-it-covers
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Search;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Search\Service\Search\SearchIndexMaintenance;
use OCP\DB\IResult;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Search\Service\Search\SearchIndexMaintenance
 * @covers \OCA\OpenRegister\Search\Service\Search\SearchIndexRowCounts
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class SearchIndexRowCountsTest extends TestCase {

	/**
	 * Every query the service ran.
	 *
	 * @var array<int, string>
	 */
	private array $queries = [];

	/**
	 * The service over two registers: cases in both, documents in one.
	 *
	 * @param array<string, int> $rows    Rows by full table name.
	 * @param array<int, string> $missing Full table names a query on fails.
	 *
	 * @return SearchIndexMaintenance
	 */
	private function service(array $rows, array $missing = []): SearchIndexMaintenance {
		$db = $this->createMock(originalClassName: IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
		$db->method('executeQuery')->willReturnCallback(
			function (string $sql) use ($rows, $missing): IResult {
				$this->queries[] = $sql;
				$answer = [];
				foreach (explode(' UNION ALL ', $sql) as $select) {
					preg_match('/^SELECT (\d+) AS t, COUNT\(\*\) AS n FROM "(\w+)"$/', $select, $parts);
					if (in_array($parts[2], $missing, true) === true) {
						throw new RuntimeException('relation "'.$parts[2].'" does not exist');
					}

					$answer[] = ['t' => $parts[1], 'n' => (string) $rows[$parts[2]]];
				}

				$result = $this->createMock(originalClassName: IResult::class);
				$result->method('fetchAll')->willReturn($answer);
				return $result;
			}
		);

		$registers = [$this->register(id: 1), $this->register(id: 2)];
		$schemas   = [1 => [$this->schema(id: 10, slug: 'cases'), $this->schema(id: 11, slug: 'documents')], 2 => [$this->schema(id: 20, slug: 'cases')]];
		$mapper    = $this->createMock(originalClassName: RegisterMapper::class);
		$mapper->method('findAll')->willReturn($registers);
		$mapper->method('getSchemasByRegisterId')->willReturnCallback(static fn (int $registerId): array => $schemas[$registerId]);
		$magic = $this->createMock(originalClassName: MagicMapper::class);
		$magic->method('getTableNameForRegisterSchema')->willReturnCallback(
			static fn (Register $register, Schema $schema): string => 'openregister_table_'.$register->getId().'_'.$schema->getId()
		);
		$config = $this->createMock(originalClassName: IConfig::class);
		$config->method('getSystemValue')->willReturn('oc_');

		return new SearchIndexMaintenance(
			stores: new \OCA\OpenRegister\Search\Service\Search\SearchIndexStores($db, new \OCA\OpenRegister\Db\SchemaCatalog(registers: $mapper, schemas: $this->createMock(\OCA\OpenRegister\Db\SchemaMapperInterface::class), tables: $magic)),
			appConfig: $this->createMock(originalClassName: IAppConfig::class),
			config: $config,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end service()

	/**
	 * A register.
	 *
	 * @param int $id Its id.
	 *
	 * @return Register
	 */
	private function register(int $id): Register {
		$register = new Register();
		$register->setId($id);
		return $register;
	}//end register()

	/**
	 * A schema.
	 *
	 * @param int    $id   Its id.
	 * @param string $slug Its slug.
	 *
	 * @return Schema
	 */
	private function schema(int $id, string $slug): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);
		return $schema;
	}//end schema()

	/**
	 * Every table is counted in one query; a slug held by two registers sums both.
	 *
	 * @return void
	 */
	public function testRowsAreCountedInOneQueryAndSummedPerSlug(): void {
		$counts = $this->service(
			rows: ['oc_openregister_table_1_10' => 12000, 'oc_openregister_table_1_11' => 31902, 'oc_openregister_table_2_20' => 418]
		)->rowCounts();

		$this->assertSame(expected: ['rowsInTables' => 44320, 'indexed' => ['cases' => 12418, 'documents' => 31902]], actual: $counts);
		$this->assertCount(expectedCount: 1, haystack: $this->queries);
	}//end testRowsAreCountedInOneQueryAndSummedPerSlug()

	/**
	 * A table that cannot be counted leaves its slug and the total out, and the other slugs stay.
	 *
	 * @return void
	 */
	public function testATableThatCannotBeCountedLeavesItsSlugAndTheTotalOut(): void {
		$counts = $this->service(
			rows: ['oc_openregister_table_1_10' => 12000, 'oc_openregister_table_2_20' => 418],
			missing: ['oc_openregister_table_1_11']
		)->rowCounts();

		$this->assertSame(expected: ['indexed' => ['cases' => 12418]], actual: $counts);
		$this->assertCount(expectedCount: 4, haystack: $this->queries, message: 'the lot, then one query per table');
	}//end testATableThatCannotBeCountedLeavesItsSlugAndTheTotalOut()

	/**
	 * Without tables in scope the total is zero and nothing is asked.
	 *
	 * @return void
	 */
	public function testNoTablesCountsNothing(): void {
		$db = $this->createMock(originalClassName: IDBConnection::class);
		$db->expects($this->never())->method('executeQuery');
		$mapper = $this->createMock(originalClassName: RegisterMapper::class);
		$mapper->method('findAll')->willReturn([]);

		$service = new SearchIndexMaintenance(
			stores: new \OCA\OpenRegister\Search\Service\Search\SearchIndexStores($db, new \OCA\OpenRegister\Db\SchemaCatalog(registers: $mapper, schemas: $this->createMock(\OCA\OpenRegister\Db\SchemaMapperInterface::class), tables: $this->createMock(originalClassName: MagicMapper::class))),
			appConfig: $this->createMock(originalClassName: IAppConfig::class),
			config: $this->createMock(originalClassName: IConfig::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

		$this->assertSame(expected: ['rowsInTables' => 0, 'indexed' => []], actual: $service->rowCounts());
	}//end testNoTablesCountsNothing()
}//end class
