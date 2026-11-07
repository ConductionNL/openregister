<?php

/**
 * The `ids` argument of the mapper's search entry points narrows the search.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Service\Audit\PurposeGuard;
use OCA\OpenRegister\Service\Audit\PurposeRefusedException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * An id list handed to searchObjects(), countSearchObjects() or
 * searchObjectsPaginated() reaches the table search as `_ids`, and a `uses`
 * value as `_relations_contains`.
 */
class MagicMapperSearchIdsTest extends TestCase {

	/**
	 * The query of a register and schema.
	 *
	 * @var array<string, array<string, int>>
	 */
	private const SCOPE = ['@self' => ['register' => 7, 'schema' => 9]];

	/**
	 * The id of a schema that no longer exists.
	 *
	 * @var int
	 */
	private const GONE_SCHEMA = 404;

	/**
	 * The queries the table search and the table count received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $tableQueries = [];

	/**
	 * The rows the table search answers, or null for one row of no table.
	 *
	 * @var array<int, ObjectEntity>|null
	 */
	private ?array $tableRows = null;

	/**
	 * The doelbinding guard the mapper is given, a permissive mock when null.
	 *
	 * @var PurposeGuard|null
	 */
	private ?PurposeGuard $purposeGuard = null;

	/**
	 * A mapper whose table search and count record their query.
	 *
	 * @return MagicMapper The mapper.
	 */
	private function mapper(): MagicMapper {
		$container = $this->createMock(ContainerInterface::class);
		$services = [
			DateTimeNormalizer::class => $this->createMock(DateTimeNormalizer::class),
			ConditionMatcher::class => $this->createMock(ConditionMatcher::class),
			SchemaTypeConverter::class => $this->createMock(SchemaTypeConverter::class),
			PurposeGuard::class => ($this->purposeGuard ?? $this->createMock(PurposeGuard::class)),
		];
		$container->method('get')->willReturnCallback(static fn (string $id) => ($services[$id] ?? null));

		$register = new Register();
		$register->setId(7);
		$schema = new Schema();
		$schema->setId(9);
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturn($register);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(
			static function (int $id) use ($schema): Schema {
				if ($id === self::GONE_SCHEMA) {
					throw new DoesNotExistException('The schema is gone.');
				}

				return $schema;
			}
		);

		$mapper = $this->getMockBuilder(MagicMapper::class)
			->setConstructorArgs(
				[
					$this->createMock(IDBConnection::class),
					$schemas,
					$registers,
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
			->onlyMethods(['searchObjectsInRegisterSchemaTable', 'countObjectsInRegisterSchemaTable', 'findMultipleAcrossAllMagicTables', 'findByRelationAcrossAllMagicTables'])
			->getMock();

		$mapper->method('searchObjectsInRegisterSchemaTable')->willReturnCallback(
			function (array $query): array {
				$this->tableQueries[] = $query;
				return ($this->tableRows ?? [new ObjectEntity()]);
			}
		);
		$mapper->method('countObjectsInRegisterSchemaTable')->willReturnCallback(
			function (array $query): int {
				$this->tableQueries[] = $query;
				return 1;
			}
		);

		return $mapper;
	}//end mapper()

	/**
	 * The `_ids` every table query carried.
	 *
	 * @return array<int, mixed> One entry per table query.
	 */
	private function tableIds(): array {
		return array_map(static fn (array $query): mixed => ($query['_ids'] ?? 'none'), $this->tableQueries);
	}//end tableIds()

	/**
	 * A search keeps to the ids it is given.
	 *
	 * @return void
	 */
	public function testASearchKeepsToItsIds(): void {
		$this->mapper()->searchObjects(query: self::SCOPE, ids: ['a', 'b']);

		$this->assertSame([['a', 'b']], $this->tableIds());
	}//end testASearchKeepsToItsIds()

	/**
	 * A count keeps to the ids it is given.
	 *
	 * @return void
	 */
	public function testACountKeepsToItsIds(): void {
		$this->mapper()->countSearchObjects(query: self::SCOPE, ids: ['a']);

		$this->assertSame([['a']], $this->tableIds());
	}//end testACountKeepsToItsIds()

	/**
	 * A paginated search keeps its page and its total to the ids it is given.
	 *
	 * @return void
	 */
	public function testAPaginatedSearchKeepsPageAndTotalToItsIds(): void {
		$this->mapper()->searchObjectsPaginated(searchQuery: self::SCOPE, countQuery: self::SCOPE, ids: ['a']);

		$this->assertSame([['a'], ['a']], $this->tableIds());
	}//end testAPaginatedSearchKeepsPageAndTotalToItsIds()

	/**
	 * Ids narrow an `_ids` the query already carries; they never widen it.
	 *
	 * @return void
	 */
	public function testIdsNarrowTheQuerysOwnIds(): void {
		$this->mapper()->searchObjects(query: (self::SCOPE + ['_ids' => ['a', 'b']]), ids: ['b', 'c']);

		$this->assertSame([['b']], $this->tableIds());
	}//end testIdsNarrowTheQuerysOwnIds()

	/**
	 * An empty list selects nothing and no table is read.
	 *
	 * @return void
	 */
	public function testAnEmptyListSelectsNothing(): void {
		$mapper = $this->mapper();

		$this->assertSame([], $mapper->searchObjects(query: self::SCOPE, ids: []));
		$this->assertSame(0, $mapper->countSearchObjects(query: self::SCOPE, ids: []));
		$this->assertSame(0, $mapper->searchObjectsPaginated(searchQuery: self::SCOPE, ids: [])['total']);
		$this->assertSame([], $this->tableQueries);
	}//end testAnEmptyListSelectsNothing()

	/**
	 * Ids that share nothing with the query's own `_ids` select nothing.
	 *
	 * @return void
	 */
	public function testDisjointIdsSelectNothing(): void {
		$this->assertSame([], $this->mapper()->searchObjects(query: (self::SCOPE + ['_ids' => ['a']]), ids: ['b']));
		$this->assertSame([], $this->tableQueries);
	}//end testDisjointIdsSelectNothing()

	/**
	 * An empty `_ids` in the query, with no ids given, is not an id list.
	 *
	 * @return void
	 */
	public function testAnEmptyIdsKeyInTheQueryStaysAsItWas(): void {
		$this->mapper()->searchObjects(query: (self::SCOPE + ['_ids' => []]));

		$this->assertSame([[]], $this->tableIds());
	}//end testAnEmptyIdsKeyInTheQueryStaysAsItWas()

	/**
	 * The `_relations_contains` every table query carried.
	 *
	 * @return array<int, mixed> One entry per table query.
	 */
	private function tableUses(): array {
		return array_map(static fn (array $query): mixed => ($query['_relations_contains'] ?? 'none'), $this->tableQueries);
	}//end tableUses()

	/**
	 * A search keeps to the objects that use the given object.
	 *
	 * @return void
	 */
	public function testASearchKeepsToWhatUsesTheObject(): void {
		$this->mapper()->searchObjects(query: self::SCOPE, uses: 'u');

		$this->assertSame(['u'], $this->tableUses());
	}//end testASearchKeepsToWhatUsesTheObject()

	/**
	 * A count keeps to the objects that use the given object.
	 *
	 * @return void
	 */
	public function testACountKeepsToWhatUsesTheObject(): void {
		$this->mapper()->countSearchObjects(query: self::SCOPE, uses: 'u');

		$this->assertSame(['u'], $this->tableUses());
	}//end testACountKeepsToWhatUsesTheObject()

	/**
	 * A paginated search keeps its page and its total to what uses the object.
	 *
	 * @return void
	 */
	public function testAPaginatedSearchKeepsPageAndTotalToWhatUsesTheObject(): void {
		$this->mapper()->searchObjectsPaginated(searchQuery: self::SCOPE, countQuery: self::SCOPE, uses: 'u');

		$this->assertSame(['u', 'u'], $this->tableUses());
	}//end testAPaginatedSearchKeepsPageAndTotalToWhatUsesTheObject()

	/**
	 * A findAll keeps to the objects that use the given object.
	 *
	 * @return void
	 */
	public function testAFindAllKeepsToWhatUsesTheObject(): void {
		$this->mapper()->findAll(uses: 'u', register: new Register(), schema: new Schema());

		$this->assertSame(['u'], $this->tableUses());
	}//end testAFindAllKeepsToWhatUsesTheObject()

	/**
	 * A relation the query asks for stays: the objects have to use both.
	 *
	 * @return void
	 */
	public function testUsesAddsToTheQuerysOwnRelation(): void {
		$this->mapper()->searchObjects(query: (self::SCOPE + ['_relations_contains' => 'a']), uses: 'u');

		$this->assertSame([['a', 'u']], $this->tableUses());
	}//end testUsesAddsToTheQuerysOwnRelation()

	/**
	 * The same relation twice is one relation.
	 *
	 * @return void
	 */
	public function testUsesEqualToTheQuerysOwnRelationStaysOne(): void {
		$this->mapper()->searchObjects(query: (self::SCOPE + ['_relations_contains' => 'u']), uses: 'u');

		$this->assertSame(['u'], $this->tableUses());
	}//end testUsesEqualToTheQuerysOwnRelationStaysOne()

	/**
	 * An empty `uses` names no object, as an empty relation filter does.
	 *
	 * @return void
	 */
	public function testAnEmptyUsesNarrowsNothing(): void {
		$this->mapper()->searchObjects(query: self::SCOPE, uses: '');

		$this->assertSame(['none'], $this->tableUses());
	}//end testAnEmptyUsesNarrowsNothing()

	/**
	 * Without a register and schema a given id list reaches no table.
	 *
	 * @return void
	 */
	public function testGivenIdsWithoutARegisterAndSchemaSelectNothing(): void {
		$mapper = $this->mapper();
		$mapper->expects($this->never())->method('findMultipleAcrossAllMagicTables');

		$page = $mapper->searchObjectsPaginated(searchQuery: [], countQuery: [], ids: ['a']);

		$this->assertSame(['results' => [], 'total' => 0, 'registers' => [], 'schemas' => []], $page);
	}//end testGivenIdsWithoutARegisterAndSchemaSelectNothing()

	/**
	 * Without a register and schema a given `uses` reaches no table.
	 *
	 * @return void
	 */
	public function testGivenUsesWithoutARegisterAndSchemaSelectsNothing(): void {
		$mapper = $this->mapper();
		$mapper->expects($this->never())->method('findByRelationAcrossAllMagicTables');

		$page = $mapper->searchObjectsPaginated(searchQuery: [], countQuery: [], uses: 'u');

		$this->assertSame(['results' => [], 'total' => 0, 'registers' => [], 'schemas' => []], $page);
	}//end testGivenUsesWithoutARegisterAndSchemaSelectsNothing()

	/**
	 * A row that a cross-table scan found.
	 *
	 * @return ObjectEntity The row, in register 7 and schema 9.
	 */
	private function locatedRow(): ObjectEntity {
		$row = new ObjectEntity();
		$row->setUuid('located');
		$row->setRegister('7');
		$row->setSchema('9');

		return $row;
	}//end locatedRow()

	/**
	 * The `_rbac` and `_multitenancy` every table query carried.
	 *
	 * @return array<int, array<int, mixed>> One pair per table query.
	 */
	private function tableFlags(): array {
		return array_map(
			static fn (array $query): array => [($query['_rbac'] ?? 'none'), ($query['_multitenancy'] ?? 'none')],
			$this->tableQueries
		);
	}//end tableFlags()

	/**
	 * Client `_ids` without a table are answered by the table, not by the scan.
	 *
	 * @return void
	 */
	public function testQueryIdsWithoutATableAreAnsweredByTheTable(): void {
		$located = $this->locatedRow();
		$mapper  = $this->mapper();
		$mapper->method('findMultipleAcrossAllMagicTables')->willReturn([$located]);

		$query  = ['_ids' => ['located']];
		$result = $mapper->searchObjectsPaginated(searchQuery: $query, countQuery: $query);

		$this->assertNotContains($located, $result['results']);
		$this->assertCount(1, $result['results']);
		$this->assertSame(1, $result['total']);
		$this->assertSame([[true, true], [true, true]], $this->tableFlags());
	}//end testQueryIdsWithoutATableAreAnsweredByTheTable()

	/**
	 * A relation search without a table is answered by the table, not by the scan.
	 *
	 * @return void
	 */
	public function testQueryIdsOnATableThatNeedsAPurposeAreRefused(): void {
		$this->purposeGuard = $this->createMock(PurposeGuard::class);
		$this->purposeGuard->expects($this->once())->method('enforce')
			->with($this->callback(static fn ($register): bool => $register->getId() === 7), $this->callback(static fn ($schema): bool => $schema->getId() === 9))
			->willThrowException(new PurposeRefusedException(rule: PurposeRefusedException::RULE_MISSING, reason: 'no purpose', purpose: null));
		$mapper = $this->mapper();
		$mapper->method('findMultipleAcrossAllMagicTables')->willReturn([$this->locatedRow()]);

		try {
			$query = ['_ids' => ['located']];
			$mapper->searchObjectsPaginated(searchQuery: $query, countQuery: $query);
			$this->fail('The search ran.');
		} catch (PurposeRefusedException $refusal) {
			$this->assertSame([], $this->tableQueries);
		}
	}//end testQueryIdsOnATableThatNeedsAPurposeAreRefused()

	public function testARelationSearchWithoutATableIsAnsweredByTheTable(): void {
		$located = $this->locatedRow();
		$mapper  = $this->mapper();
		$mapper->method('findByRelationAcrossAllMagicTables')->willReturn([$located]);

		$query  = ['_relations_contains' => 'target'];
		$result = $mapper->searchObjectsPaginated(searchQuery: $query, countQuery: $query);

		$this->assertNotContains($located, $result['results']);
		$this->assertSame(1, $result['total']);
		$this->assertSame([[true, true], [true, true]], $this->tableFlags());
	}//end testARelationSearchWithoutATableIsAnsweredByTheTable()

	/**
	 * The flags of the call decide, whatever the query carries.
	 *
	 * @return void
	 */
	public function testTheCallsFlagsOverrideTheQuerys(): void {
		$mapper = $this->mapper();
		$mapper->method('findMultipleAcrossAllMagicTables')->willReturn([$this->locatedRow()]);

		$query = ['_ids' => ['located'], '_rbac' => false, '_multitenancy' => false];
		$mapper->searchObjectsPaginated(searchQuery: $query, countQuery: $query);

		$this->assertSame([[true, true], [true, true]], $this->tableFlags());
	}//end testTheCallsFlagsOverrideTheQuerys()

	/**
	 * A trusted caller's flags reach the table too.
	 *
	 * @return void
	 */
	public function testATrustedCallersFlagsReachTheTable(): void {
		$mapper = $this->mapper();
		$mapper->method('findMultipleAcrossAllMagicTables')->willReturn([$this->locatedRow()]);

		$query = ['_ids' => ['located']];
		$mapper->searchObjectsPaginated(searchQuery: $query, countQuery: $query, _rbac: false, _multitenancy: false);

		$this->assertSame([[false, false], [false, false]], $this->tableFlags());
	}//end testATrustedCallersFlagsReachTheTable()

	/**
	 * Several rows of one table search that table once.
	 *
	 * @return void
	 */
	public function testATableIsSearchedOnce(): void {
		$mapper = $this->mapper();
		$mapper->method('findMultipleAcrossAllMagicTables')->willReturn([$this->locatedRow(), $this->locatedRow()]);

		$query = ['_ids' => ['located']];
		$mapper->searchObjectsPaginated(searchQuery: $query, countQuery: $query);

		$this->assertCount(2, $this->tableQueries);
	}//end testATableIsSearchedOnce()

	/**
	 * An id nothing holds reads no table and answers nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownIdReadsNoTable(): void {
		$mapper = $this->mapper();
		$mapper->method('findMultipleAcrossAllMagicTables')->willReturn([]);

		$query  = ['_ids' => ['nothing']];
		$result = $mapper->searchObjectsPaginated(searchQuery: $query, countQuery: $query);

		$this->assertSame([], $result['results']);
		$this->assertSame(0, $result['total']);
		$this->assertSame([], $this->tableQueries);
	}//end testAnUnknownIdReadsNoTable()

	/**
	 * A row whose schema is gone names no table; the other rows still answer.
	 *
	 * @return void
	 */
	public function testARowOfAGoneTableIsSkipped(): void {
		$gone = $this->locatedRow();
		$gone->setSchema((string) self::GONE_SCHEMA);

		$mapper = $this->mapper();
		$mapper->method('findMultipleAcrossAllMagicTables')->willReturn([$gone, $this->locatedRow()]);

		$query  = ['_ids' => ['located']];
		$result = $mapper->searchObjectsPaginated(searchQuery: $query, countQuery: $query);

		$this->assertCount(1, $result['results']);
		$this->assertCount(2, $this->tableQueries);
	}//end testARowOfAGoneTableIsSkipped()

	/**
	 * The response names a table only when a row of it is on the page.
	 *
	 * @return void
	 */
	public function testTheResponseNamesOnlyTheTablesOnThePage(): void {
		$mapper = $this->mapper();
		$mapper->method('findMultipleAcrossAllMagicTables')->willReturn([$this->locatedRow()]);

		$this->tableRows = [];
		$query           = ['_ids' => ['located']];
		$result          = $mapper->searchObjectsPaginated(searchQuery: $query, countQuery: $query);

		$this->assertSame([], $result['registers']);
		$this->assertSame([], $result['schemas']);

		$this->tableRows = [$this->locatedRow()];
		$result          = $mapper->searchObjectsPaginated(searchQuery: $query, countQuery: $query);

		$this->assertSame([7], array_keys($result['registers']));
		$this->assertSame([9], array_keys($result['schemas']));
	}//end testTheResponseNamesOnlyTheTablesOnThePage()

	/**
	 * Without ids the query is left as it came.
	 *
	 * @return void
	 */
	public function testWithoutIdsNothingIsNarrowed(): void {
		$this->mapper()->searchObjects(query: self::SCOPE);

		$this->assertSame(['none'], $this->tableIds());
	}//end testWithoutIdsNothingIsNarrowed()
}//end class
