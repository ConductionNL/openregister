<?php

/**
 * The resume answer is scoped to the node that suspended.
 *
 * A resume signal wakes the RUN, but it answers one NODE. These tests pin the
 * engine-level contract that broke live on 2026-09-01 (openregister
 * 2.0.15-unstable, runs f8996ccc and ca50c56c): after one wait node was
 * answered, every LATER wait node the resumed walk entered read the same
 * payload out of the shared `context.signal` key as its own answer and
 * completed in zero milliseconds instead of suspending — a run with two
 * approval points raced to its end on one approval, and a second decision
 * step inherited the first decision's reference because it never suspended
 * at all.
 *
 * The walk here is the REAL seam: the real engine walking a real graph
 * through the real dispatcher, with only the node itself stubbed to the
 * await-signal shape (consume `context.signal` when present, otherwise
 * record the ask in the node's own resume slot and suspend).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowDefinitionBuilder;
use OCA\OpenRegister\Service\Flow\FlowEngine;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\RegistryStepDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;

/**
 * An await-signal-shaped node: consumes the signal when one is visible,
 * otherwise records its ask in its own slot and suspends.
 *
 * Mirrors what `AwaitSignalNode`, dossiq's askPerson and dossiq's
 * requestDecision all do at this seam, including the per-ask reference a
 * decision node mints (the correlation the second node must NOT inherit).
 */
class ScopedAskStub implements IFlowNode {

	/**
	 * The signal each node id saw on each entry, in order.
	 *
	 * @var array<string, array<int, mixed>>
	 */
	public array $sawSignal = [];

	/**
	 * How many asks have been minted, so each ref is distinct.
	 *
	 * @var int
	 */
	private int $asks = 0;

	public function getId(): string {
		return 'test.scoped-ask';
	}

	public function getDisplayName(): string {
		return 'Ask';
	}

	public function getDescription(): string {
		return 'Waits for an answer.';
	}

	public function getIcon(): string {
		return 'i.svg';
	}

	public function isAvailableForScope(int $scope): bool {
		return true;
	}

	public function validateConfig(array $config): void {
	}

	public function execute(array $items, array $config, array $context): array {
		$slot = $context[FlowNodeResumeState::CONTEXT_KEY];
		$nodeId = $slot->nodeId();

		$signal = ($context[FlowRunService::SIGNAL_CONTEXT_KEY] ?? null);
		$this->sawSignal[$nodeId][] = $signal;

		if (is_array($signal) === true && trim((string)($signal['decision'] ?? '')) !== '') {
			foreach ($items as $index => $item) {
				$item['json'][(string)($config['signalKey'] ?? 'signal')] = $signal;
				$items[$index] = $item;
			}

			return $items;
		}

		if ($slot->has(key: 'ref') === false) {
			$this->asks++;
			$slot->merge(
				values: [
					'askedAt' => '2026-09-01T00:00:00+00:00',
					'ref' => sprintf('ask-%d', $this->asks),
				]
			);
		}

		throw new FlowSuspension(resumeAt: null, reason: 'waiting for an answer');
	}
}//end class

/**
 * A subject whose marking is a plain property, as the engine tests use.
 */
class ResumeScopeSubject {

	public $marking = [];
}//end class

class FlowEngineResumeScopeTest extends TestCase {

	/**
	 * The stub both wait steps resolve to.
	 *
	 * @var ScopedAskStub
	 */
	private ScopedAskStub $ask;

	/**
	 * The engine under test.
	 *
	 * @var FlowEngine
	 */
	private FlowEngine $engine;

	/**
	 * The dispatcher — the REAL one, because the scoping under test lives in it.
	 *
	 * @var RegistryStepDispatcher
	 */
	private RegistryStepDispatcher $dispatcher;

	protected function setUp(): void {
		$this->ask = new ScopedAskStub();

		$registry = $this->createMock(FlowNodeRegistry::class);
		$registry->method('get')->willReturn($this->ask);

		$this->engine = new FlowEngine(
			new FlowDefinitionBuilder(),
			$this->createMock(LoggerInterface::class)
		);
		$this->dispatcher = new RegistryStepDispatcher(registry: $registry);
	}//end setUp()

	/**
	 * Two sequential wait nodes, one edge between them.
	 */
	private function twoAsksFlow(): array {
		return [
			'id' => 'f-scope',
			'nodes' => [
				['id' => 'first-ask', 'type' => 'test.scoped-ask', 'config' => ['signalKey' => 'firstAnswer']],
				['id' => 'second-ask', 'type' => 'test.scoped-ask', 'config' => ['signalKey' => 'secondAnswer']],
			],
			'edges' => [
				['id' => 'e1', 'from' => 'first-ask', 'to' => 'second-ask'],
			],
		];
	}//end twoAsksFlow()

	/**
	 * Answering the first wait leaves the second SUSPENDED, asking fresh.
	 *
	 * The walk that consumed the first answer must not hand the same payload
	 * to the second wait: the second suspends with an ask of its own, and the
	 * run does not race to the end on one answer.
	 */
	public function testAnsweringTheFirstWaitLeavesTheSecondSuspendedWithItsOwnAsk(): void {
		$flow = $this->twoAsksFlow();
		$subject = new ResumeScopeSubject();
		$store = new MethodMarkingStore(false, 'marking');
		$state = new FlowResumeState();

		// Walk 1: the first ask suspends the run.
		$first = $this->engine->run(
			$flow,
			$store,
			$subject,
			$this->dispatcher,
			[FlowResumeState::CONTEXT_KEY => $state]
		);
		$this->assertSame(FlowEngine::STATUS_SUSPENDED, $first['status']);
		$this->assertTrue($state->forNode(nodeId: 'first-ask')->has(key: 'askedAt'));

		// Walk 2: the answer arrives, exactly as FlowRunService seeds it — the
		// stored slots and the signal, on a context whose run-wide `resuming`
		// flag is true for EVERY node.
		$payload = ['decision' => 'approved', 'node' => 'first-ask', 'taskId' => 'task-1'];
		$second = $this->engine->run(
			$flow,
			$store,
			$subject,
			$this->dispatcher,
			[
				'resuming' => true,
				FlowResumeState::CONTEXT_KEY => FlowResumeState::fromArray($state->all()),
				FlowRunService::SIGNAL_CONTEXT_KEY => $payload,
			]
		);

		$this->assertSame(
			FlowEngine::STATUS_SUSPENDED,
			$second['status'],
			'one answer must advance the run to the NEXT question, not to the end'
		);

		$statuses = [];
		foreach ($second['log'] as $entry) {
			$statuses[$entry['transition']] = $entry['status'];
		}

		$this->assertSame('completed', $statuses['first-ask'], 'the answered node completes');
		$this->assertSame('suspended', $statuses['second-ask'], 'the next wait suspends fresh');

		$this->assertSame(
			[null, $payload],
			$this->ask->sawSignal['first-ask'],
			'the answered node reads the payload on its resume, and only then'
		);
		$this->assertSame(
			[null],
			$this->ask->sawSignal['second-ask'],
			'the second node never sees the first answer'
		);

		$fresh = ($second['context'][FlowResumeState::CONTEXT_KEY] ?? null);
		$this->assertInstanceOf(FlowResumeState::class, $fresh);
		$this->assertTrue(
			$fresh->forNode(nodeId: 'second-ask')->has(key: 'askedAt'),
			'the second node recorded an ask of its own'
		);
		$this->assertSame(
			[],
			$fresh->forNode(nodeId: 'first-ask')->all(),
			'the answered node keeps no slot'
		);
	}//end testAnsweringTheFirstWaitLeavesTheSecondSuspendedWithItsOwnAsk()

	/**
	 * The decision-node variant: the second ask mints its OWN reference.
	 *
	 * Run ca50c56c's defect in miniature — the second decision node carried
	 * the FIRST decision's `decisionRef`, because it consumed the first's
	 * outcome instead of suspending and creating a decision of its own.
	 */
	public function testTheSecondAskNeverInheritsTheFirstsCorrelation(): void {
		$flow = $this->twoAsksFlow();
		$subject = new ResumeScopeSubject();
		$store = new MethodMarkingStore(false, 'marking');
		$state = new FlowResumeState();

		$this->engine->run($flow, $store, $subject, $this->dispatcher, [FlowResumeState::CONTEXT_KEY => $state]);
		$firstRef = $state->forNode(nodeId: 'first-ask')->get(key: 'ref');
		$this->assertNotNull($firstRef);

		$second = $this->engine->run(
			$flow,
			$store,
			$subject,
			$this->dispatcher,
			[
				'resuming' => true,
				FlowResumeState::CONTEXT_KEY => FlowResumeState::fromArray($state->all()),
				FlowRunService::SIGNAL_CONTEXT_KEY => ['decision' => 'completed', 'decisionRef' => $firstRef],
			]
		);

		$fresh = $second['context'][FlowResumeState::CONTEXT_KEY];
		$secondRef = $fresh->forNode(nodeId: 'second-ask')->get(key: 'ref');

		$this->assertNotNull($secondRef, 'the second ask creates its own reference');
		$this->assertNotSame($firstRef, $secondRef, 'the second ask must not ride the first decision');

		// And the second node's slot holds nothing of the first's payload.
		$this->assertNull($fresh->forNode(nodeId: 'second-ask')->get(key: 'decisionRef'));
	}//end testTheSecondAskNeverInheritsTheFirstsCorrelation()
}//end class
