<?php

/**
 * The exact predicate sequence TaskMapper's filter and blocker halves emit.
 *
 * Pins, in order, every WHERE fragment and every bound parameter (value and
 * type) the inbox filters add, one case per filter, and when the blocked-task
 * exclusion applies and when it stands aside.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\TaskInboxAnchor;
use OCA\OpenRegister\Db\TaskInboxAudience;
use OCA\OpenRegister\Db\TaskInboxCriteria;
use OCA\OpenRegister\Db\TaskInboxFilter;
use OCA\OpenRegister\Db\TaskInboxSelection;
use OCA\OpenRegister\Db\TaskInboxDueWindow;
use OCA\OpenRegister\Db\TaskMapper;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Ordered predicate log of applyFilters() and applyBlockedExclusion().
 *
 * @covers \OCA\OpenRegister\Db\TaskMapper
 * @uses \OCA\OpenRegister\Db\TaskInboxAnchor
 * @uses \OCA\OpenRegister\Db\TaskInboxAudience
 * @uses \OCA\OpenRegister\Db\TaskInboxCriteria
 * @uses \OCA\OpenRegister\Db\TaskInboxDueWindow
 * @uses \OCA\OpenRegister\Db\TaskInboxFilter
 * @uses \OCA\OpenRegister\Db\TaskInboxOrder
 * @uses \OCA\OpenRegister\Db\TaskInboxSelection
 */
class TaskMapperFilterPredicatesTest extends TestCase {
	use FluentQueryBuilderTrait;

	/**
	 * Everything the builder under test saw, in order.
	 *
	 * @var array<int, string>
	 */
	private array $log = [];

	/**
	 * A builder that logs expressions, bound parameters and andWhere calls.
	 *
	 * @return IQueryBuilder The builder.
	 */
	private function loggingBuilder(): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['eq', 'in', 'lt', 'gte', 'lte', 'isNull', 'notIn', 'iLike'] as $method) {
			$expr->method($method)->willReturnCallback(
				function (mixed ...$args) use ($method): string {
					$this->log[] = 'expr.' . $method . ' ' . (string) $args[0];

					return $method . '(' . (string) $args[0] . ')';
				}
			);
		}

		$composite = $this->createMock(ICompositeExpression::class);
		$expr->method('orX')->willReturnCallback(
			function () use ($composite): ICompositeExpression {
				$this->log[] = 'expr.orX';

				return $composite;
			}
		);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(
			function (mixed $value, mixed $type = IQueryBuilder::PARAM_STR): IParameter {
				$this->log[] = 'param ' . json_encode($value) . ' ' . json_encode($type);

				return $this->createMock(IParameter::class);
			}
		);
		$qb->method('createFunction')->willReturnCallback(
			function (string $sql): IQueryFunction {
				$this->log[] = 'function ' . $sql;

				return $this->createMock(IQueryFunction::class);
			}
		);
		$qb->method('andWhere')->willReturnCallback(
			function () use ($qb): IQueryBuilder {
				$this->log[] = 'andWhere';

				return $qb;
			}
		);

		return $qb;
	}//end loggingBuilder()

	/**
	 * Run a private TaskMapper predicate method against the logging builder.
	 *
	 * @param string $method The private method.
	 * @param array<string, mixed> $args Its named arguments besides `qb`.
	 *
	 * @return array<int, string> The log.
	 */
	private function logOf(string $method, array $args): array {
		$this->log = [];
		$mapper = new TaskMapper(db: $this->connectionWith());
		(new ReflectionMethod(TaskMapper::class, $method))->invoke($mapper, $this->loggingBuilder(), ...array_values($args));

		return $this->log;
	}//end logOf()

	/**
	 * Criteria over the given filter, anchor and window.
	 *
	 * @param TaskInboxSelection $selection The selection.
	 *
	 * @return TaskInboxCriteria The criteria.
	 */
	private static function criteria(TaskInboxSelection $selection): TaskInboxCriteria {
		return new TaskInboxCriteria(audience: new TaskInboxAudience(uid: 'alice'), selection: $selection);
	}//end criteria()

	/**
	 * No filter set adds nothing but the (empty) due window.
	 *
	 * @return void
	 */
	public function testNoFilterAddsNoPredicate(): void {
		$this->assertSame([], $this->logOf('applyFilters', [self::criteria(new TaskInboxSelection())]));
	}//end testNoFilterAddsNoPredicate()

	/**
	 * Each filter on its own adds its one predicate with its parameter type.
	 *
	 * @return void
	 */
	public function testEachFilterAloneAddsItsPredicate(): void {
		$cases = [
			'states' => [new TaskInboxSelection(filter: new TaskInboxFilter(states: ['active'])), ['param ["active"] 102', 'expr.in state', 'andWhere']],
			'isTerminal' => [new TaskInboxSelection(filter: new TaskInboxFilter(isTerminal: true)), ['param true "boolean"', 'expr.eq is_terminal', 'andWhere']],
			'priority' => [new TaskInboxSelection(filter: new TaskInboxFilter(priority: 'high')), ['param "high" 2', 'expr.eq priority', 'andWhere']],
			'kind' => [new TaskInboxSelection(filter: new TaskInboxFilter(kind: 'review')), ['param "review" 2', 'expr.eq kind', 'andWhere']],
			'objectUuid' => [new TaskInboxSelection(anchor: new TaskInboxAnchor(objectUuid: 'o-1')), ['param "o-1" 2', 'expr.eq object_uuid', 'andWhere']],
			'runUuid' => [new TaskInboxSelection(anchor: new TaskInboxAnchor(runUuid: 'r-1')), ['param "r-1" 2', 'expr.eq run_uuid', 'andWhere']],
		];
		foreach ($cases as $name => [$selection, $expected]) {
			$this->assertSame($expected, $this->logOf('applyFilters', [self::criteria($selection)]), $name);
		}
	}//end testEachFilterAloneAddsItsPredicate()

	/**
	 * Every filter together lands in one fixed order, overdue and window last.
	 *
	 * @return void
	 */
	/**
	 * The flow filter reads the run table through a subquery on run_uuid, and
	 * the node filter is one equality on node_id.
	 *
	 * @spec openspec/changes/tasks-progress-report/specs/flow-progress-report/spec.md#requirement-a-person-sees-the-report-on-the-flow
	 */
	public function testFlowAndNodeFiltersNarrowToOneStepOfOneFlow(): void {
		$node = new TaskInboxSelection(anchor: new TaskInboxAnchor(nodeId: 'legal-review'));
		$this->assertSame(['param "legal-review" 2', 'expr.eq node_id', 'andWhere'], $this->logOf('applyFilters', [self::criteria($node)]));

		$this->calls = [];
		$flow = new TaskInboxSelection(anchor: new TaskInboxAnchor(flowId: 'flow-1'));
		$this->assertSame(
			['param "flow-1" 2', 'function SELECT subquery', 'expr.in run_uuid', 'andWhere'],
			$this->logOf('applyFilters', [self::criteria($flow)])
		);
		$this->assertTrue($this->saw('from', 'openregister_flow_runs'), 'the subquery reads the runs');
		$this->assertTrue($this->saw('expr.eq', 'flowrun.flow_id'), 'the subquery narrows to the flow');
	}//end testFlowAndNodeFiltersNarrowToOneStepOfOneFlow()

	/**
	 * A flow or node filter is not an anchor: the blocked exclusion still applies.
	 *
	 * @spec openspec/changes/tasks-progress-report/specs/flow-progress-report/spec.md#requirement-a-person-sees-the-report-on-the-flow
	 */
	public function testAFlowFilterKeepsTheInboxRules(): void {
		$criteria = self::criteria(new TaskInboxSelection(anchor: new TaskInboxAnchor(flowId: 'flow-1', nodeId: 'n-1')));

		$this->assertSame(['flow-1', 'n-1'], [$criteria->flowId, $criteria->nodeId]);
		$this->assertContains('expr.isNull blocked_by', $this->logOf('applyBlockedExclusion', [$criteria]));
	}//end testAFlowFilterKeepsTheInboxRules()

	public function testAllFiltersLandInAFixedOrder(): void {
		$selection = new TaskInboxSelection(
			filter: new TaskInboxFilter(states: ['active', 'enabled'], isTerminal: false, priority: 'low', kind: 'form'),
			window: new TaskInboxDueWindow(overdueAt: new DateTime('2026-09-01T00:00:00+00:00'), dueAfter: new DateTime('2026-08-01T00:00:00+00:00')),
			anchor: new TaskInboxAnchor(objectUuid: 'o-2', runUuid: 'r-2'),
		);

		$log = $this->logOf('applyFilters', [self::criteria($selection)]);

		$this->assertSame(
			['expr.in state', 'expr.eq is_terminal', 'expr.eq priority', 'expr.eq kind', 'expr.eq object_uuid', 'expr.eq run_uuid'],
			array_slice(array_values(array_filter($log, static fn (string $l): bool => str_starts_with($l, 'expr.'))), 0, 6)
		);
		$this->assertSame(
			['param ["active","enabled"] 102', 'param false "boolean"', 'param "low" 2', 'param "form" 2', 'param "o-2" 2', 'param "r-2" 2'],
			array_slice(array_values(array_filter($log, static fn (string $l): bool => str_starts_with($l, 'param '))), 0, 6)
		);
		$this->assertGreaterThan(6, count(array_filter($log, static fn (string $l): bool => $l === 'andWhere')));
	}//end testAllFiltersLandInAFixedOrder()

	/**
	 * The blocker exclusion applies for the portal seam and a plain read, and
	 * stands aside for includeBlocked and for an object or run anchor.
	 *
	 * @return void
	 */
	public function testTheBlockerExclusionAppliesOnlyToAnUnanchoredRead(): void {
		$excluded = ['param false "boolean"', 'expr.isNull blocked_by', 'function SELECT subquery', 'expr.notIn blocked_by', 'expr.orX', 'andWhere'];

		$this->assertSame($excluded, $this->logOf('applyBlockedExclusion', [null]), 'portal seam');
		$this->assertSame($excluded, $this->logOf('applyBlockedExclusion', [self::criteria(new TaskInboxSelection())]), 'plain read');
		$this->assertSame([], $this->logOf('applyBlockedExclusion', [self::criteria(new TaskInboxSelection(anchor: new TaskInboxAnchor(includeBlocked: true)))]), 'includeBlocked');
		$this->assertSame([], $this->logOf('applyBlockedExclusion', [self::criteria(new TaskInboxSelection(anchor: new TaskInboxAnchor(objectUuid: 'o-3')))]), 'object anchor');
		$this->assertSame([], $this->logOf('applyBlockedExclusion', [self::criteria(new TaskInboxSelection(anchor: new TaskInboxAnchor(runUuid: 'r-3')))]), 'run anchor');
	}//end testTheBlockerExclusionAppliesOnlyToAnUnanchoredRead()
	/**
	 * A term adds, per distinct word, title-or-description ILIKE with the escaped word.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-search-provider/tasks.md#task-1.1
	 */
	public function testATermMatchesEveryWordInTitleOrDescription(): void {
		$log = $this->logOf('applyFilters', [self::criteria(new TaskInboxSelection(filter: new TaskInboxFilter(term: '  Terugbellen   jansen terugbellen ')))]);

		$this->assertSame(
			[
				'param "%terugbellen%" 2', 'expr.iLike title', 'param "%terugbellen%" 2', 'expr.iLike description', 'expr.orX', 'andWhere',
				'param "%jansen%" 2', 'expr.iLike title', 'param "%jansen%" 2', 'expr.iLike description', 'expr.orX', 'andWhere',
			],
			$log
		);
	}//end testATermMatchesEveryWordInTitleOrDescription()

	/**
	 * A blank term adds nothing, and a term is cut to MAX_TERM_WORDS distinct lower-cased words.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-search-provider/tasks.md#task-1.1
	 */
	public function testTermWords(): void {
		$this->assertSame([], $this->logOf('applyFilters', [self::criteria(new TaskInboxSelection(filter: new TaskInboxFilter(term: '   ')))]));
		$this->assertSame([], TaskMapper::termWords(null));
		$this->assertSame(['réünie', 'café'], TaskMapper::termWords("Réünie\tCAFÉ réünie"));
		$this->assertCount(TaskMapper::MAX_TERM_WORDS, TaskMapper::termWords('a b c d e f g h i j'));
	}//end testTermWords()
}//end class
