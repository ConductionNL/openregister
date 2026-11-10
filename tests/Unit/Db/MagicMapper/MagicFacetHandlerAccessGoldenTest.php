<?php

/**
 * Golden pin of the access decisions in MagicFacetHandler.
 *
 * A facet hands back the distinct values of a column, so it is a read of that
 * column. This pins, on the code as it stood before the unit refactor:
 * - which properties are offered or omitted by the property read rule
 *   (readable, withheld, explicit-reveal, ungoverned, no container, a rule that
 *   throws), on the single-table and the union path, explicit and `extend`;
 * - that the rule is asked BEFORE any query runs for the property;
 * - that every facet query is scoped through MagicSearchHandler (the RBAC and
 *   organisation filters), with the base query passed unchanged, `_rbac` and
 *   `_multitenancy` included and `_facets` stripped;
 * - that a `_related` filter on a union is refused before any query.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicReadTable;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\MagicMapper\WhereClauseRequest;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\RelatedRowFilterNotSupportedException;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\PropertyRbacHandlerInterface;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicFacetFieldLabel
 * @uses   \OCA\OpenRegister\Db\MagicMapper\TableTermsFacet
 * @uses   \OCA\OpenRegister\Db\MagicMapper\TermsFacetUnion
 * @uses   \OCA\OpenRegister\Db\MagicMapper\DeletedLens
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Rbac\ExplicitReveal
 */
class MagicFacetHandlerAccessGoldenTest extends TestCase {

	/**
	 * Everything the handler did, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $events = [];

	/**
	 * The schema used by the single-table cases.
	 *
	 * `salary` is withheld by the rule, `secret` is explicit-reveal, `name` and
	 * `born` are readable.
	 *
	 * @return Schema The schema.
	 */
	private function governedSchema(): Schema {
		$schema = new Schema();
		$schema->setProperties(
			[
				'name' => ['type' => 'string', 'facetable' => true],
				'salary' => ['type' => 'number', 'facetable' => true, 'authorization' => ['read' => ['hr']]],
				'secret' => ['type' => 'string', 'facetable' => true, 'authorization' => ['reveal' => 'explicit']],
				'born' => ['type' => 'string', 'format' => 'date', 'facetable' => true],
			]
		);

		return $schema;
	}//end governedSchema()

	/**
	 * A schema that governs nothing at property level.
	 *
	 * @return Schema The schema.
	 */
	private function openSchema(): Schema {
		$schema = new Schema();
		$schema->setProperties(
			[
				'name' => ['type' => 'string', 'facetable' => true],
				'salary' => ['type' => 'number', 'facetable' => true],
			]
		);

		return $schema;
	}//end openSchema()

	/**
	 * A query builder that records every call and answers with the given rows.
	 *
	 * @param string $origin Where the builder came from.
	 * @param array  $rows   The rows the executed query fetches.
	 *
	 * @return IQueryBuilder The builder.
	 */
	private function queryBuilder(string $origin, array $rows): IQueryBuilder {
		$qb   = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['selectAlias', 'addSelect', 'from', 'groupBy', 'orderBy', 'addOrderBy', 'setMaxResults', 'andWhere'] as $method) {
			$qb->method($method)->willReturnCallback(
				function (...$args) use ($qb, $origin, $method): IQueryBuilder {
					$this->events[] = [$origin, $method, array_map(static fn ($arg) => match (is_object($arg)) {
						true => (string)$arg,
						default => $arg,
					}, $args)];
					return $qb;
				}
			);
		}

		$qb->method('createFunction')->willReturnCallback(
			function (string $sql): IQueryFunction {
				$function = $this->createMock(IQueryFunction::class);
				$function->method('__toString')->willReturn($sql);
				return $function;
			}
		);
		$qb->method('createNamedParameter')->willReturn(':param');
		$expr->method('isNotNull')->willReturnCallback(static fn (string $column): string => "{$column} IS NOT NULL");
		$expr->method('eq')->willReturnCallback(static fn ($a, $b): string => "{$a} = {$b}");
		$qb->method('expr')->willReturn($expr);
		$qb->method('executeQuery')->willReturnCallback(
			function () use ($origin, $rows): IResult {
				$this->events[] = [$origin, 'executeQuery'];
				$rows[] = false;
				$result = $this->createMock(IResult::class);
				$result->method('fetch')->willReturnOnConsecutiveCalls(...$rows);
				return $result;
			}
		);

		return $qb;
	}//end queryBuilder()

	/**
	 * A handler over a recording world.
	 *
	 * @param array $world rule (property => bool|'throw'), container (bool), search (bool),
	 *                     columns (table => columns), conditions (table => list), rows, dateRows.
	 *
	 * @return MagicFacetHandler The handler.
	 */
	private function handler(array $world): MagicFacetHandler {
		$rule = ($world['rule'] ?? []);
		$rbac = $this->createMock(PropertyRbacHandler::class);
		$rbac->method('canReadProperty')->willReturnCallback(
			function (Schema $schema, string $property, array $object) use ($rule): bool {
				$this->events[] = ['ask', $property, $object];
				if (($rule[$property] ?? true) === 'throw') {
					throw new RuntimeException('rule unavailable');
				}

				return ($rule[$property] ?? true);
			}
		);

		$container = null;
		if (($world['container'] ?? true) === true) {
			$container = $this->createMock(ContainerInterface::class);
			$container->method('get')->willReturnCallback(
				function (string $id) use ($rbac) {
					if ($id === PropertyRbacHandlerInterface::class) {
						return $rbac;
					}

					throw new RuntimeException('not wired: ' . $id);
				}
			);
		}

		$columns = ($world['columns'] ?? []);
		$db      = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($this->createMock(PostgreSQLPlatform::class));
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->queryBuilder('db', []));
		$db->method('prepare')->willReturnCallback(
			function (string $sql) use ($columns, $world): IPreparedStatement {
				$statement = $this->createMock(IPreparedStatement::class);
				if (str_contains($sql, 'information_schema') === true) {
					$statement->method('execute')->willReturnCallback(
						function (array $params) use ($statement, $columns): IResult {
							$rows   = array_map(static fn (string $col): array => ['col' => $col], ($columns[$params[0]] ?? []));
							$rows[] = false;
							$statement->method('fetch')->willReturnOnConsecutiveCalls(...$rows);
							return $this->createMock(IResult::class);
						}
					);
					return $statement;
				}

				$this->events[] = ['sql', preg_replace('/\s+/', ' ', $sql)];
				$rows   = ($world['rows'] ?? []);
				$rows[] = false;
				$statement->method('execute')->willReturn($this->createMock(IResult::class));
				$statement->method('fetch')->willReturnOnConsecutiveCalls(...$rows);
				return $statement;
			}
		);

		$search = null;
		if (($world['search'] ?? true) === true) {
			$search = $this->searchHandler(world: $world);
		}

		return new MagicFacetHandler($db, new NullLogger(), $search, $container);
	}//end handler()

	/**
	 * A search handler that records the scoping it is asked for.
	 *
	 * @param array $world The world, see handler().
	 *
	 * @return MagicSearchHandler The search handler.
	 */
	private function searchHandler(array $world): MagicSearchHandler {
		$search = $this->createMock(MagicSearchHandler::class);
		$search->method('refuseRelatedRowFilters')->willReturnCallback(
			function (array $query): void {
				$this->events[] = ['refuseRelatedRowFilters', array_keys($query)];
				if (array_key_exists('_related', $query) === true) {
					throw new RelatedRowFilterNotSupportedException('refused');
				}
			}
		);
		$search->method('buildFilteredQuery')->willReturnCallback(
			function (array $query, MagicReadTable $table) use ($world): IQueryBuilder {
				$this->events[] = ['buildFilteredQuery', $table->tableName, $table->registerId, $query];
				return $this->queryBuilder('scoped', ($world['qbRows'] ?? []));
			}
		);
		$search->method('buildWhereClause')->willReturnCallback(
			function (WhereClauseRequest $request) use ($world): array {
				$this->events[] = ['buildWhereConditionsSql', $request->table->tableName, $request->table->registerId, $request->query];
				return ($world['conditions'][$request->table->tableName] ?? []);
			}
		);

		return $search;
	}//end searchHandler()

	/**
	 * A register with an id.
	 *
	 * @return Register The register.
	 */
	private function register(): Register {
		$register = new Register();
		$register->setId(7);

		return $register;
	}//end register()

	/**
	 * The facets without the timing block.
	 *
	 * @param array $facets The facets.
	 *
	 * @return array The facets.
	 */
	private function withoutMetrics(array $facets): array {
		unset($facets['_metrics']);
		return $facets;
	}//end withoutMetrics()

	/**
	 * Run one single-table case.
	 *
	 * @param array  $world  The world.
	 * @param array  $query  The query.
	 * @param Schema $schema The schema.
	 *
	 * @return array The facets and the events.
	 */
	private function single(array $world, array $query, Schema $schema): array {
		$this->events = [];
		$facets       = $this->handler(world: $world)->getSimpleFacets(
			tableName: 'openregister_table_7_1',
			query: $query,
			register: $this->register(),
			schema: $schema
		);

		return ['facets' => $this->withoutMetrics(facets: $facets), 'events' => $this->events];
	}//end single()

	/**
	 * Run one union case.
	 *
	 * @param array $world The world.
	 * @param array $query The query.
	 *
	 * @return array The facets (or the refusal) and the events.
	 */
	private function union(array $world, array $query): array {
		$this->events = [];
		$tables       = [
			['tableName' => 'openregister_table_7_1', 'register' => $this->register(), 'schema' => $this->governedSchema()],
			['tableName' => 'openregister_table_7_2', 'register' => $this->register(), 'schema' => $this->openSchema()],
		];
		try {
			$facets = $this->handler(world: $world)->getSimpleFacetsUnion(tableConfigs: $tables, query: $query);
			return ['facets' => $this->withoutMetrics(facets: $facets), 'events' => $this->events];
		} catch (RelatedRowFilterNotSupportedException $e) {
			return ['refused' => $e::class, 'events' => $this->events];
		}
	}//end union()

	/**
	 * The columns both tables have.
	 *
	 * @return array<string, array<int, string>> Columns by table.
	 */
	private function columns(): array {
		$columns = ['name', 'salary', 'secret', 'born', '_owner', '_created'];
		return ['oc_openregister_table_7_1' => $columns, 'oc_openregister_table_7_2' => $columns];
	}//end columns()

	/**
	 * Every case, by name.
	 *
	 * @return array<string, array> The outcomes.
	 */
	private function outcomes(): array {
		$terms    = ['type' => 'terms'];
		$rows     = [['facet_value' => 'a', 'date_key' => '2025', 'doc_count' => 2]];
		$readable = ['rule' => ['salary' => false], 'columns' => $this->columns(), 'qbRows' => $rows, 'rows' => $rows];
		$born     = ['type' => 'date_histogram', 'interval' => 'year'];
		$explicit = ['_facets' => ['salary' => $terms, 'secret' => $terms, 'name' => $terms, 'born' => $born], 'status' => 'open'];
		$governed = $this->governedSchema();
		$open     = $this->openSchema();
		$noRule   = ['container' => false] + $readable;
		$filtered = ['conditions' => ['openregister_table_7_1' => ['1=0'], 'openregister_table_7_2' => ["_organisation = 'org-1'"]]] + $readable;

		return [
			'explicit facets, salary withheld' => $this->single($readable, $explicit, $governed),
			'explicit facets, salary readable' => $this->single(['rule' => ['salary' => true]] + $readable, $explicit, $governed),
			'rbac and multitenancy off pass through' => $this->single($readable, ['_rbac' => false, '_multitenancy' => false] + $explicit, $governed),
			'extend, salary withheld' => $this->single($readable, ['_facets' => 'extend'], $governed),
			'list config, salary withheld' => $this->single($readable, ['_facets' => ['salary', 'name']], $governed),
			'rule throws' => $this->single(['rule' => ['salary' => 'throw', 'name' => 'throw']] + $readable, $explicit, $governed),
			'no container, governed schema' => $this->single($noRule, $explicit, $governed),
			'no container, open schema' => $this->single($noRule, ['_facets' => ['salary' => $terms, 'name' => $terms]], $open),
			'no search handler, open schema' => $this->single(['search' => false] + $readable, ['_facets' => ['name' => $terms], 'status' => 'open'], $open),
			'union, salary withheld on one schema' => $this->union($readable, $explicit),
			'union, every schema readable' => $this->union(['rule' => ['salary' => true]] + $readable, $explicit),
			'union, extend' => $this->union($readable, ['_facets' => 'extend', '_rbac' => false]),
			'union, a table ruled out by its filters' => $this->union($filtered, $explicit),
			'union, related filter refused first' => $this->union($readable, ['_related' => ['x'], '_facets' => ['name' => $terms]]),
		];
	}//end outcomes()

	/**
	 * Every case matches the recorded outcome of the code before the refactor.
	 *
	 * @return void
	 */
	public function testAccessDecisionsMatchGolden(): void {
		$fixture = __DIR__ . '/fixtures/facet-access-golden.json';
		$actual  = json_decode((string)json_encode($this->outcomes()), true);

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents($fixture, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string)file_get_contents($fixture), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, ($actual[$name] ?? null), $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testAccessDecisionsMatchGolden()

	/**
	 * The withheld, explicit-reveal and readable properties, spelled out.
	 *
	 * The golden file says the same; this says it where a reader sees it.
	 *
	 * @return void
	 */
	public function testWithheldPropertiesAreOmittedAndReadableOnesReturned(): void {
		$outcomes = $this->outcomes();

		$withheld = $outcomes['explicit facets, salary withheld']['facets'];
		$this->assertArrayNotHasKey('salary', $withheld);
		$this->assertArrayNotHasKey('secret', $withheld);
		$this->assertArrayHasKey('name', $withheld);
		$this->assertArrayHasKey('born', $withheld);

		$this->assertArrayHasKey('salary', $outcomes['explicit facets, salary readable']['facets']);
		$this->assertArrayNotHasKey('secret', $outcomes['explicit facets, salary readable']['facets']);
		$this->assertArrayNotHasKey('salary', $outcomes['extend, salary withheld']['facets']);
		$this->assertArrayHasKey('name', $outcomes['extend, salary withheld']['facets']);
		$this->assertArrayNotHasKey('salary', $outcomes['list config, salary withheld']['facets']);
		$this->assertSame(
			['born'],
			array_keys($outcomes['rule throws']['facets']),
			'A rule that throws fails closed for the properties it was asked about.'
		);
		$this->assertSame([], $outcomes['no container, governed schema']['facets']);
		$this->assertArrayHasKey('salary', $outcomes['no container, open schema']['facets']);
		$this->assertArrayNotHasKey('salary', $outcomes['union, salary withheld on one schema']['facets']);
		$this->assertArrayHasKey('name', $outcomes['union, salary withheld on one schema']['facets']);
		$this->assertArrayHasKey('salary', $outcomes['union, every schema readable']['facets']);
		$this->assertArrayNotHasKey('salary', $outcomes['union, extend']['facets']);
		$this->assertArrayHasKey('refused', $outcomes['union, related filter refused first']);
	}//end testWithheldPropertiesAreOmittedAndReadableOnesReturned()

	/**
	 * The rule is asked before a query runs, and a refused property runs none.
	 *
	 * @return void
	 */
	public function testTheRuleIsAskedBeforeTheQueryAndScopesIt(): void {
		$events = $this->outcomes()['explicit facets, salary withheld']['events'];

		$askSalary = array_search(['ask', 'salary', []], $events, true);
		$askName   = array_search(['ask', 'name', []], $events, true);
		$firstScoped = null;
		foreach ($events as $index => $event) {
			if ($event[0] === 'buildFilteredQuery') {
				$firstScoped = $index;
				break;
			}
		}

		$this->assertIsInt($askSalary);
		$this->assertIsInt($askName);
		$this->assertIsInt($firstScoped);
		$this->assertLessThan($firstScoped, $askSalary);
		$this->assertLessThan($firstScoped, $askName);

		$scopedQueries = array_values(array_filter($events, static fn (array $event): bool => $event[0] === 'buildFilteredQuery'));
		$this->assertCount(2, $scopedQueries, 'name (terms) and born (histogram) are scoped; salary and secret run no query.');
		foreach ($scopedQueries as $scoped) {
			$this->assertSame(['openregister_table_7_1', 7, ['status' => 'open']], array_slice($scoped, 1));
		}

		$flags = $this->outcomes()['rbac and multitenancy off pass through']['events'];
		foreach ($flags as $event) {
			if ($event[0] === 'buildFilteredQuery') {
				$this->assertSame(['_rbac' => false, '_multitenancy' => false, 'status' => 'open'], $event[3]);
			}
		}

		$related = $this->outcomes()['union, related filter refused first']['events'];
		$this->assertSame([['refuseRelatedRowFilters', ['_related', '_facets']]], $related);
	}//end testTheRuleIsAskedBeforeTheQueryAndScopesIt()
}//end class
