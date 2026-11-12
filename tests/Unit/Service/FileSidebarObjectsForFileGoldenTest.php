<?php

/**
 * FileSidebarObjectsForFileGoldenTest
 *
 * Characterisation test for {@see FileSidebarService::getObjectsForFile()}:
 * which registers, schemas and magic tables are searched, which columns the
 * file-id query compares, the shape and fallbacks of each result, and the
 * log line of a failing table search.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Exception;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Files\Service\FileSidebarEntityStore;
use OCA\OpenRegister\Files\Service\FileSidebarSchemaLookup;
use OCA\OpenRegister\Files\Service\FileSidebarService;
use OCA\OpenRegister\Files\Service\RiskLevelService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins getObjectsForFile() and its magic-table search.
 */
class FileSidebarObjectsForFileGoldenTest extends TestCase {

	/**
	 * Every call the fakes saw, in order.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * Debug lines logged.
	 *
	 * @var list<string>
	 */
	private array $debug = [];

	/**
	 * The register mapper.
	 *
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper&MockObject $registerMapper;

	/**
	 * The schema mapper.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * The service under test.
	 *
	 * @var FileSidebarService
	 */
	private FileSidebarService $service;

	/**
	 * Build the service over fakes that record their calls.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			function (string $message): void {
				$this->debug[] = $message;
			}
		);

		$this->service = new FileSidebarService(
			new FileSidebarSchemaLookup($this->registerMapper, $this->schemaMapper),
			new FileSidebarEntityStore(
				$this->database(),
				$this->createMock(ChunkMapper::class),
				$this->createMock(EntityRelationMapper::class),
				$this->createMock(GdprEntityMapper::class)
			),
			$this->createMock(RiskLevelService::class),
			$logger
		);
	}//end setUp()

	/**
	 * Registers without schemas, missing schemas and missing tables are skipped;
	 * system columns are not searched; rows map with their title fallbacks; a
	 * table without searchable columns is not queried; a failing table is logged.
	 *
	 * @return void
	 */
	public function testObjectsForFile(): void {
		$this->registerMapper->method('findAll')->willReturn(
			[
				$this->register(id: 1, title: null, schemas: []),
				$this->register(id: 2, title: 'Zaken', schemas: [10, '11', 12, 13, 14]),
			]
		);
		$this->schemaMapper->method('find')->willReturnCallback(
			function (int $id): Schema {
				$this->calls[] = 'find ' . $id;
				if ($id === 11) {
					throw new Exception('gone');
				}

				return $this->schema(id: $id, title: ($id === 10) ? 'Dossier' : null);
			}
		);

		$result = $this->service->getObjectsForFile(77);

		$this->assertSame(
			[
				[
					'uuid' => 'u-1',
					'title' => 'Brief',
					'register' => ['id' => 2, 'title' => 'Zaken'],
					'schema' => ['id' => 10, 'title' => 'Dossier'],
				],
				[
					'uuid' => 5,
					'title' => 'Object 5',
					'register' => ['id' => 2, 'title' => 'Zaken'],
					'schema' => ['id' => 10, 'title' => 'Dossier'],
				],
				[
					'uuid' => '',
					'title' => 'Object unknown',
					'register' => ['id' => 2, 'title' => 'Zaken'],
					'schema' => ['id' => 12, 'title' => 'Schema 12'],
				],
			],
			$result
		);
		$this->assertSame(
			[
				'find 10',
				'exists openregister_table_2_10',
				'columns openregister_table_2_10',
				'select *',
				'from openregister_table_2_10',
				'eq bijlage :p77',
				'eq extra :p77',
				'or(bijlage=:p77|extra=:p77)',
				'where',
				'execute',
				'close',
				'find 11',
				'find 12',
				'exists openregister_table_2_12',
				'columns openregister_table_2_12',
				'select *',
				'from openregister_table_2_12',
				'eq bijlage :p77',
				'eq extra :p77',
				'or(bijlage=:p77|extra=:p77)',
				'where',
				'execute',
				'close',
				'find 13',
				'exists openregister_table_2_13',
				'columns openregister_table_2_13',
				'find 14',
				'exists openregister_table_2_14',
				'columns openregister_table_2_14',
			],
			$this->calls
		);
		$this->assertSame(['[FileSidebarService] Error searching table openregister_table_2_14: no such table'], $this->debug);
	}//end testObjectsForFile()

	/**
	 * A database fake: tables 2_10, 2_12, 2_13 and 2_14 exist; 2_13 holds only
	 * system columns; listing 2_14's columns fails.
	 *
	 * @return IDBConnection
	 */
	private function database(): IDBConnection {
		$db = $this->getMockBuilder(IDBConnection::class)->addMethods(['getInner'])->getMockForAbstractClass();
		$db->method('tableExists')->willReturnCallback(
			function (string $table): bool {
				$this->calls[] = 'exists ' . $table;
				return in_array($table, ['openregister_table_2_10', 'openregister_table_2_12', 'openregister_table_2_13', 'openregister_table_2_14'], true);
			}
		);
		$schemaManager = $this->createMock(AbstractSchemaManager::class);
		$schemaManager->method('listTableColumns')->willReturnCallback(
			function (string $table): array {
				$this->calls[] = 'columns ' . $table;
				if ($table === 'openregister_table_2_14') {
					throw new Exception('no such table');
				}

				$names = ['id', 'uuid', 'register', 'schema', 'object', 'created', 'updated', 'owner', 'organisation', 'authorization', 'version', 'status', 'folder', 'textContent'];
				if ($table !== 'openregister_table_2_13') {
					$names = array_merge(['bijlage'], $names, ['extra']);
				}

				return array_map(static fn (string $name): Column => new Column($name, Type::getType(Types::STRING)), $names);
			}
		);
		$inner = new class($schemaManager) {
			/**
			 * Constructor.
			 *
			 * @param AbstractSchemaManager $manager The schema manager.
			 */
			public function __construct(private readonly AbstractSchemaManager $manager) {
			}

			/**
			 * The schema manager.
			 *
			 * @return AbstractSchemaManager
			 */
			public function createSchemaManager(): AbstractSchemaManager {
				return $this->manager;
			}
		};
		$db->method('getInner')->willReturn($inner);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->queryBuilder());
		return $db;
	}//end database()

	/**
	 * A query builder fake that records the query and returns rows per table.
	 *
	 * @return IQueryBuilder
	 */
	private function queryBuilder(): IQueryBuilder {
		$table = '';
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(
			function (string $column, string $param): string {
				$this->calls[] = 'eq ' . $column . ' ' . $param;
				return $column . '=' . $param;
			}
		);
		$expr->method('orX')->willReturnCallback(
			function (string ...$parts): ICompositeExpression {
				$this->calls[] = 'or(' . implode('|', $parts) . ')';
				return $this->createMock(ICompositeExpression::class);
			}
		);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(static fn (string $value): string => ':p' . $value);
		$qb->method('select')->willReturnCallback(
			function (string $what) use ($qb): IQueryBuilder {
				$this->calls[] = 'select ' . $what;
				return $qb;
			}
		);
		$qb->method('from')->willReturnCallback(
			function (string $from) use ($qb, &$table): IQueryBuilder {
				$this->calls[] = 'from ' . $from;
				$table = $from;
				return $qb;
			}
		);
		$qb->method('where')->willReturnCallback(
			function (ICompositeExpression $where) use ($qb): IQueryBuilder {
				$this->calls[] = 'where';
				return $qb;
			}
		);
		$qb->method('executeQuery')->willReturnCallback(
			function () use (&$table): IResult {
				$this->calls[] = 'execute';
				$rows = [['id' => 9]];
				if ($table === 'openregister_table_2_10') {
					$rows = [['uuid' => 'u-1', 'title' => '', 'name' => 'Brief'], ['id' => 5, 'title' => 7]];
				}

				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn($rows === [['id' => 9]] ? [['uuid' => '']] : $rows);
				$result->method('closeCursor')->willReturnCallback(
					function (): bool {
						$this->calls[] = 'close';
						return true;
					}
				);
				return $result;
			}
		);
		return $qb;
	}//end queryBuilder()

	/**
	 * A register fake.
	 *
	 * @param int         $id      The id.
	 * @param string|null $title   The title.
	 * @param array       $schemas The schema ids.
	 *
	 * @return Register
	 */
	private function register(int $id, ?string $title, array $schemas): Register {
		$register = $this->getMockBuilder(Register::class)->addMethods(['getTitle', 'getId'])->onlyMethods(['getSchemas'])->getMock();
		$register->method('getId')->willReturn($id);
		$register->method('getTitle')->willReturn($title);
		$register->method('getSchemas')->willReturn($schemas);
		return $register;
	}//end register()

	/**
	 * A schema fake.
	 *
	 * @param int         $id    The id.
	 * @param string|null $title The title.
	 *
	 * @return Schema
	 */
	private function schema(int $id, ?string $title): Schema {
		$schema = $this->getMockBuilder(Schema::class)->addMethods(['getTitle', 'getId'])->getMock();
		$schema->method('getId')->willReturn($id);
		$schema->method('getTitle')->willReturn($title);
		return $schema;
	}//end schema()
}//end class
