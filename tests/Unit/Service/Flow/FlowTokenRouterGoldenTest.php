<?php

/**
 * Characterisation test for FlowTokenRouter's exit selection and edge reads.
 *
 * Pins every branch of takenExits(), resolveOutputTags(), placesForExit()
 * and conditionReaching() so the routing can be split into smaller units
 * without changing which places a token reaches.
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

use OCA\OpenRegister\Service\Flow\FlowTokenRouter;
use PHPUnit\Framework\TestCase;

/**
 * Golden outcomes for the token router.
 */
class FlowTokenRouterGoldenTest extends TestCase {

	/**
	 * The router under test.
	 *
	 * @var FlowTokenRouter
	 */
	private FlowTokenRouter $router;

	/**
	 * Build the router.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->router = new FlowTokenRouter();

	}//end setUp()

	/**
	 * A minimal transition stand-in.
	 *
	 * @param string        $name The node id.
	 * @param array<string> $tos  The output places.
	 *
	 * @return object The transition.
	 */
	private function transition(string $name, array $tos): object {
		return new class ($name, $tos) {
			/**
			 * Constructor.
			 *
			 * @param string        $name The node id.
			 * @param array<string> $tos  The output places.
			 */
			public function __construct(
				private readonly string $name,
				private readonly array $tos,
			) {
			}

			/**
			 * The node id.
			 *
			 * @return string
			 */
			public function getName(): string {
				return $this->name;
			}

			/**
			 * The output places.
			 *
			 * @return array<string>
			 */
			public function getTos(): array {
				return $this->tos;
			}
		};

	}//end transition()

	/**
	 * A flow whose `split` node declares the given exits, wired to the given edges.
	 *
	 * @param array<int, mixed> $exits The exits.
	 * @param array<int, mixed> $edges The edges.
	 *
	 * @return array<string, mixed> The flow.
	 */
	private function flow(array $exits, array $edges): array {
		return [
			'nodes' => [
				'not-a-node',
				['id' => 'split', 'exits' => $exits],
				['id' => 'hi'],
				['id' => 'lo'],
			],
			'edges' => $edges,
		];

	}//end flow()

	/**
	 * An item.
	 *
	 * @param array<string, mixed> $json   The payload.
	 * @param string|null          $output The routing tag.
	 *
	 * @return array<string, mixed> The item.
	 */
	private function item(array $json, ?string $output=null): array {
		$item = ['json' => $json, 'binary' => [], 'pairedItem' => null];
		if ($output !== null) {
			$item['output'] = $output;
		}

		return $item;

	}//end item()

	/**
	 * Every branch of takenExits().
	 *
	 * @return void
	 */
	public function testTakenExitsOutcomes(): void {
		$edges = [
			'not-an-edge',
			['from' => 'split', 'fromExit' => 'big', 'to' => ['hi']],
			['from' => ['split'], 'fromExit' => 'else', 'to' => 'lo'],
		];
		$exits = [
			'not-an-exit',
			['id' => 'orphan', 'condition' => ['==' => [1, 1]]],
			['id' => 'big', 'condition' => ['>' => [['var' => 'json.n'], 10]]],
			['id' => 'else', 'condition' => []],
		];
		$flow       = $this->flow(exits: $exits, edges: $edges);
		$transition = $this->transition(name: 'split', tos: ['hi', 'lo']);

		$this->assertSame(
			['hi'],
			$this->router->takenExits(exits: $this->router->exitsOf(flow: $flow, transition: $transition), output: new \OCA\OpenRegister\Service\Flow\FlowStepOutput(items: [$this->item(['n' => 20])], context: []))
		);
		$this->assertSame(
			['lo'],
			$this->router->takenExits(exits: $this->router->exitsOf(flow: $flow, transition: $transition), output: new \OCA\OpenRegister\Service\Flow\FlowStepOutput(items: [$this->item(['n' => 1])], context: []))
		);
		$this->assertSame(
			['hi', 'lo'],
			$this->router->takenExits(exits: $this->router->exitsOf(flow: $this->flow(exits: [], edges: $edges), transition: $transition), output: new \OCA\OpenRegister\Service\Flow\FlowStepOutput(items: [], context: []))
		);
		$this->assertSame(
			['hi', 'lo'],
			$this->router->takenExits(exits: $this->router->exitsOf(flow: ['nodes' => []], transition: $transition), output: new \OCA\OpenRegister\Service\Flow\FlowStepOutput(items: [], context: []))
		);

		$noElse = $this->flow(exits: [['id' => 'big', 'condition' => ['>' => [['var' => 'json.n'], 10]]]], edges: $edges);
		$this->assertSame([], $this->router->takenExits(exits: $this->router->exitsOf(flow: $noElse, transition: $transition), output: new \OCA\OpenRegister\Service\Flow\FlowStepOutput(items: [$this->item(['n' => 1])], context: [])));

		// A tag on a place (rather than the exit id) also selects the exit.
		$twoElses = $this->flow(
			exits: [['id' => 'big'], ['id' => 'else']],
			edges: $edges
		);
		$this->assertSame(['lo'], $this->router->takenExits(exits: $this->router->exitsOf(flow: $twoElses, transition: $transition), output: new \OCA\OpenRegister\Service\Flow\FlowStepOutput(items: [$this->item([], 'lo')], context: [])));
		$this->assertSame(['hi'], $this->router->takenExits(exits: $this->router->exitsOf(flow: $twoElses, transition: $transition), output: new \OCA\OpenRegister\Service\Flow\FlowStepOutput(items: [$this->item([], 'nowhere')], context: [])));

	}//end testTakenExitsOutcomes()

	/**
	 * Every branch of placesForExit().
	 *
	 * @return void
	 */
	public function testPlacesForExitOutcomes(): void {
		$flow = [
			'edges' => [
				'not-an-edge',
				['from' => 'other', 'fromExit' => 'x', 'to' => ['hi']],
				['from' => 'split', 'fromExit' => ' x ', 'to' => ['hi', 'gone']],
				['from' => ['split', 'other'], 'fromExit' => 'x', 'to' => 'hi'],
				['from' => 'split', 'fromExit' => 'x', 'to' => ['lo']],
				['from' => 'split', 'fromExit' => 'y', 'to' => ['lo']],
			],
		];

		$this->assertSame(['hi', 'lo'], $this->router->placesForExit(exits: new \OCA\OpenRegister\Service\Flow\FlowNodeExits(flow: $flow, nodeId: 'split', candidates: ['hi', 'lo']), exitId: 'x'));
		$this->assertSame([], $this->router->placesForExit(exits: new \OCA\OpenRegister\Service\Flow\FlowNodeExits(flow: $flow, nodeId: 'split', candidates: ['hi', 'lo']), exitId: 'z'));
		$this->assertSame([], $this->router->placesForExit(exits: new \OCA\OpenRegister\Service\Flow\FlowNodeExits(flow: [], nodeId: 'split', candidates: ['hi']), exitId: 'x'));

	}//end testPlacesForExitOutcomes()

	/**
	 * Every branch of conditionReaching().
	 *
	 * @return void
	 */
	public function testConditionReachingOutcomes(): void {
		$guard = ['>' => [['var' => 'json.n'], 10]];
		$flow  = [
			'nodes' => [
				['id' => 'split', 'exits' => ['nope', ['id' => 'big', 'condition' => $guard], ['id' => 'else']]],
				['id' => 'other', 'exits' => [['id' => 'big', 'condition' => ['==' => [1, 2]]]]],
			],
			'edges' => [
				'not-an-edge',
				['from' => 'split', 'fromExit' => 'big', 'to' => ['hi']],
				['from' => 'split', 'fromExit' => 'else', 'to' => ['lo']],
				['from' => 'split', 'to' => ['plain']],
				['from' => ['ghost', 'split'], 'fromExit' => 'unknown', 'to' => ['odd']],
				['from' => ['ghost', 'split'], 'fromExit' => 'big', 'to' => ['multi']],
			],
		];

		$this->assertSame($guard, $this->router->conditionReaching($flow, 'hi'));
		$this->assertNull($this->router->conditionReaching($flow, 'lo'));
		$this->assertNull($this->router->conditionReaching($flow, 'plain'));
		$this->assertNull($this->router->conditionReaching($flow, 'odd'));
		$this->assertNull($this->router->conditionReaching($flow, 'missing'));
		$this->assertSame($guard, $this->router->conditionReaching($flow, 'multi'));

	}//end testConditionReachingOutcomes()

	/**
	 * Every branch of resolveOutputTags().
	 *
	 * @return void
	 */
	public function testResolveOutputTagsOutcomes(): void {
		$flow  = [
			'edges' => [
				['from' => 'split', 'fromExit' => 'one', 'to' => ['hi']],
				['from' => 'split', 'fromExit' => 'two', 'to' => ['hi', 'lo']],
			],
		];
		$items = $this->router->resolveOutputTags(exits: $this->router->exitsOf(flow: $flow, transition: $this->transition(name: 'split', tos: ['hi', 'lo'])), items: [
				'scalar',
				$this->item([]),
				$this->item([], 'lo'),
				$this->item([], 'one'),
				$this->item([], 'two'),
				$this->item([], 'none'),
			]);

		$this->assertSame('scalar', $items[0]);
		$this->assertArrayNotHasKey('output', $items[1]);
		$this->assertSame('lo', $items[2]['output']);
		$this->assertSame('hi', $items[3]['output']);
		$this->assertArrayNotHasKey('output', $items[4]);
		$this->assertSame('none', $items[5]['output']);

	}//end testResolveOutputTagsOutcomes()
}//end class
