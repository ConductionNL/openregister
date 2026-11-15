<?php

/**
 * `_childCount=true` puts the number of readable children on each object, in one grouped query.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/records-tree-view/specs/saved-search-views/spec.md#requirement-the-tree-loads-one-level-at-a-time-with-child-counts
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicReadTable;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\MagicMapper\TableSearch;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCA\OpenRegister\Tests\Support\MigratedSqliteDatabase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\RegisterSchema
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicReadTable
 * @uses   \OCA\OpenRegister\Db\MagicMapper\TableSearch
 * @uses   \OCA\OpenRegister\Service\Rbac\AccessDeclarationValidators
 * @uses   \OCA\OpenRegister\Service\Rbac\HierarchyGrantExpander
 * @uses   \OCA\OpenRegister\Tests\Support\MigratedSqliteDatabase
 */
final class ChildCountTest extends TestCase {

	/**
	 * Departments: uuid => [parent, status]. The caller reads only `open` rows.
	 */
	private const ROWS = [
		'root-a' => [null, 'open'],
		'root-b' => [null, 'open'],
		'a-1' => ['root-a', 'open'],
		'a-2' => ['root-a', 'open'],
		'a-3' => ['root-a', 'closed'],
		'b-1' => ['root-b', 'closed'],
		'a-1-x' => ['a-1', 'open'],
	];

	/**
	 * The queries buildFilteredQuery() was asked for.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $asked = [];

	/**
	 * The bridged SQLite connection.
	 *
	 * @var IDBConnection|null
	 */
	private ?IDBConnection $bridged = null;

	/**
	 * A department schema; with a hierarchy unless told otherwise.
	 *
	 * @param bool $hierarchy Whether it declares x-openregister-hierarchy.
	 *
	 * @return Schema
	 */
	private static function schema(bool $hierarchy=true): Schema {
		$schema = new Schema();
		$schema->setId(5);
		$schema->setSlug('department');
		$schema->setProperties(['parentDepartment' => ['type' => 'string'], 'status' => ['type' => 'string']]);
		if ($hierarchy === true) {
			$schema->setConfiguration(['x-openregister-hierarchy' => ['parent' => 'parentDepartment']]);
		}

		return $schema;
	}

	/**
	 * A logger that keeps the warnings.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->warnings[] = ($context['exception'] ?? $message);
			}
		);
		return $logger;
	}

	/**
	 * The warnings the handler logged.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * The handler: real SQL on SQLite, the access path stood in by `status = 'open'`.
	 *
	 * @return MagicSearchHandler
	 */
	private function handler(): MagicSearchHandler {
		$database = (new ReflectionClass(MigratedSqliteDatabase::class))->newInstanceWithoutConstructor();
		$test = $this;
		\Closure::bind(
			function () use ($test): void {
				$this->test = $test;
				$this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
			},
			$database,
			MigratedSqliteDatabase::class
		)();
		$connection = $database->connection();
		$connection->executeStatement('CREATE TABLE t (_uuid TEXT, parent_department TEXT, status TEXT)');
		foreach (self::ROWS as $uuid => [$parent, $status]) {
			$connection->insert('t', ['_uuid' => $uuid, 'parent_department' => $parent, 'status' => $status]);
		}

		$this->bridged = $database->idbConnection();
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(new SqlitePlatform());

		$handler = $this->getMockBuilder(MagicSearchHandler::class)
			->setConstructorArgs(
				[
					$db,
					$this->logger(),
					$this->createMock(MagicRbacHandler::class),
					$this->createMock(MagicOrganizationHandler::class),
					$this->createMock(SchemaTypeConverter::class),
					$this->createMock(DateTimeNormalizer::class),
					$this->createMock(RelatedRowQueryApplier::class),
				]
			)
			->onlyMethods(['buildFilteredQuery'])
			->getMock();
		$handler->method('buildFilteredQuery')->willReturnCallback(
			function (array $query, MagicReadTable $table): IQueryBuilder {
				$this->asked[] = $query;
				$qb = $this->bridged->getQueryBuilder();
				$qb->from($table->tableName, 't')->where($qb->expr()->eq('t.status', $qb->createNamedParameter('open')));
				return $qb;
			}
		);

		return $handler;
	}

	/**
	 * Run the page step on objects with these uuids.
	 *
	 * @param MagicSearchHandler   $handler The handler.
	 * @param array<int, string>   $uuids   The page.
	 * @param array<string, mixed> $query   The page's query.
	 * @param Schema               $schema  The schema.
	 *
	 * @return array<string, int|null> uuid => @self.childCount, null when absent.
	 */
	private static function counted(MagicSearchHandler $handler, array $uuids, array $query, Schema $schema): array {
		$objects = array_map(
			static function (string $uuid): ObjectEntity {
				$object = new ObjectEntity();
				$object->setUuid($uuid);
				return $object;
			},
			$uuids
		);
		$register = new Register();
		$register->setId(2);
		$step = new ReflectionMethod(MagicSearchHandler::class, 'applyChildCounts');
		$step->invoke($handler, $objects, $query, new TableSearch(table: new RegisterSchema(register: $register, schema: $schema), tableName: 't'));

		$counts = [];
		foreach ($objects as $object) {
			$counts[$object->getUuid()] = ($object->jsonSerialize()['@self']['childCount'] ?? null);
		}

		return $counts;
	}

	public function testEachObjectCountsOnlyTheChildrenTheCallerMayList(): void {
		$handler = $this->handler();
		$counts = self::counted($handler, ['root-a', 'root-b', 'a-1', 'a-2'], ['_childCount' => 'true', '_rbac' => true, 'status' => 'open', '_limit' => 50], self::schema());
		$this->assertSame([], $this->warnings);
		$this->assertSame(
			['root-a' => 2, 'root-b' => 0, 'a-1' => 1, 'a-2' => 0],
			$counts
		);
	}

	public function testThePageIsCountedInOneQueryOnTheListsAccessPath(): void {
		$handler = $this->handler();
		self::counted($handler, array_map(static fn (int $i): string => 'n-' . $i, range(1, 50)), ['_childCount' => true, '_rbac' => false, '_multitenancy' => true, 'parentDepartment' => 'x', '_search' => 'y'], self::schema());
		$this->assertSame([['_rbac' => false, '_multitenancy' => true]], $this->asked, 'one query, with the access flags and none of the page filters');
	}

	public function testNothingIsCountedUnlessAskedOrWithoutAHierarchy(): void {
		$handler = $this->handler();
		$this->assertSame(['root-a' => null], self::counted($handler, ['root-a'], [], self::schema()));
		$this->assertSame(['root-a' => null], self::counted($handler, ['root-a'], ['_childCount' => 'false'], self::schema()));
		$this->assertSame(['root-a' => null], self::counted($handler, ['root-a'], ['_childCount' => true], self::schema(false)));
		$this->assertSame([], $this->asked);
	}

	public function testAFailedCountLeavesThePageWithoutCounts(): void {
		$handler = $this->handler();
		$schema = self::schema();
		$schema->setConfiguration(['x-openregister-hierarchy' => ['parent' => 'noSuchColumn']]);
		$schema->setProperties(['noSuchColumn' => ['type' => 'string']]);
		$this->assertSame(['root-a' => null], self::counted($handler, ['root-a'], ['_childCount' => true], $schema));
	}
}
