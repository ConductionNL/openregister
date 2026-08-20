<?php

/**
 * ViewPresentationService Unit Test
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/saved-search-views/spec.md
 */

declare(strict_types=1);

namespace Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\View;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\ViewPresentationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ViewPresentationService (kanban board + calendar range queries).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @spec openspec/specs/saved-search-views/spec.md
 */
class ViewPresentationServiceTest extends TestCase {

	private ViewPresentationService $service;

	private SchemaMapper&MockObject $schemaMapper;

	private ObjectService&MockObject $objectService;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->schemaMapper = $this->createMock(originalClassName: SchemaMapper::class);
		$this->objectService = $this->createMock(originalClassName: ObjectService::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);

		$this->service = new ViewPresentationService(
			schemaMapper: $this->schemaMapper,
			objectService: $this->objectService,
			logger: $this->logger
		);
	}//end setUp()

	/**
	 * Build a view with the given presentation + query.
	 *
	 * @param array|null $presentation The presentation config
	 * @param array<string, mixed> $query The view's query
	 *
	 * @return View
	 */
	private function createView(?array $presentation, array $query): View {
		$view = new View();
		$view->setPresentation($presentation);
		$view->setQuery($query);
		return $view;
	}//end createView()

	private function createSchema(array $properties): Schema {
		$schema = new Schema();
		$schema->setProperties($properties);
		return $schema;
	}//end createSchema()

	// ── getKanbanBoard() — guard rails ──

	public function testGetKanbanBoardThrowsWhenViewTypeIsNotKanban(): void {
		$view = $this->createView(['viewType' => 'table'], ['registers' => [1], 'schemas' => [2]]);

		$this->expectException(InvalidArgumentException::class);
		$this->service->getKanbanBoard(view: $view);
	}//end testGetKanbanBoardThrowsWhenViewTypeIsNotKanban()

	public function testGetKanbanBoardThrowsWhenGroupByFieldMissing(): void {
		$view = $this->createView(['viewType' => 'kanban', 'kanban' => []], ['registers' => [1], 'schemas' => [2]]);

		$this->expectException(InvalidArgumentException::class);
		$this->service->getKanbanBoard(view: $view);
	}//end testGetKanbanBoardThrowsWhenGroupByFieldMissing()

	public function testGetKanbanBoardThrowsWhenRegisterOrSchemaMissing(): void {
		$view = $this->createView(
			['viewType' => 'kanban', 'kanban' => ['groupByField' => 'status']],
			[]
		);

		$this->expectException(InvalidArgumentException::class);
		$this->service->getKanbanBoard(view: $view);
	}//end testGetKanbanBoardThrowsWhenRegisterOrSchemaMissing()

	// ── getKanbanBoard() — column derivation + pagination (REQ-VIEW-KANBAN-02) ──

	public function testGetKanbanBoardDerivesColumnsFromEnumOrder(): void {
		$view = $this->createView(
			['viewType' => 'kanban', 'kanban' => ['groupByField' => 'status']],
			['registers' => [1], 'schemas' => [2]]
		);

		$this->schemaMapper->method('find')->willReturn(
			$this->createSchema(['status' => ['type' => 'string', 'enum' => ['todo', 'doing', 'done']]])
		);

		$this->objectService->expects($this->once())->method('setRegister')->with(1);
		$this->objectService->expects($this->once())->method('setSchema')->with(2);

		$this->objectService->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query) {
				return [
					'results' => [['id' => 'obj-' . $query['status']]],
					'total' => 1,
				];
			}
		);

		$board = $this->service->getKanbanBoard(view: $view, requestParams: ['_limit' => 10, '_offset' => 0]);

		$this->assertSame('kanban', $board['viewType']);
		$this->assertSame('status', $board['groupByField']);
		$this->assertCount(3, $board['columns']);
		$this->assertSame('todo', $board['columns'][0]['value']);
		$this->assertSame('doing', $board['columns'][1]['value']);
		$this->assertSame('done', $board['columns'][2]['value']);
		$this->assertSame(10, $board['columns'][0]['limit']);
		$this->assertSame([['id' => 'obj-todo']], $board['columns'][0]['cards']);
	}//end testGetKanbanBoardDerivesColumnsFromEnumOrder()

	public function testGetKanbanBoardRespectsExplicitColumnOrderOverEnum(): void {
		$view = $this->createView(
			[
				'viewType' => 'kanban',
				'kanban' => ['groupByField' => 'status', 'columnOrder' => ['done', 'todo', 'doing']],
			],
			['registers' => [1], 'schemas' => [2]]
		);

		$this->schemaMapper->method('find')->willReturn(
			$this->createSchema(['status' => ['type' => 'string', 'enum' => ['todo', 'doing', 'done']]])
		);
		$this->objectService->method('searchObjectsPaginated')->willReturn(['results' => [], 'total' => 0]);

		$board = $this->service->getKanbanBoard(view: $view);

		$this->assertSame(['done', 'todo', 'doing'], array_column($board['columns'], 'value'));
	}//end testGetKanbanBoardRespectsExplicitColumnOrderOverEnum()

	public function testGetKanbanBoardFallsBackToFacetDiscoveryWithoutEnumOrColumnOrder(): void {
		$view = $this->createView(
			['viewType' => 'kanban', 'kanban' => ['groupByField' => 'category']],
			['registers' => [1], 'schemas' => [2]]
		);

		$this->schemaMapper->method('find')->willReturn(
			$this->createSchema(['category' => ['type' => 'string']])
		);

		$this->objectService->method('getFacetsForObjects')->willReturn(
			[
				'facets' => [
					'category' => [
						'data' => [
							'buckets' => [
								['value' => 'alpha', 'count' => 2],
								['value' => 'beta', 'count' => 1],
							],
						],
					],
				],
			]
		);
		$this->objectService->method('searchObjectsPaginated')->willReturn(['results' => [], 'total' => 0]);

		$board = $this->service->getKanbanBoard(view: $view);

		$this->assertSame(['alpha', 'beta'], array_column($board['columns'], 'value'));
	}//end testGetKanbanBoardFallsBackToFacetDiscoveryWithoutEnumOrColumnOrder()

	public function testGetKanbanBoardCardsUsePaginationParamsPerColumn(): void {
		$view = $this->createView(
			['viewType' => 'kanban', 'kanban' => ['groupByField' => 'status']],
			['registers' => [1], 'schemas' => [2]]
		);

		$this->schemaMapper->method('find')->willReturn(
			$this->createSchema(['status' => ['type' => 'string', 'enum' => ['todo']]])
		);

		$capturedQuery = null;
		$this->objectService->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query) use (&$capturedQuery) {
				$capturedQuery = $query;
				return ['results' => [], 'total' => 0];
			}
		);

		$this->service->getKanbanBoard(view: $view, requestParams: ['_limit' => 5, '_offset' => 15]);

		$this->assertSame(5, $capturedQuery['_limit']);
		$this->assertSame(15, $capturedQuery['_offset']);
		$this->assertSame('todo', $capturedQuery['status']);
	}//end testGetKanbanBoardCardsUsePaginationParamsPerColumn()

	// ── getCalendarObjects() — guard rails ──

	public function testGetCalendarObjectsThrowsWhenViewTypeIsNotCalendar(): void {
		$view = $this->createView(['viewType' => 'kanban'], ['registers' => [1], 'schemas' => [2]]);

		$this->expectException(InvalidArgumentException::class);
		$this->service->getCalendarObjects(view: $view, rangeStart: '2026-07-01', rangeEnd: '2026-07-31');
	}//end testGetCalendarObjectsThrowsWhenViewTypeIsNotCalendar()

	public function testGetCalendarObjectsThrowsWhenDateFieldMissing(): void {
		$view = $this->createView(
			['viewType' => 'calendar', 'calendar' => []],
			['registers' => [1], 'schemas' => [2]]
		);

		$this->expectException(InvalidArgumentException::class);
		$this->service->getCalendarObjects(view: $view, rangeStart: '2026-07-01', rangeEnd: '2026-07-31');
	}//end testGetCalendarObjectsThrowsWhenDateFieldMissing()

	// ── getCalendarObjects() — date-range query (REQ-VIEW-CAL-04) ──

	public function testGetCalendarObjectsReturnsOnlyObjectsInRange(): void {
		$view = $this->createView(
			['viewType' => 'calendar', 'calendar' => ['dateField' => 'dueDate']],
			['registers' => [1], 'schemas' => [2]]
		);

		$capturedQuery = null;
		$this->objectService->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query) use (&$capturedQuery) {
				$capturedQuery = $query;
				return [
					'results' => [['id' => 'obj-1', 'dueDate' => '2026-07-15']],
					'total' => 1,
				];
			}
		);

		$result = $this->service->getCalendarObjects(view: $view, rangeStart: '2026-07-01', rangeEnd: '2026-07-31');

		$this->assertSame('calendar', $result['viewType']);
		$this->assertSame('dueDate', $result['dateField']);
		$this->assertNull($result['endDateField']);
		$this->assertSame(['gte' => '2026-07-01', 'lte' => '2026-07-31'], $capturedQuery['dueDate']);
		$this->assertCount(1, $result['objects']);
		$this->assertSame('obj-1', $result['objects'][0]['id']);
	}//end testGetCalendarObjectsReturnsOnlyObjectsInRange()

	public function testGetCalendarObjectsMergesSpanningObjectsWithEndDateField(): void {
		$view = $this->createView(
			['viewType' => 'calendar', 'calendar' => ['dateField' => 'startDate', 'endDateField' => 'endDate']],
			['registers' => [1], 'schemas' => [2]]
		);

		$callCount = 0;
		$this->objectService->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query) use (&$callCount) {
				$callCount++;
				if ($callCount === 1) {
					// Starting-within-range query.
					return ['results' => [['id' => 'obj-starts-in-range']], 'total' => 1];
				}

				// Spanning query.
				return ['results' => [['id' => 'obj-spans-in'], ['id' => 'obj-starts-in-range']], 'total' => 2];
			}
		);

		$result = $this->service->getCalendarObjects(view: $view, rangeStart: '2026-07-01', rangeEnd: '2026-07-31');

		$ids = array_column($result['objects'], 'id');
		sort($ids);
		$this->assertSame(['obj-spans-in', 'obj-starts-in-range'], $ids);
		$this->assertSame('endDate', $result['endDateField']);
	}//end testGetCalendarObjectsMergesSpanningObjectsWithEndDateField()

	// ── getCalendarObjects() — de-duplication across the two queries, ENTITY rows ──
	//
	// Everything above feeds the service plain arrays. searchObjectsPaginated()
	// also returns ObjectEntity rows — ObjectService::collectNamesForResults()
	// branches on `instanceof ObjectEntity` before it branches on is_array() —
	// and that is the shape the identity key was broken for: ObjectEntity
	// declares getUuid()/getId() only as `@method`, served through
	// Entity::__call(), so method_exists() is FALSE and every row fell through
	// to spl_object_hash(). The tests below use REAL entities for that reason.

	/**
	 * One database row hydrated twice — once by the "starting" query, once by the
	 * "spanning" query — must collapse to a single calendar entry.
	 *
	 * @return void
	 */
	public function testGetCalendarObjectsDeduplicatesTheSameEntityRowAcrossBothQueries(): void {
		$view = $this->createView(
			['viewType' => 'calendar', 'calendar' => ['dateField' => 'startDate', 'endDateField' => 'endDate']],
			['registers' => [1], 'schemas' => [2]]
		);

		// Two SEPARATE PHP instances carrying the SAME uuid: exactly what two
		// queries hydrating one row produce. spl_object_hash() differs between
		// them, so an identity that falls back to it renders the object twice.
		$startingRow = new ObjectEntity();
		$startingRow->setUuid('shared-row-uuid');
		$spanningRow = new ObjectEntity();
		$spanningRow->setUuid('shared-row-uuid');

		$callCount = 0;
		$this->objectService->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query) use (&$callCount, $startingRow, $spanningRow) {
				$callCount++;
				if ($callCount === 1) {
					return ['results' => [$startingRow], 'total' => 1];
				}

				return ['results' => [$spanningRow], 'total' => 1];
			}
		);

		$result = $this->service->getCalendarObjects(view: $view, rangeStart: '2026-07-01', rangeEnd: '2026-07-31');

		$this->assertCount(1, $result['objects'], 'The same row must not render twice on the calendar');
		$this->assertSame(1, $result['total']);
	}//end testGetCalendarObjectsDeduplicatesTheSameEntityRowAcrossBothQueries()

	/**
	 * Two genuinely different entity rows must still both survive the merge —
	 * the fail-closed control for the test above, which a "collapse everything"
	 * identity would pass.
	 *
	 * @return void
	 */
	public function testGetCalendarObjectsKeepsDistinctEntityRows(): void {
		$view = $this->createView(
			['viewType' => 'calendar', 'calendar' => ['dateField' => 'startDate', 'endDateField' => 'endDate']],
			['registers' => [1], 'schemas' => [2]]
		);

		$first = new ObjectEntity();
		$first->setUuid('row-one');
		$second = new ObjectEntity();
		$second->setUuid('row-two');

		$callCount = 0;
		$this->objectService->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query) use (&$callCount, $first, $second) {
				$callCount++;
				if ($callCount === 1) {
					return ['results' => [$first], 'total' => 1];
				}

				return ['results' => [$second], 'total' => 1];
			}
		);

		$result = $this->service->getCalendarObjects(view: $view, rangeStart: '2026-07-01', rangeEnd: '2026-07-31');

		$this->assertCount(2, $result['objects']);
		$this->assertSame(2, $result['total']);
	}//end testGetCalendarObjectsKeepsDistinctEntityRows()

	/**
	 * An entity row and an already-rendered array row for the SAME object must
	 * produce the same key. getObject()/jsonSerialize() publish the uuid under
	 * `id`, which is what the is_array() branch reads, so the object branch has
	 * to prefer uuid over the numeric primary key or the two shapes diverge.
	 *
	 * @return void
	 */
	public function testGetCalendarObjectsCollapsesAnEntityAndItsRenderedArrayForm(): void {
		$view = $this->createView(
			['viewType' => 'calendar', 'calendar' => ['dateField' => 'startDate', 'endDateField' => 'endDate']],
			['registers' => [1], 'schemas' => [2]]
		);

		$entityRow = new ObjectEntity();
		$entityRow->setId(42);
		$entityRow->setUuid('mixed-shape-uuid');

		$callCount = 0;
		$this->objectService->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query) use (&$callCount, $entityRow) {
				$callCount++;
				if ($callCount === 1) {
					return ['results' => [$entityRow], 'total' => 1];
				}

				return ['results' => [['id' => 'mixed-shape-uuid']], 'total' => 1];
			}
		);

		$result = $this->service->getCalendarObjects(view: $view, rangeStart: '2026-07-01', rangeEnd: '2026-07-31');

		$this->assertCount(1, $result['objects']);
	}//end testGetCalendarObjectsCollapsesAnEntityAndItsRenderedArrayForm()
}//end class
