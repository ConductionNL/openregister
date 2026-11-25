<?php

/**
 * Applies `_related` filters to a search query.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Query
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Query;

use InvalidArgumentException;
use OCA\OpenRegister\Exception\ExplicitRevealQueryException;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Schemas\SchemaRefInRegister;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCA\OpenRegister\Support\OrderParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * The one place a `_related` filter becomes SQL on a real query.
 *
 * 🔴 THIS IS THE CALLER THE CLAUSE DID NOT HAVE. `RelatedRowFilterParser` and
 * `RelatedRowExistsClause` were both correct and both unreachable, and a filter
 * with no caller is the same shape as no filter: the query runs, it answers the
 * UNFILTERED set, and nothing anywhere says the narrowing was dropped.
 *
 * 🔑 IT REFUSES RATHER THAN DROPS, ALL THE WAY DOWN. The parser already throws
 * on a malformed block. This adds the two refusals only a live lookup can make:
 * a schema nobody can name, and a schema the caller may not read at all. Both
 * end the query. Skipping either would widen the answer, and wider is the
 * direction that discloses.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
class RelatedRowQueryApplier {

	/**
	 * The alias the list path gives the outer object row, which every clause joins to.
	 */
	public const OUTER_ALIAS = 't';

	/**
	 * The parser, clause and lookups.
	 *
	 * @param RelatedRowLookups $lookups The schema lookup and the register/schema-to-table resolver.
	 * @param IDBConnection     $db      The connection, read for its platform.
	 * @param LoggerInterface   $logger  The logger.
	 * @param RelatedRowAccess  $access  The access rules for the related rows (row predicate, property read
	 *                                   rule, organisation boundary).
	 */
	public function __construct(
		private readonly RelatedRowLookups $lookups,
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
		private readonly RelatedRowAccess $access,
	) {
	}//end __construct()

	/**
	 * Whether the caller may read a property, for a filter that names it (nested-path filters on the outer table).
	 *
	 * @param Schema $schema   The schema the property belongs to.
	 * @param string $property The property name.
	 *
	 * @return bool False when it is withheld.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-a-property-read-rule-holds-on-every-route-that-returns-its-value
	 */
	public function mayReadProperty(Schema $schema, string $property): bool {
		return $this->access->mayReadProperty(schema: $schema, property: $property);
	}//end mayReadProperty()

	/**
	 * Refuse a top-level filter or sort on a property the caller may not read.
	 *
	 * Which rows match, and in which order, would answer what the property read
	 * rule withholds. A query with RBAC off, or a system operation, is not asked.
	 *
	 * @param array<mixed> $query  The search query.
	 * @param Schema       $schema The schema being searched.
	 *
	 * @return void
	 *
	 * @throws ExplicitRevealQueryException When the query filters or sorts on such a property.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-a-property-read-rule-holds-on-every-route-that-returns-its-value
	 */
	public function refuseUnreadableFilterOrSort(array $query, Schema $schema): void {
		$rbac = ($query['_rbac'] ?? true);
		if ($rbac === false || $rbac === 'false' || $rbac === '0' || $rbac === 0
			|| SystemOperationContext::isActive() === true
			|| $schema->hasPropertyAuthorization() === false
		) {
			return;
		}

		$order = (new OrderParameter(order: ($query['_order'] ?? [])))->fields();
		foreach (array_keys($schema->getProperties() ?? []) as $name) {
			$name = (string)$name;
			if (array_key_exists($name, $query) === false && array_key_exists($name, $order) === false) {
				continue;
			}

			if ($this->mayReadProperty(schema: $schema, property: $name) === false) {
				throw new ExplicitRevealQueryException(
					message: sprintf('\'%s\' cannot be filtered or sorted on.', $name)
				);
			}
		}
	}//end refuseUnreadableFilterOrSort()

	/**
	 * Narrow a query by every `_related` block it carries.
	 *
	 * Does nothing at all when there is no `_related` key, so every existing
	 * call site is unaffected.
	 *
	 * A `_ref` block (query-filter-through-a-reference) is read against the
	 * queried schema's own reference properties and becomes the same forward
	 * filter a `_referenced` block makes, so it runs through the same EXISTS
	 * clause under the referenced schema's own access predicate.
	 *
	 * @param RelatedRowFilterTarget $target The query being built, the register the related rows live in and the
	 *                                       organisation scope of a related table.
	 * @param array<mixed>           $query  The request query.
	 * @param Schema|null            $schema The schema being queried, which `_ref` blocks are read against.
	 *
	 * The outer object row is joined as `OUTER_ALIAS`, the alias the list path gives it.
	 *
	 * @return int How many clauses were applied.
	 *
	 * @throws InvalidArgumentException When a block names a schema that cannot be resolved.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 * @spec openspec/changes/query-referenced-schema-rows/specs/zoeken-filteren/spec.md
	 * @spec openspec/changes/query-filter-through-a-reference/specs/zoeken-filteren/spec.md
	 */
	public function apply(RelatedRowFilterTarget $target, array $query, ?Schema $schema = null): int {
		$filters = array_merge(
			$this->filtersIn(query: $query),
			$this->referenceFiltersIn(query: $query, schema: $schema, register: $target->register)
		);
		if ($filters === []) {
			return 0;
		}

		$engine = (new RelatedRowExistsClause())->engineFor(db: $this->db, logger: $this->logger);
		$run    = new RelatedRowApplyRun(qb: $target->qb, register: $target->register, organisationScopeFor: $target->organisationScopeFor, engine: $engine);
		foreach ($filters as $filter) {
			$this->applyOne(run: $run, filter: $filter);
		}

		return $run->applied;
	}//end apply()

	/**
	 * Join the object a reference property points to, for a facet over one of its fields.
	 *
	 * `_facets[_ref][<property>][<field>]` counts the queried objects per value
	 * of `<field>` on the object `<property>` points to. The referenced row is
	 * INNER JOINed under the same join, soft-delete exclusion, access predicate
	 * and organisation scope a `_ref` filter's EXISTS clause carries, so a
	 * referenced object the caller may not read adds no bucket and no count.
	 *
	 * Refuses (HTTP 400) what the filter refuses. A reference property or field
	 * the caller may not read returns null, and the facet is omitted, the way
	 * a facet over an unreadable property of the queried schema is.
	 *
	 * @param RelatedRowFilterTarget $target   The outer query (aliased `t`), its register and scope resolver.
	 * @param Schema                 $schema   The schema being queried.
	 * @param string                 $property The reference property.
	 * @param string                 $field    The field of the referenced schema.
	 *
	 * @return string|null The SQL expression of the referenced field to group by, or null to omit the facet.
	 *
	 * @throws InvalidArgumentException When the property or field is unusable.
	 *
	 * @spec openspec/changes/query-filter-through-a-reference/specs/zoeken-filteren/spec.md
	 */
	public function joinReferenceForFacet(RelatedRowFilterTarget $target, Schema $schema, string $property, string $field): ?string {
		$parser     = new ReferenceFilterParser(resolveSchema: fn (string $name): ?Schema => $this->findSchema(name: $name));
		$referenced = $parser->facetTarget(property: $property, field: $field, schema: $schema);
		$filter     = $this->shapedForProperty(
			schema: $schema,
			filter: new RelatedRowFilter(
				schema: (string)$referenced->getId(),
				foreignKey: $property,
				conditions: [['field' => $field, 'operator' => 'eq', 'value' => null]],
				direction: RelatedRowFilter::DIRECTION_FORWARD
			)
		);

		if ($this->mayReadProperty(schema: $schema, property: $property) === false
			|| $this->mayFacetOn(filter: $filter, schema: $referenced) === false
		) {
			return null;
		}

		$engine   = (new RelatedRowExistsClause())->engineFor(db: $this->db, logger: $this->logger);
		$run      = new RelatedRowApplyRun(
			qb: $target->qb,
			register: $target->register,
			organisationScopeFor: $target->organisationScopeFor,
			engine: $engine
		);
		$register = $this->registerFor(run: $run, filter: $filter);
		if ($this->matchesNothing(run: $run, schema: $referenced, register: $register) === true) {
			return 'NULL';
		}

		$source   = new RelatedRowSource(
			schema: $referenced,
			tableName: $this->lookups->tableHandler->getTableNameForRegisterSchema(register: $register, schema: $referenced),
			alias: 'rf0',
			scope: ($run->organisationScopeFor)($referenced, $register->getId())
		);
		$subquery = $this->subquery(run: $run, source: $source);
		$clause   = new RelatedRowExistsClause();
		$on       = $clause->joinOn(filter: $filter, subquery: $subquery);
		foreach ($on['parameters'] as $name => $value) {
			$target->qb->setParameter($name, $value);
		}

		$target->qb->innerJoin(self::OUTER_ALIAS, $source->tableName, $source->alias, $on['sql']);

		return $clause->innerFieldExpression(subquery: $subquery, field: $field);
	}//end joinReferenceForFacet()

	/**
	 * Whether the caller may count the referenced objects by the filter's field.
	 *
	 * @param RelatedRowFilter $filter The forward filter naming the field.
	 * @param Schema           $schema The referenced schema.
	 *
	 * @return bool
	 */
	private function mayFacetOn(RelatedRowFilter $filter, Schema $schema): bool {
		try {
			$this->access->assertReadable(filter: $filter, schema: $schema);
		} catch (InvalidArgumentException $e) {
			// ExplicitRevealQueryException is one too: a value shown only on request is not counted.
			return false;
		}

		return true;
	}//end mayFacetOn()

	/**
	 * Narrow the run's query by one block: no row at all when its table does not exist yet.
	 *
	 * @param RelatedRowApplyRun $run    The query, register, scope and engine, and the count so far.
	 * @param RelatedRowFilter   $filter The block.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the block names a schema that cannot be resolved.
	 */
	private function applyOne(RelatedRowApplyRun $run, RelatedRowFilter $filter): void {
		$alias = sprintf('rel%d', $run->applied);
		$run->applied++;
		$schema   = $this->resolveSchema(name: $filter->schema);
		$register = $this->registerFor(run: $run, filter: $filter);
		$relatedTable = $this->lookups->tableHandler->getTableNameForRegisterSchema(register: $register, schema: $schema);

		if ($this->matchesNothing(run: $run, schema: $schema, register: $register) === true) {
			return;
		}

		$this->access->assertReadable(filter: $filter, schema: $schema);

		$scope  = ($run->organisationScopeFor)($schema, $register->getId());
		$source = new RelatedRowSource(schema: $schema, tableName: $relatedTable, alias: $alias, scope: $scope);
		$rendered = (new RelatedRowExistsClause())->render(filter: $filter, subquery: $this->subquery(run: $run, source: $source));
		$this->addClause(qb: $run->qb, rendered: $rendered);
	}//end applyOne()

	/**
	 * Narrow the run's query to no row when the schema has no table in the register yet.
	 *
	 * A schema that has never stored an object has no table yet, so no row can
	 * match. Same answer the list path gives for a missing table.
	 *
	 * @param RelatedRowApplyRun $run      The query.
	 * @param Schema             $schema   The related schema.
	 * @param Register           $register The register the related schema is read in.
	 *
	 * @return bool True when the query was narrowed to nothing.
	 */
	private function matchesNothing(RelatedRowApplyRun $run, Schema $schema, Register $register): bool {
		if ($this->lookups->tableHandler->existsTableForRegisterSchema(register: $register, schema: $schema) === true) {
			return false;
		}

		$run->qb->andWhere($run->qb->createFunction('1 = 0'));
		return true;
	}//end matchesNothing()

	/**
	 * The EXISTS subquery over one related table, under that table's own access predicate.
	 *
	 * @param RelatedRowApplyRun $run    The query and engine.
	 * @param RelatedRowSource   $source The related schema, its table, its alias and its organisation scope.
	 *
	 * @return RelatedRowSubquery
	 */
	private function subquery(RelatedRowApplyRun $run, RelatedRowSource $source): RelatedRowSubquery {
		$target = new RelatedRowSqlTarget(
			engine: $run->engine,
			// Raw SQL gets no automatic table prefix; Nextcloud's connection
			// replaces `*PREFIX*` with the instance's (`oc_` by default).
			table: '*PREFIX*'.$source->tableName,
			outerAlias: self::OUTER_ALIAS,
			storage: RelatedRowExistsClause::STORAGE_COLUMNS
		);

		// 🔴 THE ACCESS PREDICATE FOR THE RELATED SCHEMA, NOT THE OUTER ONE.
		// The two schemas have different authorization blocks, and reusing the
		// outer query's predicate would decide who may read case properties by
		// asking who may read cases.
		$inner = new RelatedRowInnerRow(
			alias: $source->alias,
			accessPredicate: $this->access->predicate(qb: $run->qb, source: $source),
			parameterPrefix: $source->alias
		);

		return new RelatedRowSubquery(target: $target, inner: $inner);
	}//end subquery()

	/**
	 * Every `_related` and `_referenced` block in a query, inverse ones first.
	 *
	 * @param array<mixed> $query The request query.
	 *
	 * @return array<int, RelatedRowFilter> The filters.
	 *
	 * @throws InvalidArgumentException When a block is malformed.
	 */
	private function filtersIn(array $query): array {
		$filters = [];
		if (array_key_exists(RelatedRowFilterParser::KEY, $query) === true) {
			$filters = (new RelatedRowFilterParser())->parse($query);
		}

		if (array_key_exists(RelatedRowFilterParser::FORWARD_KEY, $query) === true) {
			$filters = array_merge(
				$filters,
				(new RelatedRowFilterParser(key: RelatedRowFilterParser::FORWARD_KEY))->parse($query)
			);
		}

		return $filters;
	}//end filtersIn()

	/**
	 * Every `_ref` block in a query, read against the queried schema.
	 *
	 * @param array<mixed>  $query    The request query.
	 * @param Schema|null   $schema   The schema being queried.
	 * @param Register|null $register The register the query runs in; a `$ref` slug resolves among its schemas first.
	 *
	 * @return array<int, RelatedRowFilter> The filters.
	 *
	 * @throws InvalidArgumentException When a block is unusable, or there is no schema to read it against.
	 *
	 * @spec openspec/changes/query-filter-through-a-reference/specs/zoeken-filteren/spec.md
	 */
	private function referenceFiltersIn(array $query, ?Schema $schema, ?Register $register = null): array {
		if (array_key_exists(ReferenceFilterParser::KEY, $query) === false) {
			return [];
		}

		// Refuse rather than drop: without the queried schema the block cannot
		// be read, and answering without it would return the unfiltered set.
		if ($schema === null) {
			throw new InvalidArgumentException(
				ReferenceFilterParser::KEY . ' filters need the schema being queried; narrow the query to one schema.'
			);
		}

		$parser  = new ReferenceFilterParser(resolveSchema: fn (string $name): ?Schema => $this->findSchema(name: $name, register: $register));
		$filters = $parser->parse(query: $query, schema: $schema);
		return array_map(
			fn (RelatedRowFilter $filter): RelatedRowFilter => $this->shapedForProperty(schema: $schema, filter: $filter),
			$filters
		);
	}//end referenceFiltersIn()

	/**
	 * The filter shaped by its reference property: through the list, and in the register it names.
	 *
	 * A property holding one uuid joins by equality; a property holding a list
	 * joins by JSON containment, so the object matches when ANY referenced
	 * object in its list satisfies every condition of the block. A property
	 * naming a `register` reads the referenced schema there (D-4); without one
	 * the referenced schema is read in the queried register.
	 *
	 * @param Schema           $schema The schema being queried.
	 * @param RelatedRowFilter $filter The forward filter of one `_ref` block.
	 *
	 * @return RelatedRowFilter
	 *
	 * @spec openspec/changes/query-filter-through-a-reference/specs/zoeken-filteren/spec.md
	 */
	private function shapedForProperty(Schema $schema, RelatedRowFilter $filter): RelatedRowFilter {
		$config = (($schema->getProperties() ?? [])[$filter->foreignKey] ?? []);
		if (is_array($config) === false) {
			return $filter;
		}

		return new RelatedRowFilter(
			schema: $filter->schema,
			foreignKey: $filter->foreignKey,
			conditions: $filter->conditions,
			direction: $filter->direction,
			throughList: (($config['type'] ?? null) === 'array'),
			register: ReferenceFilterParser::registerOf(config: $config)
		);
	}//end shapedForProperty()

	/**
	 * The register a filter's related schema is read in: the one it names, else the queried one.
	 *
	 * 🔴 A REGISTER THAT CANNOT BE FOUND IS REFUSED, NOT REPLACED BY THE QUERIED
	 * ONE. Reading the queried register instead would look the referenced
	 * schema up in a table it does not live in and answer "nothing matches" to
	 * a question that was never asked.
	 *
	 * @param RelatedRowApplyRun $run    The query and the queried register.
	 * @param RelatedRowFilter   $filter The filter.
	 *
	 * @return Register The register.
	 *
	 * @throws InvalidArgumentException When the named register cannot be found.
	 *
	 * @spec openspec/changes/query-filter-through-a-reference/specs/zoeken-filteren/spec.md
	 */
	private function registerFor(RelatedRowApplyRun $run, RelatedRowFilter $filter): Register {
		$named = $filter->register;
		if ($named === null || $named === (string)$run->register->getId()) {
			return $run->register;
		}

		$mapper = $this->lookups->registerMapper;
		if ($mapper === null && ctype_digit($named) === true) {
			$register = new Register();
			$register->setId((int)$named);
			return $register;
		}

		try {
			if ($mapper !== null) {
				return $mapper->find(id: $named);
			}
		} catch (\Throwable $e) {
			$this->logger->debug(
				message: '[RelatedRowQueryApplier] The register a reference property names was not found',
				context: ['register' => $named, 'error' => $e->getMessage()]
			);
		}

		throw new InvalidArgumentException(
			sprintf('The register \'%s\' that property \'%s\' references cannot be found.', $named, $filter->foreignKey)
		);
	}//end registerFor()

	/**
	 * The schema a reference property's `$ref` names (slug, id or uuid), or null when none.
	 *
	 * A slug is looked up among the register's own schemas first: slugs are
	 * unique within a register, not across the instance, so the register the
	 * query runs in answers before the instance-wide lookup does.
	 *
	 * @param string        $name     The identifier.
	 * @param Register|null $register The register the query runs in.
	 *
	 * @return Schema|null The schema.
	 *
	 * @spec openspec/changes/register-scoped-schema-slug-resolution/specs/schema-slug-resolution/spec.md
	 */
	private function findSchema(string $name, ?Register $register = null): ?Schema {
		$scoped = (new SchemaRefInRegister(schemas: $this->lookups->schemaMapper))->findInRegister(schemaRef: $name, register: $register);
		if ($scoped !== null) {
			return $scoped;
		}

		try {
			return $this->resolveSchema(name: $name);
		} catch (InvalidArgumentException $e) {
			// Not a single slug or a numeric id: try it as a uuid below.
		}

		try {
			return $this->lookups->schemaMapper->find(id: $name);
		} catch (\Throwable $e) {
			return null;
		}
	}//end findSchema()

	/**
	 * Bind a rendered clause's parameters, then narrow the query by it.
	 *
	 * @param IQueryBuilder                                         $qb       The query being built.
	 * @param array{sql: string, parameters: array<string, mixed>} $rendered The clause and its bindings.
	 *
	 * @return void
	 */
	private function addClause(IQueryBuilder $qb, array $rendered): void {
		foreach ($rendered['parameters'] as $name => $value) {
			$qb->setParameter($name, $value);
		}

		$qb->andWhere($qb->createFunction($rendered['sql']));
	}//end addClause()

	/**
	 * Resolve the schema a block names, or refuse the whole query.
	 *
	 * 🔑 A SCHEMA NOBODY CAN NAME IS A REFUSAL, NOT A SKIP. Dropping the block
	 * would answer the unfiltered set to a narrow question, which is the exact
	 * failure `RelatedRowFilterParser` was shaped against; making the lookup
	 * lenient here would reintroduce it one layer down.
	 *
	 * @param string $name The schema slug or id from the filter.
	 *
	 * @return Schema The schema.
	 *
	 * @throws InvalidArgumentException When it cannot be resolved.
	 */
	private function resolveSchema(string $name): Schema {
		$bySlug = $this->lookups->schemaMapper->findBySlug(slug: $name, query: new ListQuery(limit: 2));
		if (count($bySlug) === 1) {
			return $bySlug[0];
		}

		if (count($bySlug) > 1) {
			// Two schemas answering one slug is ambiguous, and picking the first
			// would silently filter against whichever happened to be created
			// first.
			throw new InvalidArgumentException(
				sprintf('More than one schema is called \'%s\', so the related-row filter is ambiguous.', $name)
			);
		}

		if (ctype_digit($name) === true) {
			try {
				return $this->lookups->schemaMapper->find(id: (int)$name);
			} catch (\Throwable $e) {
				$this->logger->debug(
					message: '[RelatedRowQueryApplier] No schema with that id',
					context: ['name' => $name, 'error' => $e->getMessage()]
				);
			}
		}

		throw new InvalidArgumentException(
			sprintf('There is no schema called \'%s\' to filter related rows on.', $name)
		);
	}//end resolveSchema()
}//end class
