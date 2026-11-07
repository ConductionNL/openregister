<?php

/**
 * Unit tests for TablesObjectSourceProvider.
 *
 * The Tables app is not installed under the CI runner, so all Tables access is
 * mocked at the {@see TablesTableReader} boundary (the sole class that names
 * `OCA\Tables\*`). These tests cover the observable provider contract: id,
 * enablement, RBAC fail-closed, row → object projection, find by rowId and by
 * derived UUID, count, native limit/offset pushdown, and the `config.viewId`
 * View binding.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tables-object-source-provider/specs/tables-virtual-register/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectSource\TablesColumnMapper;
use OCA\OpenRegister\Service\ObjectSource\TablesObjectSourceProvider;
use OCA\OpenRegister\Service\ObjectSource\TablesSchemaSyncService;
use OCA\OpenRegister\Service\ObjectSource\TablesReadScope;
use OCA\OpenRegister\Service\ObjectSource\TablesTableReader;
use OCA\OpenRegister\Service\ObjectSource\TablesUuidDeriver;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Test class for TablesObjectSourceProvider.
 */
class TablesObjectSourceProviderTest extends TestCase {

	/**
	 * The mocked Tables reader (the Tables-service boundary).
	 *
	 * @var TablesTableReader&MockObject
	 */
	private $reader;

	/**
	 * The mocked schema-sync service (relation-target lookups).
	 *
	 * @var TablesSchemaSyncService&MockObject
	 */
	private $syncService;

	/**
	 * The deriver (real — pure logic).
	 *
	 * @var TablesUuidDeriver
	 */
	private TablesUuidDeriver $deriver;

	/**
	 * Build collaborators before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->reader = $this->createMock(TablesTableReader::class);
		$this->syncService = $this->createMock(TablesSchemaSyncService::class);
		$this->deriver = new TablesUuidDeriver();
	}//end setUp()

	/**
	 * A session with a given login state.
	 *
	 * @param bool $loggedIn Whether a user is logged in.
	 *
	 * @return IUserSession The session.
	 */
	private function session(bool $loggedIn = true): IUserSession {
		$userSession = $this->createMock(IUserSession::class);
		if ($loggedIn === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
			$userSession->method('getUser')->willReturn($user);
		} else {
			$userSession->method('getUser')->willReturn(null);
		}

		return $userSession;
	}//end session()

	/**
	 * Build a provider with a given login state.
	 *
	 * @param bool $loggedIn Whether a user is logged in.
	 *
	 * @return TablesObjectSourceProvider The provider under test.
	 */
	private function provider(bool $loggedIn = true): TablesObjectSourceProvider {
		$userSession = $this->session(loggedIn: $loggedIn);

		$columnMapper = new TablesColumnMapper($this->deriver, new NullLogger());

		return new TablesObjectSourceProvider(
			$this->reader,
			$columnMapper,
			$this->deriver,
			$this->syncService,
			$userSession,
			new NullLogger()
		);
	}//end provider()

	/**
	 * The register/schema pair the provider is bound to.
	 *
	 * @return array{0: Register, 1: Schema} The register and schema.
	 */
	private function binding(): array {
		$register = new Register();
		$register->setId(30);
		$schema = new Schema();
		$schema->setId(300);
		return [$register, $schema];
	}//end binding()

	/**
	 * A minimal single-text-column table shape used by several tests.
	 *
	 * @return array<int, array<string, mixed>> The column descriptors.
	 */
	private function columns(): array {
		return [['id' => 1, 'title' => 'Name', 'technicalName' => 'name', 'type' => 'text']];
	}//end columns()

	/**
	 * getId() is the stable provider id.
	 *
	 * @return void
	 */
	public function testGetId(): void {
		$this->assertSame('tables', $this->provider()->getId());
	}//end testGetId()

	/**
	 * isEnabled() reflects the reader's availability.
	 *
	 * @return void
	 */
	public function testIsEnabledReflectsReader(): void {
		$this->reader->method('isAvailable')->willReturn(true);
		$this->assertTrue($this->provider()->isEnabled());
	}//end testIsEnabledReflectsReader()

	/**
	 * Reads fail closed to empty when no user is logged in.
	 *
	 * @return void
	 */
	public function testFailsClosedWithoutUser(): void {
		[$register, $schema] = $this->binding();
		$provider = $this->provider(false);

		$this->reader->expects($this->never())->method('findRows');
		$this->reader->expects($this->never())->method('findRow');
		$this->reader->expects($this->never())->method('countRows');
		$this->reader->expects($this->never())->method('listColumns');

		$this->assertSame([], $provider->findAll($register, $schema, [], ['tableId' => 5]));
		$this->assertSame(0, $provider->count($register, $schema, [], ['tableId' => 5]));
		$this->assertNull($provider->find($register, $schema, '1', ['tableId' => 5]));
	}//end testFailsClosedWithoutUser()

	/**
	 * findAll pushes limit/offset natively to the reader and maps each row.
	 *
	 * @return void
	 */
	public function testFindAllPushesPaginationAndMaps(): void {
		[$register, $schema] = $this->binding();

		$this->reader->method('listColumns')->willReturn($this->columns());
		$this->reader->expects($this->once())
			->method('findRows')
			->with((new TablesReadScope(5, 'alice'))->withPage(limit: 10, offset: 20))
			->willReturn([['id' => 42, 'cells' => [['columnId' => 1, 'value' => 'Swing']]]]);

		$objects = $this->provider()->findAll($register, $schema, ['limit' => 10, 'offset' => 20], ['tableId' => 5]);

		$this->assertCount(1, $objects);
		$this->assertSame('Swing', $objects[0]->getObject()['name']);
		$this->assertSame('42', $objects[0]->getObject()['id']);
		$this->assertSame($this->deriver->deriveObjectUuid(tableId: 5, rowId: 42), $objects[0]->getUuid());
	}//end testFindAllPushesPaginationAndMaps()

	/**
	 * find() by numeric rowId returns the single mapped object.
	 *
	 * @return void
	 */
	public function testFindByRowId(): void {
		[$register, $schema] = $this->binding();

		$this->reader->method('listColumns')->willReturn($this->columns());
		$this->reader->expects($this->once())
			->method('findRow')
			->with(42, new TablesReadScope(5, 'alice'))
			->willReturn(['id' => 42, 'cells' => [['columnId' => 1, 'value' => 'Swing']]]);

		$object = $this->provider()->find($register, $schema, '42', ['tableId' => 5]);

		$this->assertNotNull($object);
		$this->assertSame('Swing', $object->getObject()['name']);
	}//end testFindByRowId()

	/**
	 * A denied/absent row is null (denied == absent, no oracle).
	 *
	 * @return void
	 */
	public function testFindDeniedIsNull(): void {
		[$register, $schema] = $this->binding();

		$this->reader->method('findRow')->willReturn(null);

		$this->assertNull($this->provider()->find($register, $schema, '42', ['tableId' => 5]));
	}//end testFindDeniedIsNull()

	/**
	 * find() by derived UUID resolves via a bounded scan of the bound table.
	 *
	 * @return void
	 */
	public function testFindByDerivedUuid(): void {
		[$register, $schema] = $this->binding();
		$uuid = $this->deriver->deriveObjectUuid(tableId: 5, rowId: 42);

		$this->reader->method('listColumns')->willReturn($this->columns());
		$this->reader->method('findRows')->willReturn(
			[
				['id' => 41, 'cells' => []],
				['id' => 42, 'cells' => [['columnId' => 1, 'value' => 'Swing']]],
			]
		);

		$object = $this->provider()->find($register, $schema, $uuid, ['tableId' => 5]);

		$this->assertNotNull($object);
		$this->assertSame($uuid, $object->getUuid());
		$this->assertSame('Swing', $object->getObject()['name']);
	}//end testFindByDerivedUuid()

	/**
	 * count() delegates to the reader for a table and for a View binding.
	 *
	 * @return void
	 */
	public function testCountTableAndView(): void {
		[$register, $schema] = $this->binding();

		$this->reader->method('countRows')->willReturnCallback(
			static function (TablesReadScope $scope) {
				return ($scope->isView() === true) ? 7 : 3;
			}
		);

		$this->assertSame(3, $this->provider()->count($register, $schema, [], ['tableId' => 5]));
		$this->assertSame(7, $this->provider()->count($register, $schema, [], ['tableId' => 5, 'viewId' => 9]));
	}//end testCountTableAndView()

	/**
	 * A config.viewId binds a Tables View.
	 *
	 * @return void
	 */
	public function testViewIdBindsView(): void {
		[$register, $schema] = $this->binding();

		$this->reader->method('listColumns')->willReturn($this->columns());
		$this->reader->expects($this->once())
			->method('findRows')
			->with((new TablesReadScope(5, 'alice'))->withView(viewId: 9)->withPage(limit: 200, offset: 0))
			->willReturn([['id' => 1, 'cells' => []]]);

		$objects = $this->provider()->findAll($register, $schema, [], ['tableId' => 5, 'viewId' => 9]);

		$this->assertCount(1, $objects);
	}//end testViewIdBindsView()

	/**
	 * find() ignores a non-numeric, non-UUID id.
	 *
	 * @return void
	 */
	public function testFindRejectsGarbageId(): void {
		[$register, $schema] = $this->binding();
		$this->assertNull($this->provider()->find($register, $schema, 'nonsense', ['tableId' => 5]));
	}//end testFindRejectsGarbageId()

	/**
	 * The row's owner and timestamps land on the entity; an empty owner, a bad date
	 * and an empty date leave the field unset.
	 *
	 * @return void
	 */
	public function testRowMetadata(): void {
		[$register, $schema] = $this->binding();
		$this->reader->method('listColumns')->willReturn($this->columns());
		$this->reader->method('findRow')->willReturnOnConsecutiveCalls(
			['id' => 42, 'createdBy' => 'alice', 'createdAt' => '2026-01-02T03:04:05+00:00', 'lastEditAt' => '2026-02-03T00:00:00+00:00', 'cells' => []],
			['id' => 43, 'createdBy' => '', 'createdAt' => 'not a date', 'lastEditAt' => '', 'cells' => []]
		);
		$provider = $this->provider();

		$full = $provider->find($register, $schema, '42', ['tableId' => 5]);
		$this->assertSame('alice', $full->getOwner());
		$this->assertSame('2026-01-02', $full->getCreated()->format('Y-m-d'));
		$this->assertSame('2026-02-03', $full->getUpdated()->format('Y-m-d'));
		$this->assertSame('30', $full->getRegister());
		$this->assertSame('300', $full->getSchema());

		$bare = $provider->find($register, $schema, '43', ['tableId' => 5]);
		$this->assertNull($bare->getOwner());
		$this->assertNull($bare->getCreated());
		$this->assertNull($bare->getUpdated());
	}//end testRowMetadata()

	/**
	 * Without a table or view binding nothing is read.
	 *
	 * @return void
	 */
	public function testNoBindingReadsNothing(): void {
		[$register, $schema] = $this->binding();
		$this->reader->expects($this->never())->method('countRows');
		$this->reader->expects($this->never())->method('findRow');
		$provider = $this->provider();

		$this->assertSame(0, $provider->count($register, $schema));
		$this->assertSame([], $provider->findAll($register, $schema));
		$this->assertNull($provider->find($register, $schema, '42'));
	}//end testNoBindingReadsNothing()

	/**
	 * A view binding without a table id can be counted but not listed.
	 *
	 * @return void
	 */
	public function testViewWithoutTableCountsButDoesNotList(): void {
		[$register, $schema] = $this->binding();
		$this->reader->method('countRows')->willReturn(4);
		$provider = $this->provider();

		$this->assertSame(4, $provider->count($register, $schema, [], ['viewId' => 9]));
		$this->assertSame([], $provider->findAll($register, $schema, [], ['viewId' => 9]));
	}//end testViewWithoutTableCountsButDoesNotList()

	/**
	 * A UUID that matches no row is null, and the bounded scan goes through the bound View.
	 *
	 * @return void
	 */
	public function testUnknownUuidIsNull(): void {
		[$register, $schema] = $this->binding();
		$this->reader->expects($this->once())
			->method('findRows')
			->with((new TablesReadScope(5, 'alice'))->withView(viewId: 9)->withPage(limit: 1000, offset: 0))
			->willReturn([['id' => 1, 'cells' => []]]);

		$uuid = $this->deriver->deriveObjectUuid(tableId: 5, rowId: 2);
		$this->assertNull($this->provider()->find($register, $schema, $uuid, ['tableId' => 5, 'viewId' => 9]));
	}//end testUnknownUuidIsNull()

	/**
	 * Three rows with a name and a number, and the columns they project through.
	 *
	 * @return void
	 */
	private function threeRows(): void {
		$this->reader->method('listColumns')->willReturn(
			[
				['id' => 1, 'title' => 'Name', 'technicalName' => 'name', 'type' => 'text'],
				['id' => 2, 'title' => 'Rank', 'technicalName' => 'rank', 'type' => 'number'],
			]
		);
		$this->reader->method('findRows')->willReturn(
			[
				['id' => 1, 'cells' => [['columnId' => 1, 'value' => 'Swing'], ['columnId' => 2, 'value' => 2]]],
				['id' => 2, 'cells' => [['columnId' => 1, 'value' => 'Blues'], ['columnId' => 2, 'value' => 3]]],
				['id' => 3, 'cells' => [['columnId' => 1, 'value' => 'Swing'], ['columnId' => 2, 'value' => 1]]],
			]
		);
	}//end threeRows()

	/**
	 * The row ids of a result.
	 *
	 * @param array<int, ObjectEntity> $objects The objects.
	 *
	 * @return array<int, string> The ids.
	 */
	private function ids(array $objects): array {
		return array_map(static fn (ObjectEntity $object): string => $object->getObject()['id'], $objects);
	}//end ids()

	/**
	 * Filters, search, ids and sort narrow and order the result, in each shape callers send.
	 *
	 * @dataProvider narrowingQueries
	 *
	 * @param array<string, mixed> $query The query.
	 * @param array<int, string> $expected The row ids, in order.
	 *
	 * @return void
	 */
	public function testAQueryIsApplied(array $query, array $expected): void {
		[$register, $schema] = $this->binding();
		$this->threeRows();

		$this->assertSame($expected, $this->ids($this->provider()->findAll($register, $schema, $query, ['tableId' => 5])));
	}//end testAQueryIsApplied()

	/**
	 * The queries of testAQueryIsApplied.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<int, string>}> The cases.
	 */
	public static function narrowingQueries(): array {
		$uuid = (new TablesUuidDeriver())->deriveObjectUuid(tableId: 5, rowId: 2);

		return [
			'filter' => [['filters' => ['name' => 'Swing']], ['1', '3']],
			'filter on a number, as text' => [['filters' => ['rank' => '3']], ['2']],
			'filter on any of a list' => [['filters' => ['rank' => [1, 3]]], ['2', '3']],
			'two filters' => [['filters' => ['name' => 'Swing', 'rank' => 1]], ['3']],
			'filter on an unknown property' => [['filters' => ['colour' => 'red']], []],
			'search, ignoring case' => [['search' => 'BLU'], ['2']],
			'search under filters' => [['filters' => ['search' => 'wing']], ['1', '3']],
			'search as _search' => [['_search' => 'wing'], ['1', '3']],
			'ids by row id' => [['ids' => ['1', '3']], ['1', '3']],
			'ids by uuid' => [['_ids' => [$uuid]], ['2']],
			'sort map' => [['sort' => ['rank' => 'DESC']], ['2', '1', '3']],
			'sort list as JSON' => [['sort' => '[{"field":"rank","direction":"ASC"}]'], ['3', '1', '2']],
			'sort text with minus' => [['sort' => '-rank'], ['2', '1', '3']],
			'sort by two fields' => [['sort' => ['name' => 'ASC', 'rank' => 'ASC']], ['2', '3', '1']],
			'paged after the filter' => [['filters' => ['name' => 'Swing'], 'limit' => 1, 'offset' => 1], ['3']],
		];
	}//end narrowingQueries()

	/**
	 * A query with nothing to apply stays a native page, whatever empty keys it carries.
	 *
	 * @return void
	 */
	public function testEmptyOperatorsStayNative(): void {
		[$register, $schema] = $this->binding();
		$this->reader->expects($this->once())
			->method('findRows')
			->with((new TablesReadScope(5, 'alice'))->withPage(limit: 10, offset: 20))
			->willReturn([]);

		$query = ['limit' => 10, 'offset' => 20, 'filters' => [], 'sort' => [], 'search' => null, 'ids' => null];
		$this->assertSame([], $this->provider()->findAll($register, $schema, $query, ['tableId' => 5]));
	}//end testEmptyOperatorsStayNative()

	/**
	 * The count of a narrowed query counts what the query matches, not the table.
	 *
	 * @return void
	 */
	public function testCountFollowsTheQuery(): void {
		[$register, $schema] = $this->binding();
		$this->threeRows();
		$this->reader->expects($this->never())->method('countRows');

		$this->assertSame(2, $this->provider()->count($register, $schema, ['filters' => ['name' => 'Swing'], 'limit' => 1], ['tableId' => 5]));
	}//end testCountFollowsTheQuery()

	/**
	 * A query reads a bounded scan, and the columns once.
	 *
	 * @return void
	 */
	public function testAQueryScansBoundedAndReadsColumnsOnce(): void {
		[$register, $schema] = $this->binding();
		$this->reader->expects($this->once())->method('listColumns')->willReturn($this->columns());
		$this->reader->expects($this->once())
			->method('findRows')
			->with((new TablesReadScope(5, 'alice'))->withPage(limit: 1000, offset: 0))
			->willReturn([['id' => 1, 'cells' => []], ['id' => 2, 'cells' => []]]);

		$this->provider()->findAll($register, $schema, ['search' => 'x'], ['tableId' => 5]);
	}//end testAQueryScansBoundedAndReadsColumnsOnce()

	/**
	 * A query on a View-bound schema scans that View, never the whole table.
	 *
	 * @return void
	 */
	public function testAQueryOnAViewScansTheView(): void {
		[$register, $schema] = $this->binding();
		$this->reader->method('listColumns')->willReturn($this->columns());
		$this->reader->expects($this->exactly(2))
			->method('findRows')
			->with((new TablesReadScope(5, 'alice'))->withView(viewId: 9)->withPage(limit: 1000, offset: 0))
			->willReturn([]);

		$config = ['tableId' => 5, 'viewId' => 9];
		$this->provider()->findAll($register, $schema, ['search' => 'x'], $config);
		$this->provider()->count($register, $schema, ['search' => 'x'], $config);
	}//end testAQueryOnAViewScansTheView()

	/**
	 * A scan that reaches the cap says so: rows past it were not considered.
	 *
	 * @return void
	 */
	public function testAScanAtTheCapWarns(): void {
		[$register, $schema] = $this->binding();
		$this->reader->method('listColumns')->willReturn($this->columns());
		$this->reader->method('findRows')->willReturn(array_fill(0, 1000, ['id' => 1, 'cells' => []]));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('first 1000 rows of table 5'));

		$provider = new TablesObjectSourceProvider(
			$this->reader,
			new TablesColumnMapper($this->deriver, new NullLogger()),
			$this->deriver,
			$this->syncService,
			$this->session(),
			$logger
		);
		$provider->findAll($register, $schema, ['search' => 'x'], ['tableId' => 5]);
	}//end testAScanAtTheCapWarns()
}//end class
