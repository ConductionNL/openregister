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

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ViewMapper;
use InvalidArgumentException;
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
	 * Constructor.
	 *
	 * @param ViewMapper      $viewMapper     Reads the backing view.
	 * @param RegisterMapper  $registerMapper Resolves the source register.
	 * @param SchemaMapper    $schemaMapper   Resolves the source schema.
	 * @param MagicMapper     $magicMapper    Searches the source table.
	 * @param LoggerInterface $logger         Logs a view that cannot back a type.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ViewMapper $viewMapper,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly MagicMapper $magicMapper,
		private readonly LoggerInterface $logger,
	) {
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
		$rows = $this->findAll(register: $register, schema: $schema, query: ['_ids' => [$id], 'limit' => 1], config: $config);

		return ($rows[0] ?? null);
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
		$source = $this->resolve(schema: $schema, config: $config, query: $query);
		if ($source === null) {
			return [];
		}

		$search = $source['query'];
		if (isset($query['limit']) === true) {
			$search['_limit'] = (int) $query['limit'];
		}

		if (isset($query['offset']) === true) {
			$search['_offset'] = (int) $query['offset'];
		}

		if (isset($query['sort']) === true) {
			$search['_order'] = $query['sort'];
		}

		return $this->magicMapper->searchObjectsInRegisterSchemaTable(
			query: $search,
			register: $source['register'],
			schema: $source['schema']
		);
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
		$source = $this->resolve(schema: $schema, config: $config, query: $query);
		if ($source === null) {
			return 0;
		}

		return $this->magicMapper->countObjectsInRegisterSchemaTable(
			query: $source['query'],
			register: $source['register'],
			schema: $source['schema']
		);
	}//end count()

	/**
	 * The source table and the combined filter, or null when nothing can match.
	 *
	 * @param Schema $schema The view-backed schema.
	 * @param array  $config The object-source config (`view`).
	 * @param array  $query  The caller's query.
	 *
	 * @return array{register: Register, schema: Schema, query: array<string, mixed>}|null
	 */
	private function resolve(Schema $schema, array $config, array $query): ?array {
		try {
			// The schema's author chose the view; readers of the type need not
			// hold a share on it. Their access to the source ROWS is checked by
			// the search below (_rbac on).
			$view = $this->viewMapper->find($config['view'] ?? '', _rbac: false, _multitenancy: false);
			$viewQuery = ($view->getQuery() ?? []);
			$registers = array_values((array) ($viewQuery['registers'] ?? []));
			$schemas = array_values((array) ($viewQuery['schemas'] ?? []));
			if (count($registers) !== 1 || count($schemas) !== 1) {
				throw new InvalidArgumentException('the view must name exactly one register and one schema');
			}

			$sourceRegister = $this->registerMapper->find($registers[0]);
			$sourceSchema = $this->schemaMapper->find($schemas[0]);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[ViewObjectSource] a view-backed schema cannot read its view: ' . $e->getMessage(),
				['file' => __FILE__, 'line' => __LINE__, 'schema' => $schema->getSlug(), 'view' => ($config['view'] ?? null)]
			);
			return null;
		}

		$filter = $this->combine(viewQuery: $viewQuery, query: $query);
		if ($filter === null) {
			return null;
		}

		return ['register' => $sourceRegister, 'schema' => $sourceSchema, 'query' => $filter];
	}//end resolve()

	/**
	 * The view's filters AND the caller's, as a MagicMapper query; null when they exclude each other.
	 *
	 * @param array $viewQuery The view's stored query.
	 * @param array $query     The caller's query.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) One branch per query part; splitting hides the AND.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      One branch per query part; splitting hides the AND.
	 */
	private function combine(array $viewQuery, array $query): ?array {
		$filter = ['_rbac' => true, '_multitenancy' => true];

		// The caller's field filters (top-level keys, as the search query holds them).
		foreach ($query as $key => $value) {
			$key = (string) $key;
			if ($key === '' || $key[0] === '_' || $key[0] === '@' || in_array($key, self::RESERVED, true) === true) {
				continue;
			}

			$filter[$key] = $value;
		}

		if (isset($query['_ids']) === true) {
			$filter['_ids'] = $query['_ids'];
		}

		// The view's facet filters. An empty selection is no filter. A field
		// both name keeps only the values both allow.
		foreach ((array) ($viewQuery['facetFilters'] ?? []) as $field => $values) {
			$values = array_values((array) $values);
			if ($values === []) {
				continue;
			}

			$field = (string) $field;
			if (str_starts_with($field, '@self.') === true) {
				$filter['@self'][substr($field, 6)] = $values;
				continue;
			}

			if (array_key_exists($field, $filter) === true) {
				$values = array_values(array_intersect(array_map('strval', (array) $filter[$field]), array_map('strval', $values)));
				if ($values === []) {
					return null;
				}
			}

			$filter[$field] = $values;
		}//end foreach

		$terms = ($viewQuery['searchTerms'] ?? []);
		if (is_array($terms) === true) {
			$terms = implode(' ', $terms);
		}

		$search = trim((string) ($query['_search'] ?? '') . ' ' . (string) $terms);
		if ($search !== '') {
			$filter['_search'] = $search;
		}

		return $filter;
	}//end combine()
}//end class
