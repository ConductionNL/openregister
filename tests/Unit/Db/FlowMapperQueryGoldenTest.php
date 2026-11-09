<?php

/**
 * Golden for the queries FlowMapper's listing, count and trigger reads build.
 *
 * Every builder call is recorded in order with its arguments, so a change to
 * which predicates are added, in which order, or with which values shows up.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\FlowMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * FlowMapper whose entity read returns nothing, so only the query is observed.
 */
class RecordingFlowMapper extends FlowMapper {

	/**
	 * Return no rows.
	 *
	 * @param IQueryBuilder $query The query.
	 *
	 * @return array<int, mixed>
	 */
	protected function findEntities(IQueryBuilder $query): array {
		return [];
	}//end findEntities()
}//end class

/**
 * Pins the builder calls of findAllFlows(), countFlows() and findByTrigger().
 */
class FlowMapperQueryGoldenTest extends TestCase {

	/**
	 * Recorded builder calls.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * A mapper on a recording query builder.
	 *
	 * @return RecordingFlowMapper
	 */
	private function mapper(): RecordingFlowMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->queryBuilder());

		return new RecordingFlowMapper($db);
	}//end mapper()

	/**
	 * Render a value for the record.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private static function show(mixed $value): string {
		return var_export($value, true);
	}//end show()

	/**
	 * A query builder that records every call.
	 *
	 * @return IQueryBuilder
	 */
	private function queryBuilder(): IQueryBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['eq', 'isNull', 'isNotNull', 'neq', 'gt'] as $method) {
			$expr->method($method)->willReturnCallback(
				static fn (...$args): string => $method . '(' . implode(', ', array_map([self::class, 'show'], $args)) . ')'
			);
		}

		$expr->method('orX')->willReturnCallback(
			static fn (...$args): string => 'orX(' . implode(' | ', $args) . ')'
		);

		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('count')->willReturn($this->createMock(IQueryFunction::class));

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('func')->willReturn($func);
		$qb->method('createNamedParameter')->willReturnCallback(
			static fn ($value, $type = null): string => ':' . self::show($value) . '/' . self::show($type)
		);
		foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'setMaxResults', 'setFirstResult'] as $chain) {
			$qb->method($chain)->willReturnCallback(
				function (...$args) use ($chain, &$qb) {
					$shown = array_map(
						static fn ($arg): string => is_string($arg) === true ? $arg : self::show($arg),
						$args
					);
					$this->calls[] = $chain . '(' . implode(', ', $shown) . ')';
					return $qb;
				}
			);
		}

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(['total' => '7']);
		$qb->method('executeQuery')->willReturn($result);

		return $qb;
	}//end queryBuilder()

	/**
	 * The calls recorded, without the select() argument (a mock for count).
	 *
	 * @return array<int, string>
	 */
	private function recorded(): array {
		return array_values(array_filter($this->calls, static fn (string $call): bool => str_starts_with($call, 'select(') === false));
	}//end recorded()

	/**
	 * A bare listing pages at 100 from 0, newest first.
	 *
	 * @return void
	 */
	public function testABareListingPagesNewestFirst(): void {
		$this->mapper()->findAllFlows();

		$this->assertSame(
			["from(openregister_flows)", "orderBy(id, DESC)", "setMaxResults(100)", "setFirstResult(0)"],
			$this->recorded()
		);
	}//end testABareListingPagesNewestFirst()

	/**
	 * Every filter given adds its predicate, in order app, slug, organisation, enabled.
	 *
	 * @return void
	 */
	public function testEveryListingFilterAddsItsPredicateInOrder(): void {
		$this->mapper()->findAllFlows(
			app: 'hydra',
			applicationSlug: 'build',
			organisation: 'org-1',
			enabled: false,
			limit: 5,
			offset: 10
		);

		$this->assertSame(
			[
				"from(openregister_flows)",
				"orderBy(id, DESC)",
				"setMaxResults(5)",
				"setFirstResult(10)",
				"andWhere(eq('app', ':\\'hydra\\'/NULL'))",
				"andWhere(eq('application_slug', ':\\'build\\'/NULL'))",
				"andWhere(eq('organisation', ':\\'org-1\\'/NULL'))",
				"andWhere(eq('enabled', ':false/\\'boolean\\''))",
			],
			$this->recorded()
		);
	}//end testEveryListingFilterAddsItsPredicateInOrder()

	/**
	 * Empty-string filters add no predicate.
	 *
	 * @return void
	 */
	public function testEmptyListingFiltersAddNothing(): void {
		$this->mapper()->findAllFlows(app: '', applicationSlug: '', organisation: '');

		$this->assertSame(
			["from(openregister_flows)", "orderBy(id, DESC)", "setMaxResults(100)", "setFirstResult(0)"],
			$this->recorded()
		);
	}//end testEmptyListingFiltersAddNothing()

	/**
	 * A count scopes like the listing and reads the total.
	 *
	 * @return void
	 */
	public function testACountScopesLikeTheListingAndReadsTheTotal(): void {
		$count = $this->mapper()->countFlows(app: 'hydra', applicationSlug: '', organisation: 'org-1');

		$this->assertSame(7, $count);
		$this->assertSame(
			[
				"from(openregister_flows)",
				"andWhere(eq('app', ':\\'hydra\\'/NULL'))",
				"andWhere(eq('organisation', ':\\'org-1\\'/NULL'))",
			],
			$this->recorded()
		);
	}//end testACountScopesLikeTheListingAndReadsTheTotal()

	/**
	 * A bare trigger read filters on the trigger and on enabled only.
	 *
	 * @return void
	 */
	public function testABareTriggerReadFiltersOnTriggerAndEnabled(): void {
		$this->mapper()->findByTrigger(trigger: 'object.created', register: '', schema: null, organisation: '');

		$this->assertSame(
			[
				"from(openregister_flows)",
				"where(eq('trigger', ':\\'object.created\\'/NULL'))",
				"andWhere(eq('enabled', ':true/\\'boolean\\''))",
			],
			$this->recorded()
		);
	}//end testABareTriggerReadFiltersOnTriggerAndEnabled()

	/**
	 * A scoped trigger read lets unrestricted flows through and scopes the organisation.
	 *
	 * @return void
	 */
	public function testAScopedTriggerReadKeepsUnrestrictedFlows(): void {
		$this->mapper()->findByTrigger(trigger: 'object.created', register: 'reg', schema: 'sch', organisation: 'org-1');

		$this->assertSame(
			[
				"from(openregister_flows)",
				"where(eq('trigger', ':\\'object.created\\'/NULL'))",
				"andWhere(eq('enabled', ':true/\\'boolean\\''))",
				"andWhere(orX(isNull('trigger_register') | eq('trigger_register', ':\\'\\'/NULL') | eq('trigger_register', ':\\'reg\\'/NULL')))",
				"andWhere(orX(isNull('trigger_schema') | eq('trigger_schema', ':\\'\\'/NULL') | eq('trigger_schema', ':\\'sch\\'/NULL')))",
				"andWhere(eq('organisation', ':\\'org-1\\'/NULL'))",
			],
			$this->recorded()
		);
	}//end testAScopedTriggerReadKeepsUnrestrictedFlows()
}//end class
