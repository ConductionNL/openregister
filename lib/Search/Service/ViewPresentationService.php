<?php

/**
 * OpenRegister ViewPresentationService
 *
 * Backend contract for the kanban and calendar presentations of a saved
 * view: kanban column/card derivation and calendar date-range object
 * queries. Both reuse the existing object search/pagination machinery
 * (ObjectService) — neither introduces new storage or a bespoke write path.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Search\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\Rbac\AggregateVisibility;
use OCA\OpenRegister\Search\Db\View;
use OCP\AppFramework\Db\Entity;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;

/**
 * Derives kanban board data and calendar date-range results for a view's
 * `presentation` config, over the existing object query/pagination surface.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @spec openspec/specs/saved-search-views/spec.md#requirement-kanban-columns-and-cards-req-view-kanban-02
 * @spec openspec/specs/saved-search-views/spec.md#requirement-calendar-plots-objects-by-a-date-field-over-a-range-req-view-cal-04
 */
class ViewPresentationService {

	/**
	 * Schema mapper, used to resolve enum order for the groupByField.
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Object service, used to run the existing paginated/faceted object query.
	 *
	 * @var ObjectOperationsInterface
	 */
	private readonly ObjectOperationsInterface $objectService;

	/**
	 * Logger.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param SchemaMapperInterface $schemaMapper Schema mapper for enum/property discovery
	 * @param ObjectOperationsInterface $objectService Object service for the paginated object query
	 * @param LoggerInterface $logger Logger for error tracking
	 * @param PropertyRbacHandler|null $propertyRbac Judges whether the caller may group on a governed property.
	 *                                               Nullable and last so no construction site shifts; absent, a
	 *                                               governed grouping property is refused, which is safe
	 *
	 * @return void
	 */
	public function __construct(
		SchemaMapperInterface $schemaMapper,
		ObjectOperationsInterface $objectService,
		LoggerInterface $logger,
		// LAST AND NULLABLE so every existing construction keeps working. The
		// container always supplies it; null happens only in a hand-built test,
		// and then a GOVERNED grouping property is refused, which is the safe
		// direction.
		private readonly ?PropertyRbacHandler $propertyRbac = null,
	) {
		$this->schemaMapper = $schemaMapper;
		$this->objectService = $objectService;
		$this->logger = $logger;
	}//end __construct()

	/**
	 * The shared answer to "may a summary over this property be shown".
	 *
	 * @return AggregateVisibility The answer.
	 */
	private function aggregateVisibility(): AggregateVisibility {
		return new AggregateVisibility(rbac: $this->propertyRbac, logger: $this->logger);
	}//end aggregateVisibility()

	/**
	 * Build the kanban board for a view: one column per distinct value of
	 * `groupByField`, cards paginated through the existing object query.
	 *
	 * Column order: `columnOrder` when the view configures one; otherwise
	 * the schema's enum order for `groupByField` when it is an enum
	 * property; otherwise the distinct values observed via the existing
	 * facet/object-query machinery (REQ-VIEW-KANBAN-02).
	 *
	 * @param View $view The kanban view
	 * @param array<string, mixed> $requestParams Request params (_limit/_offset apply per column)
	 *
	 * @return array{viewType: string, groupByField: string, columns: array<int, array<string, mixed>>}
	 *
	 * @throws InvalidArgumentException If the view is not a kanban view or its config is incomplete
	 *
	 * @spec openspec/specs/saved-search-views/spec.md#requirement-kanban-columns-and-cards-req-view-kanban-02
	 */
	public function getKanbanBoard(View $view, array $requestParams = []): array {
		$presentation = $view->getPresentation();
		$viewType = $presentation['viewType'] ?? 'table';
		if ($viewType !== 'kanban') {
			throw new InvalidArgumentException('View is not a kanban view (viewType is "' . $viewType . '")');
		}

		$kanbanConfig = $presentation['kanban'] ?? [];
		$groupByField = $kanbanConfig['groupByField'] ?? null;
		if (is_string($groupByField) === false || $groupByField === '') {
			throw new InvalidArgumentException('Kanban view is missing kanban.groupByField');
		}

		$query = $view->getQuery() ?? [];
		$registerRef = $query['registers'][0] ?? null;
		$schemaRef = $query['schemas'][0] ?? null;
		if ($registerRef === null || $schemaRef === null) {
			throw new InvalidArgumentException('Kanban view requires a register and schema in its query');
		}

		$schema = $this->schemaMapper->find($schemaRef);

		// 🔴 A COLUMN HEADING IS A VALUE. This board is one column per DISTINCT
		// VALUE of `groupByField`, so a governed grouping property becomes a row
		// of headings naming every value it holds, to anybody who may open the
		// view. The cards inside the columns are stripped correctly by the
		// render path, which is exactly what makes this hard to notice: the
		// board looks empty and correct while its headings are the leak.
		if ($this->aggregateVisibility()->maySummariseValues(schema: $schema, property: $groupByField) === false) {
			throw new InvalidArgumentException(
				sprintf(
					'This board groups on \'%s\', which you may not read, so it cannot be drawn for you.',
					$groupByField
				)
			);
		}

		$properties = $schema->getProperties();

		$columnOrder = $kanbanConfig['columnOrder'] ?? null;
		if (is_array($columnOrder) === false) {
			$columnOrder = null;
		}

		// Set register/schema context BEFORE any object-query call (including
		// the enum/columnOrder-less fallback, which discovers distinct values
		// via the existing facet machinery and therefore needs the same
		// register/schema scoping as the per-column card queries below).
		$this->objectService->setRegister(register: $registerRef);
		$this->objectService->setSchema(schema: $schemaRef);

		$baseQuery = $this->buildBaseObjectQuery(query: $query);

		$columnValues = $this->deriveColumnValues(
			properties: $properties,
			groupByField: $groupByField,
			columnOrder: $columnOrder,
			baseQuery: $baseQuery
		);

		$limit = (int)($requestParams['_limit'] ?? 20);
		$offset = (int)($requestParams['_offset'] ?? 0);

		$columns = [];
		foreach ($columnValues as $columnValue) {
			$columnQuery = $baseQuery;
			$columnQuery[$groupByField] = $columnValue;
			$columnQuery['_limit'] = $limit;
			$columnQuery['_offset'] = $offset;

			$result = $this->objectService->searchObjectsPaginated(query: $columnQuery);

			$columns[] = [
				'value' => $columnValue,
				'cards' => $result['results'] ?? [],
				'total' => $result['total'] ?? count($result['results'] ?? []),
				'limit' => $limit,
				'offset' => $offset,
			];
		}//end foreach

		return [
			'viewType' => 'kanban',
			'groupByField' => $groupByField,
			'columns' => $columns,
		];
	}//end getKanbanBoard()

	/**
	 * Return objects for a calendar view whose `dateField` falls within the
	 * visible range, spanning to `endDateField` when configured
	 * (REQ-VIEW-CAL-04).
	 *
	 * @param View $view The calendar view
	 * @param string $rangeStart Inclusive range start (ISO 8601 date/datetime)
	 * @param string $rangeEnd Inclusive range end (ISO 8601 date/datetime)
	 *
	 * @return array{viewType: string, dateField: string, endDateField: string|null,
	 *     rangeStart: string, rangeEnd: string, objects: array<int, mixed>, total: int}
	 *
	 * @throws InvalidArgumentException If the view is not a calendar view or its config is incomplete
	 *
	 * @spec openspec/specs/saved-search-views/spec.md#requirement-calendar-plots-objects-by-a-date-field-over-a-range-req-view-cal-04
	 */
	public function getCalendarObjects(View $view, string $rangeStart, string $rangeEnd): array {
		$calendar = $this->calendarSpec(view: $view);
		$baseQuery = $this->calendarBaseQuery(query: ($view->getQuery() ?? []));
		$calendar += ['rangeStart' => $rangeStart, 'rangeEnd' => $rangeEnd];

		$objectsById = $this->mergeByIdentity(queries: $this->calendarQueries(baseQuery: $baseQuery, calendar: $calendar));

		return ['viewType' => 'calendar'] + $calendar + ['objects' => array_values($objectsById), 'total' => count($objectsById)];
	}//end getCalendarObjects()

	/**
	 * Read a calendar view's date fields, refusing a view that is not a usable calendar.
	 *
	 * An `endDateField` that is not a non-empty string reads as none.
	 *
	 * @param View $view The view.
	 *
	 * @return array{dateField: string, endDateField: string|null}
	 *
	 * @throws InvalidArgumentException If the view is not a calendar view or has no dateField.
	 */
	private function calendarSpec(View $view): array {
		$presentation = $view->getPresentation();
		$viewType = $presentation['viewType'] ?? 'table';
		if ($viewType !== 'calendar') {
			throw new InvalidArgumentException('View is not a calendar view (viewType is "' . $viewType . '")');
		}

		$calendarConfig = $presentation['calendar'] ?? [];
		$dateField = $calendarConfig['dateField'] ?? null;
		if (is_string($dateField) === false || $dateField === '') {
			throw new InvalidArgumentException('Calendar view is missing calendar.dateField');
		}

		return ['dateField' => $dateField, 'endDateField' => $this->nonEmptyStringOrNull(value: ($calendarConfig['endDateField'] ?? null))];
	}//end calendarSpec()

	/**
	 * The value when it is a non-empty string, else null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	private function nonEmptyStringOrNull(mixed $value): ?string {
		if (is_string($value) === true && $value !== '') {
			return $value;
		}

		return null;
	}//end nonEmptyStringOrNull()

	/**
	 * Scope the object service to the calendar view's register and schema, and build its base query.
	 *
	 * @param array<string, mixed> $query The view's stored query.
	 *
	 * @return array<string, mixed> The base object query.
	 *
	 * @throws InvalidArgumentException If the query names no register or no schema.
	 */
	private function calendarBaseQuery(array $query): array {
		$registerRef = $query['registers'][0] ?? null;
		$schemaRef = $query['schemas'][0] ?? null;
		if ($registerRef === null || $schemaRef === null) {
			throw new InvalidArgumentException('Calendar view requires a register and schema in its query');
		}

		$this->objectService->setRegister(register: $registerRef);
		$this->objectService->setSchema(schema: $schemaRef);

		return $this->buildBaseObjectQuery(query: $query);
	}//end calendarBaseQuery()

	/**
	 * The calendar's object queries: those starting within the range and,
	 * with an end field, those started at/before the range end and still open
	 * at/after the range start.
	 *
	 * @param array<string, mixed> $baseQuery The base object query.
	 * @param array<string, mixed> $calendar  Date fields and range.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function calendarQueries(array $baseQuery, array $calendar): array {
		$starting = $baseQuery;
		$starting[$calendar['dateField']] = ['gte' => $calendar['rangeStart'], 'lte' => $calendar['rangeEnd']];
		if ($calendar['endDateField'] === null) {
			return [$starting];
		}

		$spanning = $baseQuery;
		$spanning[$calendar['dateField']] = ['lte' => $calendar['rangeEnd']];
		$spanning[$calendar['endDateField']] = ['gte' => $calendar['rangeStart']];

		return [$starting, $spanning];
	}//end calendarQueries()

	/**
	 * Run the queries in order and key their results by object identity; a later result replaces an earlier one.
	 *
	 * @param array<int, array<string, mixed>> $queries The object queries.
	 *
	 * @return array<string, mixed>
	 */
	private function mergeByIdentity(array $queries): array {
		$objectsById = [];
		foreach ($queries as $query) {
			foreach (($this->objectService->searchObjectsPaginated(query: $query)['results'] ?? []) as $object) {
				$objectsById[$this->objectIdentity(object: $object)] = $object;
			}
		}

		return $objectsById;
	}//end mergeByIdentity()

	/**
	 * Derive the ordered list of kanban column values.
	 *
	 * Precedence: explicit `columnOrder` > schema enum order > distinct
	 * observed values (discovered via the existing facet/object-query
	 * machinery, never by loading the whole table).
	 *
	 * @param array<string, mixed> $properties The schema's properties
	 * @param string $groupByField The property grouped on
	 * @param array|null $columnOrder Explicit column order, if configured
	 * @param array<string, mixed> $baseQuery Base object query (filters/sort) to scope discovery
	 *
	 * @return array<int, mixed> The ordered column values
	 */
	private function deriveColumnValues(array $properties, string $groupByField, ?array $columnOrder, array $baseQuery): array {
		if ($columnOrder !== null && empty($columnOrder) === false) {
			return array_values($columnOrder);
		}

		$enumValues = $properties[$groupByField]['enum'] ?? null;
		if (is_array($enumValues) === true && empty($enumValues) === false) {
			return array_values($enumValues);
		}

		return $this->discoverDistinctValues(groupByField: $groupByField, baseQuery: $baseQuery);
	}//end deriveColumnValues()

	/**
	 * Discover the distinct observed values of a field via the existing
	 * facet machinery (never a bespoke "SELECT DISTINCT" query, and never a
	 * full-table scan — facets are computed over the existing search index).
	 *
	 * @param string $groupByField The property to discover distinct values for
	 * @param array<string, mixed> $baseQuery Base object query (filters/sort) to scope discovery
	 *
	 * @return array<int, mixed> The distinct observed values
	 */
	private function discoverDistinctValues(string $groupByField, array $baseQuery): array {
		try {
			$facetQuery = $baseQuery;
			$facetQuery['_facets'] = [$groupByField => ['type' => 'terms']];
			$facets = $this->objectService->getFacetsForObjects(query: $facetQuery);
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[ViewPresentationService] Failed to discover distinct kanban column values: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'groupByField' => $groupByField]
			);
			return [];
		}

		return $this->bucketValues(facets: $facets, groupByField: $groupByField);
	}//end discoverDistinctValues()

	/**
	 * The non-null bucket values (or keys) of one field's terms facet.
	 *
	 * @param array<string, mixed> $facets       The facets answer.
	 * @param string               $groupByField The faceted field.
	 *
	 * @return array<int, mixed>
	 */
	private function bucketValues(array $facets, string $groupByField): array {
		$buckets = $facets['facets'][$groupByField]['data']['buckets'] ?? $facets['facets'][$groupByField]['buckets'] ?? [];
		$values = array_map(static fn ($bucket) => $bucket['value'] ?? $bucket['key'] ?? null, $buckets);

		return array_values(array_filter($values, static fn ($value) => $value !== null));
	}//end bucketValues()

	/**
	 * Build the base object-query filters/sort from a view's stored query,
	 * preserved unchanged (REQ-VIEW-KANBAN-02: "filters and sort preserved").
	 *
	 * @param array<string, mixed> $query The view's stored query
	 *
	 * @return array<string, mixed> The base object-query filters/sort
	 */
	private function buildBaseObjectQuery(array $query): array {
		$objectQuery = [];

		if (empty($query['filters']) === false && is_array($query['filters']) === true) {
			$objectQuery = array_merge($objectQuery, $query['filters']);
		}

		if (empty($query['sort']) === false) {
			$objectQuery['_order'] = $query['sort'];
		}

		return $objectQuery;
	}//end buildBaseObjectQuery()

	/**
	 * Derive a stable identity for an object result used to de-duplicate
	 * the starting/spanning calendar query merge.
	 *
	 * @param mixed $object A single result row from searchObjectsPaginated()
	 *
	 * @return string The object's identity key
	 */
	private function objectIdentity(mixed $object): string {
		if (is_array($object) === true) {
			return $this->rowIdentity(row: $object);
		}

		// Nextcloud entities serve get*() through Entity::__call() and declare the
		// accessors only as `@method`, so method_exists($row, 'getId') is FALSE for
		// every ObjectEntity searchObjectsPaginated() hands back. That sent both
		// queries to spl_object_hash(), which is unique per PHP instance — so the
		// same DB row, hydrated once by the "starting" query and once by the
		// "spanning" query, produced TWO keys and rendered twice on the calendar.
		$identity = null;
		if ($object instanceof Entity) {
			$identity = $this->entityIdentity(entity: $object);
		}

		return $identity ?? spl_object_hash((object)$object);
	}//end objectIdentity()

	/**
	 * Identity of an array row: its id, else its uuid, else a hash of the row.
	 *
	 * @param array<mixed> $row The row.
	 *
	 * @return string
	 */
	private function rowIdentity(array $row): string {
		$id = $row['id'] ?? $row['uuid'] ?? null;
		if ($id !== null) {
			return (string) $id;
		}

		return md5(serialize($row));
	}//end rowIdentity()

	/**
	 * Identity of an entity: its uuid, else its id, else none.
	 *
	 * The uuid is read only when property_exists() says the entity has one: the
	 * membership test Entity::getter() itself uses.
	 * uuid is preferred over id so an entity row and an already-rendered array
	 * row of the SAME object collapse to one key: getObject()/jsonSerialize()
	 * publish the uuid under 'id', which is what rowIdentity() reads.
	 *
	 * @param Entity $entity The entity.
	 *
	 * @return string|null
	 */
	private function entityIdentity(Entity $entity): ?string {
		$uuid = null;
		if (property_exists($entity, 'uuid') === true) {
			// A plain Entity declares getUuid() only through __call(); the callable dispatches as a direct call would.
			$uuid = call_user_func([$entity, 'getUuid']);
		}

		if ($uuid !== null && $uuid !== '') {
			return (string) $uuid;
		}

		if ($entity->getId() !== null) {
			return (string) $entity->getId();
		}

		return null;
	}//end entityIdentity()
}//end class
