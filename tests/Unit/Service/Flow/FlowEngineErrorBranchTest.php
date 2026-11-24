<?php

/**
 * onError: branch sends the failed items, each with json.error, down the node's error output; the rest walk on.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-error-branch-and-step-retry/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use InvalidArgumentException;
use OCA\OpenRegister\Service\Flow\FlowDefinitionBuilder;
use OCA\OpenRegister\Service\Flow\FlowEngine;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowItemsFailed;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunOrigin;
use OCA\OpenRegister\Service\Flow\FlowRunStart;
use OCA\OpenRegister\Service\Flow\FlowStepDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;

/**
 * Holds the marking for one walk.
 */
class ErrorBranchSubject {
	public array $marking = [];
}//end class

/**
 * Step `map` fails for the item ids it is told to; every step records the item ids it received.
 */
class ErrorBranchDispatcher implements FlowStepDispatcher {
	/**
	 * Step id => the items it received.
	 *
	 * @var array<string, list<array>>
	 */
	public array $received = [];

	/**
	 * @param list<int> $failIds  The item ids `map` fails for.
	 * @param bool      $whole    Whether `map` fails as a whole rather than per item.
	 */
	public function __construct(private array $failIds, private bool $whole=false) {
	}//end __construct()

	public function dispatch(array $step, array $items, array $context): array {
		$id = (string)($step['id'] ?? '');
		$this->received[$id] = $items;
		if ($id !== 'map' || $this->failIds === []) {
			return $items;
		}

		if ($this->whole === true) {
			throw new RuntimeException('mapping service down');
		}

		$succeeded = [];
		$failed    = [];
		foreach ($items as $item) {
			if (in_array($item['json']['id'], $this->failIds, true) === true) {
				$failed[] = ['item' => $item, 'error' => new RuntimeException('no mapping for record '.$item['json']['id'])];
				continue;
			}

			$succeeded[] = $item;
		}

		throw new FlowItemsFailed(succeeded: $succeeded, failed: $failed);
	}//end dispatch()

	/**
	 * @return list<int> The ids of the items a step received.
	 */
	public function idsAt(string $step): array {
		return array_map(static fn (array $item): int => $item['json']['id'], ($this->received[$step] ?? []));
	}//end idsAt()
}//end class

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowEngine
 * @covers \OCA\OpenRegister\Service\Flow\FlowStepFailure
 * @covers \OCA\OpenRegister\Service\Flow\FlowErrorBranch
 * @covers \OCA\OpenRegister\Service\Flow\FlowItemsFailed
 * @covers \OCA\OpenRegister\Service\Flow\FlowTokenRouter
 * @covers \OCA\OpenRegister\Service\Flow\FlowNodeDeclarations
 */
class FlowEngineErrorBranchTest extends TestCase {

	private static function flow(string $onError='branch', bool $errorEdge=true): array {
		$edges = [['id' => 'ok', 'from' => 'map', 'to' => 'next']];
		if ($errorEdge === true) {
			$edges[] = ['id' => 'ko', 'from' => 'map', 'fromExit' => 'error', 'to' => 'notify'];
		}

		return [
			'id' => 'branching',
			'nodes' => [
				['id' => 'map', 'type' => 'openregister.map', 'onError' => $onError],
				['id' => 'next', 'type' => 'openregister.set-fields'],
				['id' => 'notify', 'type' => 'openregister.notify'],
			],
			'edges' => $edges,
		];
	}//end flow()

	private function walk(array $flow, ErrorBranchDispatcher $dispatcher): array {
		$engine = new FlowEngine(new FlowDefinitionBuilder(), new NullLogger());

		return $engine->run(
			start: (new FlowRunStart(flow: $flow, store: new MethodMarkingStore(false, 'marking'), subject: new ErrorBranchSubject(), dispatcher: $dispatcher))
				->withContext(context: [FlowResumeState::CONTEXT_KEY => new FlowResumeState()]),
			origin: new FlowRunOrigin(items: [FlowItems::item(json: ['id' => 1]), FlowItems::item(json: ['id' => 2]), FlowItems::item(json: ['id' => 3])])
		);
	}//end walk()

	private static function entryOf(array $result, string $step): array {
		foreach ($result['log'] as $entry) {
			if (($entry['transition'] ?? '') === $step) {
				return $entry;
			}
		}

		self::fail('no log entry for '.$step);
	}//end entryOf()

	public function testOneFailedItemTakesTheErrorEdgeAndTwoWalkOn(): void {
		$dispatcher = new ErrorBranchDispatcher(failIds: [2]);

		$result = $this->walk(flow: self::flow(), dispatcher: $dispatcher);

		self::assertSame(FlowEngine::STATUS_COMPLETED, $result['status']);
		self::assertSame([1, 3], $dispatcher->idsAt(step: 'next'));
		self::assertSame([2], $dispatcher->idsAt(step: 'notify'));
		$error = $dispatcher->received['notify'][0]['json']['error'];
		self::assertSame('no mapping for record 2', $error['message']);
		self::assertSame('map', $error['step']);
		self::assertSame(1, $error['attempt']);
		self::assertNotEmpty($error['at']);

		$entry = self::entryOf(result: $result, step: 'map');
		self::assertSame('failed', $entry['status']);
		self::assertSame('branched', $entry['outcome']);
		self::assertSame(1, $entry['itemsBranched']);
	}//end testOneFailedItemTakesTheErrorEdgeAndTwoWalkOn()

	public function testAStepThatFailsAsAWholeSendsAllItsItemsDownTheBranch(): void {
		$dispatcher = new ErrorBranchDispatcher(failIds: [1], whole: true);

		$result = $this->walk(flow: self::flow(), dispatcher: $dispatcher);

		self::assertSame(FlowEngine::STATUS_COMPLETED, $result['status']);
		self::assertArrayNotHasKey('next', $dispatcher->received, 'the normal edge is not taken');
		self::assertSame([1, 2, 3], $dispatcher->idsAt(step: 'notify'));
		self::assertSame('mapping service down', $dispatcher->received['notify'][2]['json']['error']['message']);
		self::assertSame(3, self::entryOf(result: $result, step: 'map')['itemsBranched']);
	}//end testAStepThatFailsAsAWholeSendsAllItsItemsDownTheBranch()

	public function testASuccessfulBranchingStepNeverTakesTheErrorEdge(): void {
		$dispatcher = new ErrorBranchDispatcher(failIds: []);

		$result = $this->walk(flow: self::flow(), dispatcher: $dispatcher);

		self::assertSame(FlowEngine::STATUS_COMPLETED, $result['status']);
		self::assertSame([1, 2, 3], $dispatcher->idsAt(step: 'next'));
		self::assertArrayNotHasKey('notify', $dispatcher->received);
	}//end testASuccessfulBranchingStepNeverTakesTheErrorEdge()

	public function testUnderStopAPartialFailureStillEndsTheRun(): void {
		$dispatcher = new ErrorBranchDispatcher(failIds: [2]);

		$result = $this->walk(flow: self::flow(onError: 'stop', errorEdge: false), dispatcher: $dispatcher);

		self::assertSame(FlowEngine::STATUS_FAILED, $result['status']);
		self::assertSame('1 item(s) failed: no mapping for record 2', $result['error']);
		self::assertArrayNotHasKey('next', $dispatcher->received);
	}//end testUnderStopAPartialFailureStillEndsTheRun()

	public function testABranchWithoutAnErrorEdgeIsRefusedNamingTheNode(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Flow node "map" sets onError to "branch" but no edge leaves its "error" output');

		(new FlowDefinitionBuilder())->build(flow: self::flow(errorEdge: false));
	}//end testABranchWithoutAnErrorEdgeIsRefusedNamingTheNode()
}//end class
