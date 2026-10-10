<?php

/**
 * OpenRegister - the `_recent` lens on the cross-table search paths.
 *
 * The single-schema path orders a `_recent` page by the read history in SQL
 * and stamps `@self.viewedAt`. The cross-table paths assemble rows from
 * several tables, or from a lookup by id, and returned them in storage order
 * without the moment. These tests drive `searchAcrossMultipleTables()` and
 * the global `_ids` result from their public and private entry points.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/recently-opened-means-opened/specs/object-interactions/spec.md#requirement-cross-table-searches-honour-the-recent-lens-like-one-schema
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
 * Order, paging and `@self.viewedAt` of a cross-table `_recent` page.
 */
class MagicMapperRecentLensTest extends TestCase {

	/**
	 * The read history, newest first: C, then B, then A, then D, then E.
	 *
	 * @var array<string, string>
	 */
	private const VIEWS = [
		'uuid-c' => '2026-10-09T10:00:00+00:00',
		'uuid-b' => '2026-10-09T09:00:00+00:00',
		'uuid-a' => '2026-10-09T08:00:00+00:00',
		'uuid-d' => '2026-10-08T08:00:00+00:00',
		'uuid-e' => '2026-10-07T08:00:00+00:00',
	];

	/**
	 * The queries each table search received.
	 *
	 * @var array<int, array>
	 */
	private array $tableQueries = [];

	/**
	 * A mapper whose per-table search answers from fixed rows.
	 *
	 * Table `zaak` holds A, C and E; table `taak` holds B and D, each in
	 * storage order, so any order in the result is the lens's doing.
	 *
	 * @return MagicMapper&MockObject The mapper.
	 */
	private function mapper(): MagicMapper {
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
					$this->createMock(IDBConnection::class),
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
			->onlyMethods(['searchObjectsInRegisterSchemaTable'])
			->getMock();

		$rows = ['zaak' => ['uuid-a', 'uuid-c', 'uuid-e'], 'taak' => ['uuid-b', 'uuid-d']];
		$mapper->method('searchObjectsInRegisterSchemaTable')->willReturnCallback(
			function (array $query, Register $register, Schema $schema) use ($rows): array {
				$this->tableQueries[] = $query;
				return array_map(fn (string $uuid): ObjectEntity => $this->object(uuid: $uuid), $rows[$schema->getSlug()]);
			}
		);

		return $mapper;
	}//end mapper()

	/**
	 * One object.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(string $uuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		return $object;
	}//end object()

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
	 * A `_recent` query as SearchQueryHandler leaves it.
	 *
	 * `_aggregations` keeps the search on the sequential path, which needs no
	 * database; the UNION path shares the same wrapper and its `_ids` SQL is
	 * pinned by MagicSearchHandlerIdsSqlTest.
	 *
	 * @param array $extra More query keys.
	 *
	 * @return array The query.
	 */
	private function query(array $extra = []): array {
		return array_merge(
			[
				'_ids' => array_keys(self::VIEWS),
				'_recentViews' => self::VIEWS,
				'_aggregations' => [],
				'_limit' => 20,
				'_offset' => 0,
			],
			$extra
		);
	}//end query()

	/**
	 * Uuids and moments of a result.
	 *
	 * @param array<int, ObjectEntity> $results The result.
	 *
	 * @return array<int, array{0: string|null, 1: mixed}> Uuid and `@self.viewedAt` per object.
	 */
	private function shape(array $results): array {
		return array_map(
			static fn (ObjectEntity $object): array => [$object->getUuid(), ($object->jsonSerialize()['@self']['viewedAt'] ?? null)],
			$results
		);
	}//end shape()

	/**
	 * Two schemas: newest read first across both, each with its moment.
	 *
	 * @return void
	 */
	public function testTwoSchemasAreOrderedByTheHistory(): void {
		$results = $this->mapper()->searchAcrossMultipleTables(query: $this->query(), registerSchemaPairs: $this->pairs());

		$this->assertSame(
			[
				['uuid-c', self::VIEWS['uuid-c']],
				['uuid-b', self::VIEWS['uuid-b']],
				['uuid-a', self::VIEWS['uuid-a']],
				['uuid-d', self::VIEWS['uuid-d']],
				['uuid-e', self::VIEWS['uuid-e']],
			],
			$this->shape($results)
		);
	}//end testTwoSchemasAreOrderedByTheHistory()

	/**
	 * The second page continues the history: sorted first, paged after.
	 *
	 * @return void
	 */
	public function testTheSecondPageContinuesTheHistory(): void {
		$results = $this->mapper()->searchAcrossMultipleTables(
			query: $this->query(['_limit' => 2, '_offset' => 2]),
			registerSchemaPairs: $this->pairs()
		);

		$this->assertSame(['uuid-a', 'uuid-d'], array_column($this->shape($results), 0));
		foreach ($this->tableQueries as $tableQuery) {
			$this->assertSame(0, $tableQuery['_offset'], 'each table is read whole, so the page is cut after sorting');
			$this->assertFalse($tableQuery['_limit']);
			$this->assertSame(array_keys(self::VIEWS), $tableQuery['_ids'], 'the restriction still bounds every table');
		}
	}//end testTheSecondPageContinuesTheHistory()

	/**
	 * An explicit order wins, and every object still carries its moment.
	 *
	 * @return void
	 */
	public function testAnExplicitOrderWinsAndKeepsTheMoment(): void {
		$results = $this->mapper()->searchAcrossMultipleTables(
			query: $this->query(['_order' => ['@self.name' => 'asc']]),
			registerSchemaPairs: $this->pairs()
		);

		$this->assertSame(
			['uuid-a', 'uuid-b', 'uuid-c', 'uuid-d', 'uuid-e'],
			array_column($this->shape($results), 0),
			'the requested order (no names, so the uuid tiebreaker), not the history'
		);
		$this->assertSame(self::VIEWS['uuid-a'], $this->shape($results)[0][1]);
		$this->assertSame(20, $this->tableQueries[0]['_limit'], 'without the history ordering, paging stays with the tables');
	}//end testAnExplicitOrderWinsAndKeepsTheMoment()

	/**
	 * Without the lens nothing changes: no moment, the UNION path's default order (uuid).
	 *
	 * @return void
	 */
	public function testWithoutTheLensNothingChanges(): void {
		$results = $this->mapper()->searchAcrossMultipleTables(
			query: ['_aggregations' => [], '_limit' => 20],
			registerSchemaPairs: $this->pairs()
		);

		$this->assertSame(
			[['uuid-a', null], ['uuid-b', null], ['uuid-c', null], ['uuid-d', null], ['uuid-e', null]],
			$this->shape($results)
		);
	}//end testWithoutTheLensNothingChanges()

	/**
	 * The global `_ids` path (no register or schema) orders, pages and stamps.
	 *
	 * @return void
	 */
	public function testTheGlobalIdsPathOrdersAndStamps(): void {
		$objects = array_map(fn (string $uuid): ObjectEntity => $this->object(uuid: $uuid), ['uuid-e', 'uuid-a', 'uuid-c', 'uuid-b']);

		$method = new \ReflectionMethod(MagicMapper::class, 'getGlobalSearchResult');
		$result = $method->invoke(
			$this->mapper(),
			$objects,
			$this->query(['_limit' => 2, '_offset' => 1]),
			false
		);

		$this->assertSame(4, $result['total']);
		$this->assertSame(
			[['uuid-b', self::VIEWS['uuid-b']], ['uuid-a', self::VIEWS['uuid-a']]],
			$this->shape($result['results'])
		);
	}//end testTheGlobalIdsPathOrdersAndStamps()
}//end class
