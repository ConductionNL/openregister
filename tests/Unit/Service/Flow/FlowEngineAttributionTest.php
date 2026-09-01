<?php

/**
 * Unit tests for the engine's flow-attribution frames.
 *
 * The engine pushes an attribution frame around each hop so every write the hop
 * causes is filed under that run and node. What these tests are really about is
 * the POP: a frame left standing attributes later writes — including a
 * different run's, since one worker advances several — to a run that has
 * already finished, and produces rows that look entirely correct.
 *
 * So the assertions that matter here are the ones taken AFTER a run ends, and
 * especially after one ends BADLY. A test that only checks the frame is right
 * during a successful step passes just as happily with the `finally` deleted.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-object-attribution/specs/flow-engine/spec.md
 */

namespace Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowDefinitionBuilder;
use OCA\OpenRegister\Service\Flow\FlowEngine;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\FlowStepDispatcher;
use OCA\OpenRegister\Service\Flow\FlowStop;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;

/**
 * A subject whose marking is a plain property.
 */
class AttributionSubject {

	public $marking = [];
}//end class

/**
 * Reads the ambient frame at the moment each step runs, then optionally fails.
 *
 * Sampling INSIDE the step is the point: the frame has to be correct while the
 * write would be happening, not merely at some point during the run.
 */
class AttributionSamplingDispatcher implements FlowStepDispatcher {

	/**
	 * The frame observed during each step, keyed by step id.
	 */
	public array $frames = [];

	public function __construct(
		private readonly FlowRunContext $context,
		private readonly ?string $failOn = null,
		private readonly ?string $throwKind = null,
	) {
	}//end __construct()

	public function dispatch(array $step, array $items, array $context): array {
		$name = (string)($step['id'] ?? '');
		$this->frames[$name] = $this->context->current();

		if ($this->failOn !== null && $name === $this->failOn) {
			if ($this->throwKind === 'stop') {
				throw new FlowStop('stopped on purpose');
			}

			if ($this->throwKind === 'suspend') {
				// Named argument: the first positional parameter is the resume
				// DateTime, not the reason.
				throw new FlowSuspension(reason: 'waiting on purpose');
			}

			throw new RuntimeException('step blew up');
		}

		$out = [];
		foreach ($items as $index => $item) {
			$out[] = FlowItems::item(json: (array)($item['json'] ?? []), binary: [], fromItemIndex: $index);
		}

		return $out;
	}//end dispatch()
}//end class

class FlowEngineAttributionTest extends TestCase {

	private FlowRunContext $context;

	private FlowEngine $engine;

	protected function setUp(): void {
		$this->context = new FlowRunContext();
		$this->engine = new FlowEngine(
			new FlowDefinitionBuilder(),
			$this->createMock(LoggerInterface::class),
			null,
			null,
			null,
			$this->context
		);
	}//end setUp()

	/**
	 * A two-node flow.
	 */
	private function linearFlow(): array {
		return [
			'id' => 'linear',
			'nodes' => [
				['id' => 'first', 'type' => 'test.step'],
				['id' => 'second', 'type' => 'test.step'],
			],
			'edges' => [['id' => 'first-second', 'from' => 'first', 'to' => 'second']],
		];
	}//end linearFlow()

	private function runFlow(array $flow, FlowStepDispatcher $dispatcher, array $context = []): array {
		return $this->engine->run(
			$flow,
			new MethodMarkingStore(false, 'marking'),
			new AttributionSubject(),
			$dispatcher,
			$context
		);
	}//end runFlow()

	/**
	 * The attribution context the engine is handed for a run.
	 */
	private function attributionContext(string $run = 'run-abc', int $base = 0): array {
		return [
			FlowRunContext::CONTEXT_RUN => $run,
			FlowRunContext::CONTEXT_BASE => $base,
		];
	}//end attributionContext()

	/**
	 * Each step sees its OWN node id and its own step number.
	 */
	public function testEachStepSeesItsOwnFrame(): void {
		$dispatcher = new AttributionSamplingDispatcher(context: $this->context);

		$this->runFlow($this->linearFlow(), $dispatcher, $this->attributionContext());

		$this->assertSame(
			['run' => 'run-abc', 'node' => 'first', 'step' => 0],
			$dispatcher->frames['first']
		);
		$this->assertSame(
			['run' => 'run-abc', 'node' => 'second', 'step' => 1],
			$dispatcher->frames['second']
		);
	}//end testEachStepSeesItsOwnFrame()

	/**
	 * The step number continues from the base, so a resumed run keeps numbering
	 * where it stopped instead of restarting and colliding with its own history.
	 */
	public function testStepNumbersContinueFromTheBase(): void {
		$dispatcher = new AttributionSamplingDispatcher(context: $this->context);

		$this->runFlow($this->linearFlow(), $dispatcher, $this->attributionContext(base: 12));

		$this->assertSame(12, $dispatcher->frames['first']['step']);
		$this->assertSame(13, $dispatcher->frames['second']['step']);
	}//end testStepNumbersContinueFromTheBase()

	/**
	 * 🔴 THE LEAK TEST. Nothing is attributed once the run is over.
	 *
	 * Delete the `finally` in FlowEngine and this is the assertion that goes
	 * red. Every other test in this file still passes.
	 */
	public function testNothingIsAttributedAfterASuccessfulRun(): void {
		$dispatcher = new AttributionSamplingDispatcher(context: $this->context);

		$this->runFlow($this->linearFlow(), $dispatcher, $this->attributionContext());

		$this->assertNull(
			$this->context->current(),
			'A write after the run must be unattributed; a standing frame files it under a finished run.'
		);
		$this->assertSame(0, $this->context->depth());
	}//end testNothingIsAttributedAfterASuccessfulRun()

	/**
	 * 🔴 A THROWING step still clears its frame.
	 *
	 * The failure path is the one where a success-path pop would have been
	 * skipped, so this is where a leak would actually happen in production.
	 */
	public function testAThrowingStepStillClearsItsFrame(): void {
		$dispatcher = new AttributionSamplingDispatcher(
			context: $this->context,
			failOn: 'first'
		);

		$this->runFlow($this->linearFlow(), $dispatcher, $this->attributionContext());

		$this->assertNull($this->context->current());
		$this->assertSame(0, $this->context->depth());
	}//end testAThrowingStepStillClearsItsFrame()

	/**
	 * 🔴 A stopped run leaves nothing behind. A Stop returns from INSIDE the
	 * catch, so only a `finally` pops it.
	 */
	public function testAStoppedRunClearsItsFrame(): void {
		$dispatcher = new AttributionSamplingDispatcher(
			context: $this->context,
			failOn: 'first',
			throwKind: 'stop'
		);

		$result = $this->runFlow($this->linearFlow(), $dispatcher, $this->attributionContext());

		$this->assertSame(FlowEngine::STATUS_STOPPED, $result['status']);
		$this->assertNull($this->context->current());
		$this->assertSame(0, $this->context->depth());
	}//end testAStoppedRunClearsItsFrame()

	/**
	 * 🔴 A suspended run leaves nothing behind either — and this is the one that
	 * matters most in practice, because a suspended run is precisely the one
	 * whose process goes on to do other things.
	 */
	public function testASuspendedRunClearsItsFrame(): void {
		$dispatcher = new AttributionSamplingDispatcher(
			context: $this->context,
			failOn: 'first',
			throwKind: 'suspend'
		);

		$result = $this->runFlow($this->linearFlow(), $dispatcher, $this->attributionContext());

		$this->assertSame(FlowEngine::STATUS_SUSPENDED, $result['status']);
		$this->assertNull($this->context->current());
		$this->assertSame(0, $this->context->depth());
	}//end testASuspendedRunClearsItsFrame()

	/**
	 * Two runs walked in sequence — as FlowRunWorker does — attribute to
	 * themselves and not to their predecessor.
	 */
	public function testASecondRunIsNotAttributedToTheFirst(): void {
		$first = new AttributionSamplingDispatcher(context: $this->context);
		$this->runFlow($this->linearFlow(), $first, $this->attributionContext(run: 'run-1'));

		$second = new AttributionSamplingDispatcher(context: $this->context);
		$this->runFlow($this->linearFlow(), $second, $this->attributionContext(run: 'run-2'));

		$this->assertSame('run-1', $first->frames['first']['run']);
		$this->assertSame('run-2', $second->frames['first']['run']);
		$this->assertNull($this->context->current());
	}//end testASecondRunIsNotAttributedToTheFirst()

	/**
	 * A run with no attribution context attributes nothing — the flow tester and
	 * the node unit tests dispatch this way, and must not crash or invent a run.
	 */
	public function testARunWithoutAttributionContextAttributesNothing(): void {
		$dispatcher = new AttributionSamplingDispatcher(context: $this->context);

		$this->runFlow($this->linearFlow(), $dispatcher);

		$this->assertNull($dispatcher->frames['first']);
		$this->assertNull($this->context->current());
	}//end testARunWithoutAttributionContextAttributesNothing()
}//end class
