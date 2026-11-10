<?php

/**
 * The `view` object-source provider: a saved view's rows as a record type.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/modelling-query-backed-type/specs/saved-search-views/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use InvalidArgumentException;
use OCA\OpenRegister\Db\MagicMapper\MagicTableRowReaderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Serves a schema that declares `x-openregister-view` from the view's source table.
 *
 * The view names one source register and one source schema. Its facet filters
 * and search terms become the query; a caller's own filters narrow it further
 * and never widen it. The reader's access to the source rows still applies
 * (`_rbac` stays on), so a type never shows a row its source would hide.
 *
 * Read-only: Schema::getObjectSource() declares it so, and ObjectService refuses
 * writes to it with ReadOnlyTypeException (405).
 *
 * @spec openspec/changes/modelling-query-backed-type/specs/saved-search-views/spec.md#requirement-req-qtype-001-a-saved-view-can-back-a-read-only-record-type
 */
class ViewObjectSourceProvider implements ObjectSourceProvider {

	/**
	 * Query keys that are paging or structure, never a field filter.
	 *
	 * @var array<int, string>
	 */
	private const RESERVED = ['limit', 'offset', 'page', 'sort', 'filters', 'extend', 'fields', 'register', 'schema'];

	/**
	 * Searches and counts the source table's rows.
	 *
	 * @var MagicTableRowReaderInterface
	 */
	private readonly MagicTableRowReaderInterface $rows;

	/**
	 * Constructor.
	 *
	 * @param ViewSourceMappers $mappers The view mapper and the schema catalog of the source tables.
	 * @param LoggerInterface   $logger  Logs a view that cannot back a type.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ViewSourceMappers $mappers,
		private readonly LoggerInterface $logger,
	) {
		$this->rows = $mappers->catalog->tables();
	}//end __construct()

	/**
	 * The provider id a schema's object source names.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/modelling-query-backed-type/specs/saved-search-views/spec.md#requirement-req-qtype-001-a-saved-view-can-back-a-read-only-record-type
	 */
	public function getId(): string {
		return 'view';
	}//end getId()

	/**
	 * Always available: it reads OpenRegister's own tables.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/modelling-query-backed-type/specs/saved-search-views/spec.md#requirement-req-qtype-001-a-saved-view-can-back-a-read-only-record-type
	 */
	public function isEnabled(): bool {
		return true;
	}//end isEnabled()

	/**
	 * One row of the view by uuid, or null when the view does not hold it.
	 *
	 * @param Register $register The view-backed type's register (unused: the view names its source).
	 * @param Schema   $schema   The view-backed schema.
	 * @param string   $id       The object uuid.
	 * @param array    $config   The object-source config (`view`).
	 *
	 * @return ObjectEntity|null
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The interface fixes the signature.
	 * @SuppressWarnings(PHPMD.ShortVariable)         $id is the interface's name.
	 *
	 * @spec openspec/changes/modelling-query-backed-type/specs/saved-search-views/spec.md#requirement-req-qtype-001-a-saved-view-can-back-a-read-only-record-type
	 */
	public function find(Register $register, Schema $schema, string $id, array $config = []): ?ObjectEntity {
		return ($this->findAll(register: $register, schema: $schema, query: ['_ids' => [$id], 'limit' => 1], config: $config)[0] ?? null);
	}//end find()

	/**
	 * The rows the view's query finds, narrowed by the caller's filters.
	 *
	 * @param Register $register The view-backed type's register (unused: the view names its source).
	 * @param Schema   $schema   The view-backed schema.
	 * @param array    $query    The caller's query (filters, limit, offset, sort).
	 * @param array    $config   The object-source config (`view`).
	 *
	 * @return ObjectEntity[]
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The interface fixes the signature.
	 *
	 * @spec openspec/changes/modelling-query-backed-type/specs/saved-search-views/spec.md#requirement-req-qtype-001-a-saved-view-can-back-a-read-only-record-type
	 */
	public function findAll(Register $register, Schema $schema, array $query = [], array $config = []): array {
		return $this->readRows(request: new ViewRowsRequest(schema: $schema, query: $query, config: $config));
	}//end findAll()

	/**
	 * How many rows the view's query finds, narrowed by the caller's filters.
	 *
	 * @param Register $register The view-backed type's register (unused).
	 * @param Schema   $schema   The view-backed schema.
	 * @param array    $query    The caller's query.
	 * @param array    $config   The object-source config (`view`).
	 *
	 * @return int
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The interface fixes the signature.
	 *
	 * @spec openspec/changes/modelling-query-backed-type/specs/saved-search-views/spec.md#requirement-req-qtype-001-a-saved-view-can-back-a-read-only-record-type
	 */
	public function count(Register $register, Schema $schema, array $query = [], array $config = []): int {
		return $this->countRows(request: new ViewRowsRequest(schema: $schema, query: $query, config: $config));
	}//end count()

	/**
	 * The rows a request finds in the view's source table, with the caller's paging and sort.
	 *
	 * @param ViewRowsRequest $request The schema, caller query and view binding.
	 *
	 * @return ObjectEntity[]
	 */
	private function readRows(ViewRowsRequest $request): array {
		$source = $this->resolve(request: $request);
		if ($source === null) {
			return [];
		}

		return $this->rows->searchObjectsInRegisterSchemaTable(
			query: self::withPaging(search: $source['query'], query: $request->query),
			register: $source['register'],
			schema: $source['schema']
		);
	}//end readRows()

	/**
	 * Add the caller's limit, offset and sort to a source-table search.
	 *
	 * @param array<string, mixed> $search The combined filter.
	 * @param array                $query  The caller's query.
	 *
	 * @return array<string, mixed>
	 */
	private static function withPaging(array $search, array $query): array {
		if (isset($query['limit']) === true) {
			$search['_limit'] = (int) $query['limit'];
		}

		if (isset($query['offset']) === true) {
			$search['_offset'] = (int) $query['offset'];
		}

		if (isset($query['sort']) === true) {
			$search['_order'] = $query['sort'];
		}

		return $search;
	}//end withPaging()

	/**
	 * How many rows a request finds in the view's source table.
	 *
	 * @param ViewRowsRequest $request The schema, caller query and view binding.
	 *
	 * @return int
	 */
	private function countRows(ViewRowsRequest $request): int {
		$source = $this->resolve(request: $request);
		if ($source === null) {
			return 0;
		}

		return $this->rows->countObjectsInRegisterSchemaTable(
			query: $source['query'],
			register: $source['register'],
			schema: $source['schema']
		);
	}//end countRows()

	/**
	 * The source table and the combined filter, or null when nothing can match.
	 *
	 * @param ViewRowsRequest $request The schema, caller query and view binding.
	 *
	 * @return array{register: Register, schema: Schema, query: array<string, mixed>}|null
	 */
	private function resolve(ViewRowsRequest $request): ?array {
		try {
			// The schema's author chose the view; readers of the type need not
			// hold a share on it. Their access to the source ROWS is checked by
			// the search below (_rbac on).
			[$viewQuery, $sourceRegister, $sourceSchema] = $this->readView(config: $request->config);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[ViewObjectSource] a view-backed schema cannot read its view: ' . $e->getMessage(),
				['file' => __FILE__, 'line' => __LINE__, 'schema' => $request->schema->getSlug(), 'view' => ($request->config['view'] ?? null)]
			);
			return null;
		}

		$filter = $this->combine(viewQuery: $viewQuery, query: $request->query);
		if ($filter === null) {
			return null;
		}

		return ['register' => $sourceRegister, 'schema' => $sourceSchema, 'query' => $filter];
	}//end resolve()

	/**
	 * Read the view and the one register and schema it names.
	 *
	 * @param array $config The object-source config (`view`).
	 *
	 * @return array{0: array<string, mixed>, 1: Register, 2: Schema} [view query, source register, source schema].
	 *
	 * @throws Throwable When the view cannot be read or does not name exactly one register and one schema.
	 */
	private function readView(array $config): array {
		$view = $this->mappers->viewMapper->find($config['view'] ?? '', _rbac: false, _multitenancy: false);
		$viewQuery = ($view->getQuery() ?? []);
		$registers = array_values((array) ($viewQuery['registers'] ?? []));
		$schemas = array_values((array) ($viewQuery['schemas'] ?? []));
		if (count($registers) !== 1 || count($schemas) !== 1) {
			throw new InvalidArgumentException('the view must name exactly one register and one schema');
		}

		$sourceRegister = $this->mappers->catalog->registers()->find($registers[0]);
		$sourceSchema = $this->mappers->catalog->schemas()->find($schemas[0]);

		return [$viewQuery, $sourceRegister, $sourceSchema];
	}//end readView()

	/**
	 * The view's filters AND the caller's, as a MagicMapper query; null when they exclude each other.
	 *
	 * The search always runs with `_rbac` and `_multitenancy` on: a caller key
	 * starting with `_` or `@` never reaches the filter, so it cannot turn them off.
	 *
	 * @param array $viewQuery The view's stored query.
	 * @param array $query     The caller's query.
	 *
	 * @return array<string, mixed>|null
	 */
	private function combine(array $viewQuery, array $query): ?array {
		$filter = self::withCallerFilters(filter: ['_rbac' => true, '_multitenancy' => true], query: $query);

		$filter = self::withFacetFilters(filter: $filter, viewQuery: $viewQuery);
		if ($filter === null) {
			return null;
		}

		return self::withSearch(filter: $filter, search: self::searchText(viewQuery: $viewQuery, query: $query));
	}//end combine()

	/**
	 * Add the caller's field filters (top-level keys, as the search query holds them) and `_ids`.
	 *
	 * @param array<string, mixed> $filter The filter so far.
	 * @param array                $query  The caller's query.
	 *
	 * @return array<string, mixed>
	 */
	private static function withCallerFilters(array $filter, array $query): array {
		foreach ($query as $key => $value) {
			$key = (string) $key;
			if (self::isCallerField(key: $key) === false) {
				continue;
			}

			$filter[$key] = $value;
		}

		if (isset($query['_ids']) === true) {
			$filter['_ids'] = $query['_ids'];
		}

		return $filter;
	}//end withCallerFilters()

	/**
	 * Whether a caller query key is a field filter: not empty, not `_`/`@`-prefixed, not reserved.
	 *
	 * @param string $key The caller query key.
	 *
	 * @return bool
	 */
	private static function isCallerField(string $key): bool {
		return ($key === '' || $key[0] === '_' || $key[0] === '@' || in_array($key, self::RESERVED, true) === true) === false;
	}//end isCallerField()

	/**
	 * Add the view's facet filters; an empty selection is no filter.
	 *
	 * @param array<string, mixed> $filter    The filter so far.
	 * @param array                $viewQuery The view's stored query.
	 *
	 * @return array<string, mixed>|null Null when a facet and a caller filter exclude each other.
	 */
	private static function withFacetFilters(array $filter, array $viewQuery): ?array {
		foreach ((array) ($viewQuery['facetFilters'] ?? []) as $field => $values) {
			$values = array_values((array) $values);
			if ($values === []) {
				continue;
			}

			$filter = self::withFacet(filter: $filter, facet: new ViewFacet(field: (string) $field, values: $values));
			if ($filter === null) {
				return null;
			}
		}

		return $filter;
	}//end withFacetFilters()

	/**
	 * Add one non-empty facet: `@self.` facets nest; a field both name keeps only the values both allow.
	 *
	 * @param array<string, mixed> $filter The filter so far.
	 * @param ViewFacet            $facet  The facet field and its values.
	 *
	 * @return array<string, mixed>|null Null when no value is allowed by both.
	 */
	private static function withFacet(array $filter, ViewFacet $facet): ?array {
		$field  = $facet->field;
		$values = $facet->values;
		if (str_starts_with($field, '@self.') === true) {
			$filter['@self'][substr($field, 6)] = $values;
			return $filter;
		}

		if (array_key_exists($field, $filter) === true) {
			$values = array_values(array_intersect(array_map('strval', (array) $filter[$field]), array_map('strval', $values)));
			if ($values === []) {
				return null;
			}
		}

		$filter[$field] = $values;
		return $filter;
	}//end withFacet()

	/**
	 * The caller's `_search` and the view's search terms as one search text.
	 *
	 * @param array $viewQuery The view's stored query.
	 * @param array $query     The caller's query.
	 *
	 * @return string The trimmed search text, possibly empty.
	 */
	private static function searchText(array $viewQuery, array $query): string {
		$terms = ($viewQuery['searchTerms'] ?? []);
		if (is_array($terms) === true) {
			$terms = implode(' ', $terms);
		}

		return trim((string) ($query['_search'] ?? '') . ' ' . (string) $terms);
	}//end searchText()

	/**
	 * Add the search text as `_search`, when not empty.
	 *
	 * @param array<string, mixed> $filter The filter so far.
	 * @param string               $search The combined search text.
	 *
	 * @return array<string, mixed>
	 */
	private static function withSearch(array $filter, string $search): array {
		if ($search !== '') {
			$filter['_search'] = $search;
		}

		return $filter;
	}//end withSearch()
}//end class
