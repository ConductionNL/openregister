<?php

/**
 * Golden for the object field filters a facet count applies.
 *
 * Records every WHERE predicate MagicFacetHandler::applyObjectFieldFilters()
 * adds for a range of base queries: reserved and underscore parameters,
 * missing columns, array, boolean, list and scalar values. Re-record with
 * UPDATE_GOLDEN=1.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler;
use OCA\OpenRegister\Db\MagicMapper\TableTermsFacet;
use OCA\OpenRegister\Db\MagicMapper\TermsFacetTable;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Object field filters on the facet path, recorded per base query.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicFacetFieldLabel
 * @uses   \OCA\OpenRegister\Db\MagicMapper\TableTermsFacet
 * @uses   \OCA\OpenRegister\Db\MagicMapper\TermsFacetTable
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Support\FilterParams
 */
class MagicFacetHandlerObjectFieldFiltersGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/facet-object-field-filters-golden.json';

	/**
	 * Every builder call, rendered.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	public function testObjectFieldFiltersMatchGolden(): void {
		$queries = [
			'empty' => [],
			'reserved only' => ['_limit' => 5, '_search' => 'x', '@self' => ['register' => 1], '_custom' => 'y', '_schemas' => [1, 2]],
			'scalar' => ['title' => 'Hallo'],
			'camel case' => ['firstName' => 'Jan'],
			'list' => ['status' => ['open', 'closed']],
			'boolean' => ['flag' => true, 'other' => false],
			'number' => ['amount' => 12.5, 'count' => 3],
			'array property' => ['tags' => 'a'],
			'array property list' => ['tags' => ['a', 'b']],
			'missing column first' => ['nope' => 1, 'title' => 'x'],
			'missing column later' => ['title' => 'x', 'nope' => 1, 'status' => 'open'],
			'mixed' => ['_order' => ['title' => 'asc'], 'title' => 'a', '@self' => [], 'flag' => false, 'tags' => ['z'], 'status' => ['open']],
			'null value' => ['title' => null],
			'context params' => ['register' => 1, 'schema' => 2, 'registers' => [1], 'schemas' => [2], 'extend' => 'owner'],
			'context params and a filter' => ['extend' => ['owner'], 'title' => 'x', 'schemas' => [2]],
		];

		$actual = [];
		foreach ([true, false] as $withSchema) {
			foreach ($queries as $name => $query) {
				$this->calls = [];
				$this->apply(query: $query, withSchema: $withSchema);
				$actual[$name . ($withSchema === true ? '' : ' / no schema')] = $this->calls;
			}
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testObjectFieldFiltersMatchGolden()

	/**
	 * Run applyObjectFieldFilters() on one base query.
	 *
	 * @param array $query      The base query.
	 * @param bool  $withSchema Whether the schema declares the property types.
	 *
	 * @return void
	 */
	private function apply(array $query, bool $withSchema): void {
		$facets = new MagicFacetHandler(db: $this->createMock(IDBConnection::class), logger: $this->createMock(LoggerInterface::class));
		$cache = new ReflectionProperty(MagicFacetHandler::class, 'columnCache');
		$cache->setValue(
			$facets,
			['oc_openregister_table_1_1' => ['title' => true, 'first_name' => true, 'status' => true, 'flag' => true, 'other' => true, 'amount' => true, 'count' => true, 'tags' => true]]
		);

		$schema = new Schema();
		if ($withSchema === true) {
			$schema->setProperties(
				[
					'title' => ['type' => 'string'],
					'status' => ['type' => 'string'],
					'flag' => ['type' => 'boolean'],
					'other' => ['type' => 'boolean'],
					'amount' => ['type' => 'number'],
					'count' => ['type' => 'integer'],
					'tags' => ['type' => 'array'],
				]
			);
		}

		$method = new ReflectionMethod(MagicFacetHandler::class, 'applyObjectFieldFilters');
		$facet  = (new TermsFacetTable('openregister_table_1_1', $query, new Register(), $schema))->forPropertyField('title');
		$method->invoke($facets, $this->queryBuilder(), $facet);
	}//end apply()

	/**
	 * A builder that renders every predicate it is given.
	 *
	 * @return IQueryBuilder The builder.
	 */
	private function queryBuilder(): IQueryBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['eq', 'in'] as $operator) {
			$expr->method($operator)->willReturnCallback(
				static fn (string $column, $value): string => "{$operator}({$column}," . json_encode($value) . ')'
			);
		}

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value, $type = null): string {
				$this->calls[] = 'param ' . json_encode($value) . ' ' . json_encode($type);
				return ':p' . count($this->calls);
			}
		);
		$qb->method('createFunction')->willReturnCallback(
			function (string $call): IQueryFunction {
				$function = $this->createMock(IQueryFunction::class);
				$function->method('__toString')->willReturn($call);
				return $function;
			}
		);
		$qb->method('andWhere')->willReturnCallback(
			function (...$predicates) use ($qb) {
				foreach ($predicates as $predicate) {
					$this->calls[] = 'where ' . (string) $predicate;
				}

				return $qb;
			}
		);
		return $qb;
	}//end queryBuilder()
}//end class
