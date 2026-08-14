<?php

/**
 * The repeat node is the engine's `while`, and nothing had ever asserted it.
 *
 * `openregister.iterate` is registered, dispatched and documented — and until
 * now carried no test at all, while its sibling `LoopNode` (which only batches)
 * had one. That asymmetry matters because the two are easy to confuse by name:
 * the one with tests is not the one that loops.
 *
 * What is pinned here is the contract a flow author depends on:
 *   * the body runs once per iteration, in order;
 *   * the loop stops the moment the source returns nothing — the single
 *     termination rule, which is what makes pagination fall out for free;
 *   * `context['iteration']` carries the index, so a source can ask for the
 *     next page;
 *   * `maxIterations` bounds it, and `onLimit` decides whether overrunning is
 *     a failure or a quiet stop. That pair is the difference between a `while`
 *     and a `for`, expressed in one node.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowStepDispatcher;
use OCA\OpenRegister\Service\Flow\Nodes\IterateNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\IterateNode
 */
final class IterateNodeTest extends TestCase {

	/**
	 * Build the node with a dispatcher whose behaviour each test defines.
	 *
	 * @param callable $dispatch Receives (step, items, context) and returns items.
	 *
	 * @return IterateNode The node under test.
	 */
	private function nodeWith(callable $dispatch): IterateNode {
		$dispatcher = $this->getMockBuilder(FlowStepDispatcher::class)
			->disableOriginalConstructor()
			->onlyMethods(['dispatch'])
			->getMock();
		$dispatcher->method('dispatch')->willReturnCallback(
			static fn (array $step, array $items, array $context): array => $dispatch($step, $items, $context)
		);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($dispatcher);

		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new IterateNode($l10n, $this->createMock(originalClassName: IURLGenerator::class), $container);
	}

	/**
	 * The source dries up and the loop stops there — the one termination rule.
	 *
	 * @return void
	 */
	public function testItStopsWhenTheSourceProducesNothing(): void {
		$sourceCalls = 0;
		$bodyCalls = 0;

		$node = $this->nodeWith(function (array $step, array $items, array $context) use (&$sourceCalls, &$bodyCalls): array {
			if (($step['id'] ?? '') === 'source') {
				$sourceCalls++;
				// Three pages, then empty — exactly what a page past the end is.
				return $sourceCalls <= 3 ? [FlowItems::item(json: ['page' => $sourceCalls])] : [];
			}

			$bodyCalls++;
			return $items;
		});

		$out = $node->execute(
			items: [],
			config: [
				'source' => ['id' => 'source', 'type' => 'openconnector.source-call'],
				'body' => [['id' => 'body', 'type' => 'openregister.set-fields']],
			],
			context: []
		);

		$this->assertSame(expected: 4, actual: $sourceCalls, message: 'the source is asked once more than it produced, and the empty answer ends it');
		$this->assertSame(expected: 3, actual: $bodyCalls, message: 'the body runs once per productive iteration and not on the empty one');
		$this->assertCount(expectedCount: 3, haystack: $out, message: 'every iteration contributes its items to the result');
	}

	/**
	 * The iteration index is in scope, which is how a source pages.
	 *
	 * @return void
	 */
	public function testTheIterationIndexIsVisibleToTheSource(): void {
		$seen = [];

		$node = $this->nodeWith(function (array $step, array $items, array $context) use (&$seen): array {
			if (($step['id'] ?? '') === 'source') {
				$seen[] = [$context['iteration']['index'] ?? null, $context['iteration']['first'] ?? null];
				return count($seen) <= 2 ? [FlowItems::item(json: [])] : [];
			}

			return $items;
		});

		$node->execute(
			items: [],
			config: ['source' => ['id' => 'source'], 'body' => [['id' => 'body']]],
			context: []
		);

		$this->assertSame(expected: [[0, true], [1, false], [2, false]], actual: $seen);
	}

	/**
	 * A source that never runs out is a FAILURE by default — the loop refuses
	 * to return a half-answer as though it were the whole one.
	 *
	 * @return void
	 */
	public function testAnEndlessSourceOverrunsAndFails(): void {
		$node = $this->nodeWith(
			static fn (array $step, array $items, array $context): array => [FlowItems::item(json: [])]
		);

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessageMatches(regularExpression: '/did not finish within 3 iterations/');

		$node->execute(
			items: [],
			config: ['source' => ['id' => 's'], 'body' => [['id' => 'b']], 'maxIterations' => 3],
			context: []
		);
	}

	/**
	 * `onLimit: stop` turns the same node into a bounded `for`: run at most N,
	 * keep what you got, do not call it an error.
	 *
	 * @return void
	 */
	public function testOnLimitStopMakesItABoundedFor(): void {
		$node = $this->nodeWith(
			static fn (array $step, array $items, array $context): array => [FlowItems::item(json: ['i' => $context['iteration']['index']])]
		);

		$out = $node->execute(
			items: [],
			config: ['source' => ['id' => 's'], 'body' => [['id' => 'b']], 'maxIterations' => 4, 'onLimit' => 'stop'],
			context: []
		);

		$this->assertCount(expectedCount: 4, haystack: $out, message: 'exactly maxIterations iterations ran, and none of them failed the run');
	}

	/**
	 * Body steps run in declared order, each fed the previous one's output.
	 *
	 * @return void
	 */
	public function testBodyStepsChainInOrder(): void {
		$order = [];

		$node = $this->nodeWith(function (array $step, array $items, array $context) use (&$order): array {
			$id = (string)($step['id'] ?? '');
			if ($id === 'source') {
				return empty($order) === true ? [FlowItems::item(json: [])] : [];
			}

			$order[] = $id;
			return $items;
		});

		$node->execute(
			items: [],
			config: [
				'source' => ['id' => 'source'],
				'body' => [['id' => 'first'], ['id' => 'second'], ['id' => 'third']],
			],
			context: []
		);

		$this->assertSame(expected: ['first', 'second', 'third'], actual: $order);
	}

}//end class
