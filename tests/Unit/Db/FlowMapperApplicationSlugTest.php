<?php

/**
 * `FlowMapper::findAllFlows()`/`countFlows()` actually apply the
 * `applicationSlug` predicate.
 *
 * Every other test that touches `FlowMapper` mocks it away entirely (the
 * round-trip test, the controller test, the repair-job test), so the new
 * `andWhere('applicationSlug', ...)` branches in both methods were never
 * exercised against the real method body — only against a double that
 * always did whatever the test told it to. This file drives the real
 * `FlowMapper` with a `IQueryBuilder` double that records which `eq()`
 * predicates were actually built, the same pattern
 * `RegisterMapperDeterministicFindTest` and `GdprEntityMapperTest` use for
 * the same reason.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCP\AppFramework\Db\Entity;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A subclass that lets us inspect `findEntities()`'s incoming query without
 * needing to fake a hydratable result set — `findAllFlows()` builds the
 * predicate we care about before handing the query off, and execution is
 * `RegisterMapper`'s / `GdprEntityMapper`'s territory to prove, not this
 * file's.
 */
class TestableFlowMapperForApplicationSlug extends FlowMapper {

	/**
	 * The query `findAllFlows()` handed to `findEntities()`, if any.
	 *
	 * @var IQueryBuilder|null
	 */
	public ?IQueryBuilder $capturedQuery = null;

	/**
	 * Capture the query and return no rows — the predicate is what this
	 * file asserts on, not the hydrated entities.
	 *
	 * @param IQueryBuilder $query The built query.
	 *
	 * @return Entity[] Always empty.
	 */
	protected function findEntities(IQueryBuilder $query): array {
		$this->capturedQuery = $query;
		return [];

	}//end findEntities()
}//end class

/**
 * Real-query-builder coverage for the `applicationSlug` predicate.
 */
class FlowMapperApplicationSlugTest extends TestCase {

	/**
	 * `eq()` calls recorded across every `IQueryBuilder` produced, as
	 * `[column, value]` pairs — `createNamedParameter()` is stubbed to pass
	 * the raw value straight through so the pair is legible.
	 *
	 * @var array<int, array{0: string, 1: mixed}>
	 */
	private array $eqCalls = [];

	/**
	 * Build a `IDBConnection` double whose `getQueryBuilder()` yields a fresh
	 * recording `IQueryBuilder` double each time.
	 *
	 * @param array<string, mixed> $countRow The row `countFlows()`'s
	 *                                        `executeQuery()->fetch()` returns.
	 *
	 * @return IDBConnection&MockObject The wired double.
	 */
	private function db(array $countRow = ['total' => 0]): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->queryBuilder($countRow));

		return $db;

	}//end db()

	/**
	 * Build one recording `IQueryBuilder` double.
	 *
	 * @param array<string, mixed> $countRow The row `executeQuery()->fetch()` returns.
	 *
	 * @return IQueryBuilder&MockObject The double.
	 */
	private function queryBuilder(array $countRow): IQueryBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(
			function (string $column, $value): string {
				$this->eqCalls[] = [$column, $value];
				return 'eq:' . $column;
			}
		);

		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('count')->willReturn($this->createMock(IQueryFunction::class));

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('func')->willReturn($func);
		// The raw value passes straight through, so eq()'s second argument
		// above is the actual filter value rather than an opaque placeholder.
		$qb->method('createNamedParameter')->willReturnCallback(
			static fn ($value, $type = null) => $value
		);
		foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'setMaxResults', 'setFirstResult'] as $chain) {
			$qb->method($chain)->willReturnSelf();
		}

		$result = $this->createMock(IResult::class);
		$fetched = false;
		$result->method('fetch')->willReturnCallback(
			function () use (&$fetched, $countRow) {
				if ($fetched === true) {
					return false;
				}

				$fetched = true;
				return $countRow;
			}
		);
		$result->method('closeCursor')->willReturn(true);
		$qb->method('executeQuery')->willReturn($result);

		return $qb;

	}//end queryBuilder()

	/**
	 * A non-empty `applicationSlug` becomes an `eq('applicationSlug', ...)`
	 * predicate on `findAllFlows()`.
	 *
	 * @return void
	 */
	public function testFindAllFlowsAppliesTheApplicationSlugPredicateWhenGiven(): void {
		$mapper = new TestableFlowMapperForApplicationSlug($this->db());

		$mapper->findAllFlows(applicationSlug: 'hydra');

		$this->assertContains(['applicationSlug', 'hydra'], $this->eqCalls);

	}//end testFindAllFlowsAppliesTheApplicationSlugPredicateWhenGiven()

	/**
	 * An absent `applicationSlug` builds no predicate for it at all — the
	 * unfiltered case must not accidentally filter on an empty string.
	 *
	 * @return void
	 */
	public function testFindAllFlowsAppliesNoApplicationSlugPredicateWhenAbsent(): void {
		$mapper = new TestableFlowMapperForApplicationSlug($this->db());

		$mapper->findAllFlows();

		$columns = array_column($this->eqCalls, 0);
		$this->assertNotContains('applicationSlug', $columns);

	}//end testFindAllFlowsAppliesNoApplicationSlugPredicateWhenAbsent()

	/**
	 * `app` and `applicationSlug` compose as an AND: both predicates are
	 * built on the same query, matching the migration's precedent and the
	 * spec's "compose" requirement.
	 *
	 * @return void
	 */
	public function testFindAllFlowsComposesTheAppAndApplicationSlugPredicates(): void {
		$mapper = new TestableFlowMapperForApplicationSlug($this->db());

		$mapper->findAllFlows(app: 'hermiq', applicationSlug: 'hydra');

		$this->assertContains(['app', 'hermiq'], $this->eqCalls);
		$this->assertContains(['applicationSlug', 'hydra'], $this->eqCalls);

	}//end testFindAllFlowsComposesTheAppAndApplicationSlugPredicates()

	/**
	 * `countFlows()` applies the same predicate — it scopes identically to
	 * `findAllFlows()` so a filtered list and its total agree.
	 *
	 * @return void
	 */
	public function testCountFlowsAppliesTheApplicationSlugPredicateWhenGiven(): void {
		$mapper = new FlowMapper($this->db(['total' => 2]));

		$total = $mapper->countFlows(applicationSlug: 'hydra');

		$this->assertSame(2, $total);
		$this->assertContains(['applicationSlug', 'hydra'], $this->eqCalls);

	}//end testCountFlowsAppliesTheApplicationSlugPredicateWhenGiven()

	/**
	 * An absent `applicationSlug` on `countFlows()` builds no predicate for
	 * it either.
	 *
	 * @return void
	 */
	public function testCountFlowsAppliesNoApplicationSlugPredicateWhenAbsent(): void {
		$mapper = new FlowMapper($this->db(['total' => 5]));

		$total = $mapper->countFlows();

		$this->assertSame(5, $total);
		$columns = array_column($this->eqCalls, 0);
		$this->assertNotContains('applicationSlug', $columns);

	}//end testCountFlowsAppliesNoApplicationSlugPredicateWhenAbsent()

}//end class
