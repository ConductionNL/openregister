<?php

/**
 * `_roots=true` returns the top level of a hierarchy: the records with no parent, and the
 * readable records whose parent the caller may not read, marked `@self.parentHidden`.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/records-tree-view/specs/saved-search-views/spec.md#requirement-a-record-under-an-unreadable-parent-stays-visible
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

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
use RuntimeException;

final class RootScopeTest extends TestCase {

	/**
	 * uuid => [parent, status]. The access predicate the mocked list path applies is
	 * `status = 'open'`, so a `closed` row is one the caller may not read.
	 */
	private const ROWS = [
		'root-a'  => [null, 'open'],
		'root-b'  => [null, 'closed'],
		'a-1'     => ['root-a', 'open'],
		'b-1'     => ['root-b', 'open'],
		'b-2'     => ['root-b', 'closed'],
		'ghost-1' => ['gone', 'open'],
		'a-1-x'   => ['a-1', 'open'],
	];

	/** @var array<int, array<string, mixed>> The queries the list path was asked for. */
	private array $asked = [];

	/** @var array<int, string> */
	private array $warnings = [];

	private ?IDBConnection $bridged = null;

	private ?\Doctrine\DBAL\Connection $raw = null;

	private bool $listPathFails = false;

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

	private function handler(): MagicSearchHandler {
		$database = (new ReflectionClass(MigratedSqliteDatabase::class))->newInstanceWithoutConstructor();
		$test     = $this;
		\Closure::bind(
			function () use ($test): void {
				$this->test       = $test;
				$this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
			},
			$database,
			MigratedSqliteDatabase::class
		)();
		$connection = $database->connection();
		$this->raw  = $connection;
		$connection->executeStatement('CREATE TABLE t (_uuid TEXT, parent_department TEXT, status TEXT)');
		foreach (self::ROWS as $uuid => [$parent, $status]) {
			$connection->insert('t', ['_uuid' => $uuid, 'parent_department' => $parent, 'status' => $status]);
		}

		$this->bridged = $database->idbConnection();
		$db            = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(new SqlitePlatform());

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);

		$handler = $this->getMockBuilder(MagicSearchHandler::class)
			->setConstructorArgs(
				[
					$db,
					$logger,
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
				if ($this->listPathFails === true) {
					throw new RuntimeException('list path down');
				}

				return $this->listQuery(table: $table->tableName);
			}
		);

		return $handler;
	}

	private function listQuery(string $table): IQueryBuilder {
		$qb = $this->bridged->getQueryBuilder();
		$qb->from($table, 't')->where($qb->expr()->eq('t.status', $qb->createNamedParameter('open')));
		return $qb;
	}

	private static function search(Schema $schema): TableSearch {
		$register = new Register();
		$register->setId(2);
		return new TableSearch(table: new RegisterSchema(register: $register, schema: $schema), tableName: 't');
	}

	/**
	 * Apply the root scope to a page query on the list path and return [hidden parents, page uuids].
	 */
	private function rootPage(MagicSearchHandler $handler, array $query, Schema $schema): array {
		$page   = $this->listQuery(table: 't');
		$scope  = new ReflectionMethod(MagicSearchHandler::class, 'applyRootScope');
		$hidden = $scope->invoke($handler, $page, $query, self::search(schema: $schema));
		$page->select('t._uuid')->orderBy('t._uuid');
		$uuids = array_map(static fn (array $row): string => (string) $row['_uuid'], $page->executeQuery()->fetchAll());

		return [$hidden, $uuids];
	}

	public function testTheRootPageHoldsTheRootsAndTheRecordsUnderAHiddenParent(): void {
		[$hidden, $uuids] = $this->rootPage($this->handler(), ['_roots' => 'true'], self::schema());

		// root-b is closed and "gone" does not exist: both are parents the caller cannot read.
		sort($hidden);
		$this->assertSame(['gone', 'root-b'], $hidden);
		// a-1 and a-1-x sit under readable parents, so they are not top level.
		$this->assertSame(['b-1', 'ghost-1', 'root-a'], $uuids);
	}

	public function testHiddenParentsAreReadOnTheListsAccessPathWithOnlyTheAccessContext(): void {
		$this->rootPage(
			$this->handler(),
			['_roots' => true, '_rbac' => false, 'status' => 'open', '_search' => 'x', '_deleted' => true],
			self::schema()
		);

		$this->assertCount(2, $this->asked);
		foreach ($this->asked as $query) {
			$this->assertSame(['_rbac' => false, '_deleted' => true], $query);
		}
	}

	public function testWithoutHiddenParentsOnlyTheRootsRemain(): void {
		$handler = $this->handler();
		$this->raw->executeStatement("DELETE FROM t WHERE _uuid IN ('b-1', 'ghost-1')");

		[$hidden, $uuids] = $this->rootPage($handler, ['_roots' => '1'], self::schema());

		$this->assertSame([], $hidden);
		$this->assertSame(['root-a'], $uuids);
		$this->assertCount(2, $this->asked);
	}

	public function testNothingChangesUnlessAskedOrWithoutAHierarchy(): void {
		$handler = $this->handler();
		$all     = ['a-1', 'a-1-x', 'b-1', 'ghost-1', 'root-a'];

		$this->assertSame([null, $all], $this->rootPage($handler, [], self::schema()));
		$this->assertSame([null, $all], $this->rootPage($handler, ['_roots' => 'false'], self::schema()));
		$this->assertSame([null, $all], $this->rootPage($handler, ['_roots' => true], self::schema(hierarchy: false)));
		$this->assertSame([], $this->asked);
	}

	public function testAFailedLookupLeavesOnlyTheRecordsWithoutAParent(): void {
		$handler             = $this->handler();
		$this->listPathFails = true;

		[$hidden, $uuids] = $this->rootPage($handler, ['_roots' => true], self::schema());

		$this->assertSame([], $hidden);
		$this->assertSame(['root-a'], $uuids);
		$this->assertSame(['[MagicSearchHandler] Hidden parents could not be read; the root page holds only records without a parent'], $this->warnings);
	}

	public function testRecordsUnderAHiddenParentAreMarkedAndNoOthers(): void {
		$objects = [];
		foreach (['b-1' => 'root-b', 'ghost-1' => 'gone', 'root-a' => null, 'linked' => ['value' => 'root-b']] as $uuid => $parent) {
			$object = new ObjectEntity();
			$object->setUuid($uuid);
			$object->setObject(['parentDepartment' => $parent]);
			$objects[$uuid] = $object;
		}

		$mark = new ReflectionMethod(MagicSearchHandler::class, 'markHiddenParents');
		$mark->invoke($this->handler(), array_values($objects), ['root-b', 'gone'], self::search(schema: self::schema()));

		$flags = array_map(static fn (ObjectEntity $o): ?bool => ($o->jsonSerialize()['@self']['parentHidden'] ?? null), $objects);
		$this->assertSame(['b-1' => true, 'ghost-1' => true, 'root-a' => null, 'linked' => true], $flags);
	}

	public function testNoMarkIsSetOutsideARootRead(): void {
		$object = new ObjectEntity();
		$object->setUuid('b-1');
		$object->setObject(['parentDepartment' => 'root-b']);

		$mark = new ReflectionMethod(MagicSearchHandler::class, 'markHiddenParents');
		$mark->invoke($this->handler(), [$object], null, self::search(schema: self::schema()));

		$this->assertArrayNotHasKey('parentHidden', $object->jsonSerialize()['@self']);
	}
}
