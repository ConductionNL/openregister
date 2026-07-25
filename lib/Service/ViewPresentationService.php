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

namespace OCA\OpenRegister\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\View;
use Psr\Log\LoggerInterface;

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
class ViewPresentationService
{

    /**
     * Schema mapper, used to resolve enum order for the groupByField.
     *
     * @var SchemaMapper
     */
    private readonly SchemaMapper $schemaMapper;

    /**
     * Object service, used to run the existing paginated/faceted object query.
     *
     * @var ObjectService
     */
    private readonly ObjectService $objectService;

    /**
     * Logger.
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * Constructor.
     *
     * @param SchemaMapper    $schemaMapper  Schema mapper for enum/property discovery
     * @param ObjectService   $objectService Object service for the paginated object query
     * @param LoggerInterface $logger        Logger for error tracking
     *
     * @return void
     */
    public function __construct(
        SchemaMapper $schemaMapper,
        ObjectService $objectService,
        LoggerInterface $logger
    ) {
        $this->schemaMapper  = $schemaMapper;
        $this->objectService = $objectService;
        $this->logger        = $logger;
    }//end __construct()

    /**
     * Build the kanban board for a view: one column per distinct value of
     * `groupByField`, cards paginated through the existing object query.
     *
     * Column order: `columnOrder` when the view configures one; otherwise
     * the schema's enum order for `groupByField` when it is an enum
     * property; otherwise the distinct values observed via the existing
     * facet/object-query machinery (REQ-VIEW-KANBAN-02).
     *
     * @param View                 $view          The kanban view
     * @param array<string, mixed> $requestParams Request params (_limit/_offset apply per column)
     *
     * @return array{viewType: string, groupByField: string, columns: array<int, array<string, mixed>>}
     *
     * @throws InvalidArgumentException If the view is not a kanban view or its config is incomplete
     *
     * @spec openspec/specs/saved-search-views/spec.md#requirement-kanban-columns-and-cards-req-view-kanban-02
     */
    public function getKanbanBoard(View $view, array $requestParams=[]): array
    {
        $presentation = $view->getPresentation();
        $viewType     = $presentation['viewType'] ?? 'table';
        if ($viewType !== 'kanban') {
            throw new InvalidArgumentException('View is not a kanban view (viewType is "'.$viewType.'")');
        }

        $kanbanConfig = $presentation['kanban'] ?? [];
        $groupByField = $kanbanConfig['groupByField'] ?? null;
        if (is_string($groupByField) === false || $groupByField === '') {
            throw new InvalidArgumentException('Kanban view is missing kanban.groupByField');
        }

        $query       = $view->getQuery() ?? [];
        $registerRef = $query['registers'][0] ?? null;
        $schemaRef   = $query['schemas'][0] ?? null;
        if ($registerRef === null || $schemaRef === null) {
            throw new InvalidArgumentException('Kanban view requires a register and schema in its query');
        }

        $schema     = $this->schemaMapper->find($schemaRef);
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

        $limit  = (int) ($requestParams['_limit'] ?? 20);
        $offset = (int) ($requestParams['_offset'] ?? 0);

        $columns = [];
        foreach ($columnValues as $columnValue) {
            $columnQuery = $baseQuery;
            $columnQuery[$groupByField] = $columnValue;
            $columnQuery['_limit']      = $limit;
            $columnQuery['_offset']     = $offset;

            $result = $this->objectService->searchObjectsPaginated(query: $columnQuery);

            $columns[] = [
                'value'  => $columnValue,
                'cards'  => $result['results'] ?? [],
                'total'  => $result['total'] ?? count($result['results'] ?? []),
                'limit'  => $limit,
                'offset' => $offset,
            ];
        }//end foreach

        return [
            'viewType'     => 'kanban',
            'groupByField' => $groupByField,
            'columns'      => $columns,
        ];
    }//end getKanbanBoard()

    /**
     * Return objects for a calendar view whose `dateField` falls within the
     * visible range, spanning to `endDateField` when configured
     * (REQ-VIEW-CAL-04).
     *
     * @param View                 $view          The calendar view
     * @param string               $rangeStart    Inclusive range start (ISO 8601 date/datetime)
     * @param string               $rangeEnd      Inclusive range end (ISO 8601 date/datetime)
     * @param array<string, mixed> $requestParams Additional request params (reserved for future use)
     *
     * @return array{viewType: string, dateField: string, endDateField: string|null,
     *     rangeStart: string, rangeEnd: string, objects: array<int, mixed>, total: int}
     *
     * @throws InvalidArgumentException If the view is not a calendar view or its config is incomplete
     *
     * @spec openspec/specs/saved-search-views/spec.md#requirement-calendar-plots-objects-by-a-date-field-over-a-range-req-view-cal-04
     */
    public function getCalendarObjects(View $view, string $rangeStart, string $rangeEnd, array $requestParams=[]): array
    {
        // @spec exclude requestParams reserved for future filter passthrough; unused today.
        unset($requestParams);

        $presentation = $view->getPresentation();
        $viewType     = $presentation['viewType'] ?? 'table';
        if ($viewType !== 'calendar') {
            throw new InvalidArgumentException('View is not a calendar view (viewType is "'.$viewType.'")');
        }

        $calendarConfig = $presentation['calendar'] ?? [];
        $dateField      = $calendarConfig['dateField'] ?? null;
        if (is_string($dateField) === false || $dateField === '') {
            throw new InvalidArgumentException('Calendar view is missing calendar.dateField');
        }

        $endDateField = $calendarConfig['endDateField'] ?? null;
        if ($endDateField !== null && (is_string($endDateField) === false || $endDateField === '')) {
            $endDateField = null;
        }

        $query       = $view->getQuery() ?? [];
        $registerRef = $query['registers'][0] ?? null;
        $schemaRef   = $query['schemas'][0] ?? null;
        if ($registerRef === null || $schemaRef === null) {
            throw new InvalidArgumentException('Calendar view requires a register and schema in its query');
        }

        $this->objectService->setRegister(register: $registerRef);
        $this->objectService->setSchema(schema: $schemaRef);

        $baseQuery = $this->buildBaseObjectQuery(query: $query);

        // Objects that start within the visible range.
        $startingQuery = $baseQuery;
        $startingQuery[$dateField] = [
            'gte' => $rangeStart,
            'lte' => $rangeEnd,
        ];
        $startingResult            = $this->objectService->searchObjectsPaginated(query: $startingQuery);

        $objectsById = [];
        foreach (($startingResult['results'] ?? []) as $object) {
            $objectsById[$this->objectIdentity(object: $object)] = $object;
        }

        // Spanning objects: started at/before the range end and still open at/after the range start.
        if ($endDateField !== null) {
            $spanningQuery = $baseQuery;
            $spanningQuery[$dateField]    = ['lte' => $rangeEnd];
            $spanningQuery[$endDateField] = ['gte' => $rangeStart];
            $spanningResult = $this->objectService->searchObjectsPaginated(query: $spanningQuery);
            foreach (($spanningResult['results'] ?? []) as $object) {
                $objectsById[$this->objectIdentity(object: $object)] = $object;
            }
        }

        return [
            'viewType'     => 'calendar',
            'dateField'    => $dateField,
            'endDateField' => $endDateField,
            'rangeStart'   => $rangeStart,
            'rangeEnd'     => $rangeEnd,
            'objects'      => array_values($objectsById),
            'total'        => count($objectsById),
        ];
    }//end getCalendarObjects()

    /**
     * Derive the ordered list of kanban column values.
     *
     * Precedence: explicit `columnOrder` > schema enum order > distinct
     * observed values (discovered via the existing facet/object-query
     * machinery, never by loading the whole table).
     *
     * @param array<string, mixed> $properties   The schema's properties
     * @param string               $groupByField The property grouped on
     * @param array|null           $columnOrder  Explicit column order, if configured
     * @param array<string, mixed> $baseQuery    Base object query (filters/sort) to scope discovery
     *
     * @return array<int, mixed> The ordered column values
     */
    private function deriveColumnValues(array $properties, string $groupByField, ?array $columnOrder, array $baseQuery): array
    {
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
     * @param string               $groupByField The property to discover distinct values for
     * @param array<string, mixed> $baseQuery    Base object query (filters/sort) to scope discovery
     *
     * @return array<int, mixed> The distinct observed values
     */
    private function discoverDistinctValues(string $groupByField, array $baseQuery): array
    {
        try {
            $facetQuery            = $baseQuery;
            $facetQuery['_facets'] = [$groupByField => ['type' => 'terms']];
            $facets = $this->objectService->getFacetsForObjects(query: $facetQuery);
        } catch (\Throwable $e) {
            $this->logger->error(
                message: '[ViewPresentationService] Failed to discover distinct kanban column values: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__, 'groupByField' => $groupByField]
            );
            return [];
        }

        $buckets = $facets['facets'][$groupByField]['data']['buckets'] ?? $facets['facets'][$groupByField]['buckets'] ?? [];

        $values = [];
        foreach ($buckets as $bucket) {
            $value = $bucket['value'] ?? $bucket['key'] ?? null;
            if ($value !== null) {
                $values[] = $value;
            }
        }

        return $values;
    }//end discoverDistinctValues()

    /**
     * Build the base object-query filters/sort from a view's stored query,
     * preserved unchanged (REQ-VIEW-KANBAN-02: "filters and sort preserved").
     *
     * @param array<string, mixed> $query The view's stored query
     *
     * @return array<string, mixed> The base object-query filters/sort
     */
    private function buildBaseObjectQuery(array $query): array
    {
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
    private function objectIdentity(mixed $object): string
    {
        if (is_array($object) === true) {
            $id = $object['id'] ?? $object['uuid'] ?? null;
            if ($id !== null) {
                return (string) $id;
            }

            return md5(serialize($object));
        }

        if (is_object($object) === true && method_exists($object, 'getId') === true) {
            return (string) $object->getId();
        }

        return spl_object_hash((object) $object);
    }//end objectIdentity()
}//end class
