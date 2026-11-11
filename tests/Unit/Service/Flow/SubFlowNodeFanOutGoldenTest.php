<?php

/**
 * Characterisation test for SubFlowNode's fan-out paths.
 *
 * Pins one child run per item when fanning out (waited and fire-and-forget),
 * the per-item `payload` seed, the recursion stack and acting identity each
 * child receives, and the concatenated output, so the invocation can take a
 * parameter object without moving an outcome.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCA\OpenRegister\Service\Flow\Nodes\SubFlowNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Service\Flow\FlowRunOrigin;
use OCA\OpenRegister\Service\Flow\FlowRunPass;
use OCA\OpenRegister\Service\Flow\FlowRunQueueOptions;

/**
 * Golden outcomes for a fanned-out sub-flow step.
 */
class SubFlowNodeFanOutGoldenTest extends TestCase {

	/**
	 * The run service double.
	 *
	 * @var FlowRunServiceInterface&MockObject
	 */
	private FlowRunServiceInterface&MockObject $runs;

	/**
	 * The node under test.
	 *
	 * @var SubFlowNode
	 */
	private SubFlowNode $node;

	/**
	 * Every queue() call, as named arguments.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queued = [];

	/**
	 * Build the node over a resolver that knows flow `child`.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$resolvers = $this->createMock(FlowLocator::class);
		$resolvers->method('resolveFlow')->willReturn(['id' => 'child', 'edges' => []]);
		$this->runs = $this->createMock(FlowRunServiceInterface::class);
		$this->runs->method('queueRun')->willReturnCallback(
			function (string $flowId, FlowRunQueueOptions $options): FlowRun {
				$this->queued[] = [$flowId, $options->subject, $options->trigger, $options->context, $options->user];
				$run = new FlowRun();
				$run->setStatus(FlowRun::STATUS_QUEUED);
				return $run;
			}
		);
		$this->node = new SubFlowNode(new \OCA\OpenRegister\Service\Flow\FlowExecution($resolvers, $this->runs, new \Psr\Log\NullLogger()), new \OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation($l10n, $this->createMock(IURLGenerator::class)));

	}//end setUp()

	/**
	 * Three input items.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function items(): array {
		return [
			FlowItems::item(json: ['n' => 1]),
			FlowItems::item(json: ['n' => 2]),
			FlowItems::item(json: ['n' => 3]),
		];

	}//end items()

	/**
	 * A waited fan-out runs one child per item and concatenates what they produced.
	 *
	 * @return void
	 */
	public function testAWaitedFanOutRunsOneChildPerItem(): void {
		$seeds = [];
		$this->runs->method('executePass')->willReturnCallback(
			static function (FlowRunPass $pass, FlowRunOrigin $origin) use (&$seeds): FlowRun {
				$seed    = $origin->items;
				$seeds[] = $seed;
				$n       = $seed[0]['json']['n'];
				$run     = new FlowRun();
				$run->setStatus(FlowRun::STATUS_COMPLETED);
				$run->setItems(array_fill(0, $n, FlowItems::item(json: ['from' => $n])));
				return $run;
			}
		);

		$out = $this->node->execute(new FlowNodeCall(
			items: $this->items(),
			config: ['flowId' => 'child', 'fanOut' => true],
			context: ['flowStack' => ['parent'], 'runAs' => 'alice']
		));

		$this->assertSame([1, 2, 2, 3, 3, 3], array_map(static fn (array $item): int => $item['json']['from'], $out));
		$this->assertSame([[$this->items()[0]], [$this->items()[1]], [$this->items()[2]]], $seeds);
		$this->assertCount(3, $this->queued);
		foreach ($this->queued as $index => $call) {
			$context = $call['context'] ?? $call[3];
			$this->assertSame(['parent', 'child'], $context['flowStack']);
			$this->assertSame(['n' => $index + 1], $context['payload']);
			$this->assertSame('alice', $call['user'] ?? $call[4]);
			$this->assertSame('sub-flow', $call['trigger'] ?? $call[2]);
		}

	}//end testAWaitedFanOutRunsOneChildPerItem()

	/**
	 * A fire-and-forget fan-out queues one seeded child per item and passes the items through.
	 *
	 * @return void
	 */
	public function testAFireAndForgetFanOutQueuesOneChildPerItem(): void {
		$this->runs->expects($this->never())->method('executePass');

		$out = $this->node->execute(new FlowNodeCall(items: $this->items(), config: ['flowId' => 'child', 'fanOut' => true, 'wait' => false], context: []));

		$this->assertSame($this->items(), $out);
		$this->assertSame(
			[['n' => 1], ['n' => 2], ['n' => 3]],
			array_map(static fn (array $call): array => ($call['context'] ?? $call[3])['payload'], $this->queued)
		);
		$this->assertSame([null, null, null], array_map(static fn (array $call): mixed => ($call['user'] ?? $call[4]), $this->queued));

	}//end testAFireAndForgetFanOutQueuesOneChildPerItem()

	/**
	 * Without fan-out one child takes every item and gets no payload seed.
	 *
	 * @return void
	 */
	public function testWithoutFanOutOneChildTakesEveryItem(): void {
		$this->node->execute(new FlowNodeCall(items: $this->items(), config: ['flowId' => 'child', 'wait' => false, 'fanOut' => 'yes'], context: []));

		$this->assertCount(1, $this->queued);
		$this->assertArrayNotHasKey('payload', ($this->queued[0]['context'] ?? $this->queued[0][3]));

	}//end testWithoutFanOutOneChildTakesEveryItem()

	/**
	 * A fan-out over no items starts no child.
	 *
	 * @return void
	 */
	public function testAFanOutOverNoItemsStartsNoChild(): void {
		$this->assertSame([], $this->node->execute(new FlowNodeCall(items: [], config: ['flowId' => 'child', 'fanOut' => true], context: [])));
		$this->assertSame([], $this->queued);

	}//end testAFanOutOverNoItemsStartsNoChild()
}//end class
