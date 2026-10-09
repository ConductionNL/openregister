<?php

/**
 * OpenRegister - the total of a cross-table search.
 *
 * `crossTableSearch()` reported the size of the returned page as `total`,
 * so every pager showed one page. The mapper now counts the matches on the
 * search's own path: one statement for the UNION path, the single-table
 * count summed for the sequential path.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/objects-crud/spec.md#requirement-limit-supports-an-explicit-unlimited-value
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\SettingsService;
use OCP\DB\IPreparedStatement;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The total counts every match across the tables, on both paths.
 */
class MagicMapperCrossTableCountTest extends TestCase {

	/**
	 * The SQL statements prepared.
	 *
	 * @var array<int, string>
	 */
	private array $prepared = [];

	/**
	 * A mapper whose database answers one count, with table lookups stubbed.
	 *
	 * @param string $total The SUM the database answers.
	 *
	 * @return MagicMapper&MockObject The mapper.
	 */
	private function mapper(string $total = '0'): MagicMapper {
		$statement = $this->createMock(IPreparedStatement::class);
		$statement->method('fetch')->willReturn(['total' => $total]);

		$connection = $this->createMock(IDBConnection::class);
		$connection->method('prepare')->willReturnCallback(
			function (string $sql) use ($statement): IPreparedStatement {
				$this->prepared[] = $sql;
				return $statement;
			}
		);
		$queryBuilder = $this->createMock(IQueryBuilder::class);
		$queryBuilder->method('getConnection')->willReturn($connection);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($queryBuilder);

		$container = $this->createMock(ContainerInterface::class);
		$services = [
			DateTimeNormalizer::class => $this->createMock(DateTimeNormalizer::class),
			ConditionMatcher::class => $this->createMock(ConditionMatcher::class),
			SchemaTypeConverter::class => $this->createMock(SchemaTypeConverter::class),
		];
		$container->method('get')->willReturnCallback(static fn (string $id) => ($services[$id] ?? null));

		$mapper = $this->getMockBuilder(MagicMapper::class)
			->setConstructorArgs(
				[
					$db,
					$this->createMock(SchemaMapper::class),
					$this->createMock(RegisterMapper::class),
					$this->createMock(IConfig::class),
					$this->createMock(IEventDispatcher::class),
					$this->createMock(IUserSession::class),
					$this->createMock(IGroupManager::class),
					$this->createMock(IUserManager::class),
					$this->createMock(IAppConfig::class),
					$this->createMock(LoggerInterface::class),
					$this->createMock(SettingsService::class),
					$container,
				]
			)
			->onlyMethods(['countObjectsInRegisterSchemaTable', 'existsTableForRegisterSchema', 'getTableNameForRegisterSchema'])
			->getMock();

		$mapper->method('existsTableForRegisterSchema')->willReturn(true);
		$mapper->method('getTableNameForRegisterSchema')->willReturnCallback(
			static fn (Register $register, Schema $schema): string => 'openregister_table_'.$register->getId().'_'.$schema->getId()
		);
		$mapper->method('countObjectsInRegisterSchemaTable')->willReturnCallback(
			static fn (array $query, Register $register, Schema $schema): int => ['zaak' => 3, 'taak' => 4][$schema->getSlug()]
		);

		$search = $this->createMock(MagicSearchHandler::class);
		$search->method('buildWhereConditionsSql')->willReturn(['_deleted IS NULL', "status = 'open'"]);
		$property = new \ReflectionProperty(MagicMapper::class, 'searchHandler');
		$property->setValue($mapper, $search);

		return $mapper;
	}//end mapper()

	/**
	 * Two register+schema pairs.
	 *
	 * @return array<int, array{register: Register, schema: Schema}> The pairs.
	 */
	private function pairs(): array {
		$pairs = [];
		foreach (['zaak' => 1, 'taak' => 2] as $slug => $id) {
			$schema = new Schema();
			$schema->setId($id);
			$schema->setSlug($slug);
			$register = new Register();
			$register->setId(7);
			$pairs[] = ['register' => $register, 'schema' => $schema];
		}

		return $pairs;
	}//end pairs()

	/**
	 * The UNION path counts every table in one statement with the search's WHERE.
	 *
	 * @return void
	 */
	public function testTheUnionPathCountsInOneStatement(): void {
		$total = $this->mapper(total: '7')->countAcrossMultipleTables(
			query: ['_limit' => 2, '_offset' => 2, 'status' => 'open'],
			registerSchemaPairs: $this->pairs()
		);

		$this->assertSame(7, $total);
		$this->assertCount(1, $this->prepared, 'one statement, not one per table');
		$this->assertSame(2, substr_count($this->prepared[0], 'SELECT COUNT(*) AS cnt FROM'));
		$this->assertStringContainsString("WHERE _deleted IS NULL AND status = 'open'", $this->prepared[0]);
		$this->assertStringNotContainsString('LIMIT', $this->prepared[0], 'the total ignores the page');
	}//end testTheUnionPathCountsInOneStatement()

	/**
	 * The sequential path sums the single-table counts it pages with.
	 *
	 * @return void
	 */
	public function testTheSequentialPathSumsTheTableCounts(): void {
		$total = $this->mapper()->countAcrossMultipleTables(
			query: ['_aggregations' => [], '_limit' => 2, '_offset' => 2],
			registerSchemaPairs: $this->pairs()
		);

		$this->assertSame(7, $total);
		$this->assertSame([], $this->prepared);
	}//end testTheSequentialPathSumsTheTableCounts()
}//end class
