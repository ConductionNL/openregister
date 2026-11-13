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
use OCA\OpenRegister\Db\Schema;
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
	 * @param RelatedRowFilterTarget $target The query being built, the register the related rows live in and the
	 *                                       organisation scope of a related table.
	 * @param array<mixed>           $query  The request query.
	 *
	 * The outer object row is joined as `OUTER_ALIAS`, the alias the list path gives it.
	 *
	 * @return int How many clauses were applied.
	 *
	 * @throws InvalidArgumentException When a block names a schema that cannot be resolved.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 * @spec openspec/changes/query-referenced-schema-rows/specs/zoeken-filteren/spec.md
	 */
	public function apply(RelatedRowFilterTarget $target, array $query): int {
		$filters = $this->filtersIn(query: $query);
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
		$schema       = $this->resolveSchema(name: $filter->schema);
		$relatedTable = $this->lookups->tableHandler->getTableNameForRegisterSchema(register: $run->register, schema: $schema);

		if ($this->matchesNothing(run: $run, schema: $schema) === true) {
			return;
		}

		$this->access->assertReadable(filter: $filter, schema: $schema);

		$scope  = ($run->organisationScopeFor)($schema, $run->register->getId());
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
	 * @param RelatedRowApplyRun $run    The query and register.
	 * @param Schema             $schema The related schema.
	 *
	 * @return bool True when the query was narrowed to nothing.
	 */
	private function matchesNothing(RelatedRowApplyRun $run, Schema $schema): bool {
		if ($this->lookups->tableHandler->existsTableForRegisterSchema(register: $run->register, schema: $schema) === true) {
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
