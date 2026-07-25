<?php

/**
 * Unit tests for FlowEngine — the OpenRegister flow engine (ADR-065).
 *
 * These prove the two claims the engine choice rests on: that one Petri net
 * expresses both a linear pipeline and true parallel split/join, and that the
 * ported run lifecycle (onError policies, trace, ceiling) actually governs a run.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowDefinitionBuilder;
use OCA\OpenRegister\Service\Flow\FlowEngine;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowStepDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;

/** A subject whose marking is a plain property. */
class FlowSubject
{
    public $marking = [];
}

/** Records what ran, and can be told to fail on a given step. */
class RecordingDispatcher implements FlowStepDispatcher
{
    public array $dispatched = [];

    public function __construct(protected readonly ?string $failOn = null)
    {
    }

    /** Items this dispatcher was handed, per step id. */
    public array $seenItems = [];

    public function dispatch(array $step, array $items, array $context): array
    {
        $name = (string) ($step['id'] ?? '');
        $this->dispatched[] = $name;
        $this->seenItems[$name] = $items;

        if ($this->failOn !== null && $name === $this->failOn) {
            throw new RuntimeException('step blew up');
        }

        // One item out per item in, tagged with the step that produced it, so a
        // test can assert both the threading and the per-item pairing.
        $out = [];
        foreach ($items as $index => $item) {
            $json = (array) ($item['json'] ?? []);
            $json['ranBy'] = $name;
            $out[] = FlowItems::item(json: $json, binary: [], fromItemIndex: $index);
        }

        return $out;
    }
}

class FlowEngineTest extends TestCase
{
    private FlowEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new FlowEngine(
            new FlowDefinitionBuilder(),
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * Run a flow against a fresh subject.
     *
     * Named runFlow(), not run(): PHPUnit's TestCase::run() is final.
     */
    private function runFlow(array $flow, ?FlowStepDispatcher $dispatcher = null): array
    {
        $dispatcher ??= new RecordingDispatcher();
        return $this->engine->run(
            $flow,
            new MethodMarkingStore(false, 'marking'),
            new FlowSubject(),
            $dispatcher
        );
    }

    private function linearFlow(): array
    {
        return [
            'id'    => 'linear',
            'nodes' => [['id' => 'start'], ['id' => 'middle'], ['id' => 'end']],
            'edges' => [
                ['id' => 'first', 'from' => 'start', 'to' => 'middle'],
                ['id' => 'second', 'from' => 'middle', 'to' => 'end'],
            ],
        ];
    }

    public function testALinearFlowRunsEveryStepInOrderAndCompletes(): void
    {
        $dispatcher = new RecordingDispatcher();
        $result = $this->runFlow($this->linearFlow(), $dispatcher);

        $this->assertSame(FlowEngine::STATUS_COMPLETED, $result['status']);
        $this->assertSame(['first', 'second'], $dispatcher->dispatched);
    }

    public function testEachStepIsTracedInTheRunLog(): void
    {
        $result = $this->runFlow($this->linearFlow());

        $this->assertSame(
            [
                ['transition' => 'first', 'status' => 'completed', 'itemsIn' => 1, 'itemsOut' => 1],
                ['transition' => 'second', 'status' => 'completed', 'itemsIn' => 1, 'itemsOut' => 1],
            ],
            $result['log']
        );
    }

    public function testItemsAreThreadedFromEachStepIntoTheNext(): void
    {
        $dispatcher = new RecordingDispatcher();
        $result = $this->runFlow($this->linearFlow(), $dispatcher);

        // The second step sees what the first produced, not the run's seed.
        $this->assertSame('first', $dispatcher->seenItems['second'][0]['json']['ranBy']);
        $this->assertSame('second', $result['items'][0]['json']['ranBy']);
    }

    public function testARunSeedsExactlyOneItemFromTheSubject(): void
    {
        $dispatcher = new RecordingDispatcher();
        $this->runFlow($this->linearFlow(), $dispatcher);

        $this->assertCount(1, $dispatcher->seenItems['first']);
        $this->assertArrayHasKey('json', $dispatcher->seenItems['first'][0]);
        $this->assertArrayHasKey('binary', $dispatcher->seenItems['first'][0]);
        $this->assertArrayHasKey('pairedItem', $dispatcher->seenItems['first'][0]);
    }

    public function testAStepThatFansOutIsFollowedByOneCallPerItem(): void
    {
        // A step returning three items must hand all three to the next step:
        // this is the property the whole item model exists for.
        $fanOut = new class extends RecordingDispatcher {
            public function dispatch(array $step, array $items, array $context): array
            {
                $name = (string) ($step['id'] ?? '');
                $this->dispatched[] = $name;
                $this->seenItems[$name] = $items;

                if ($name !== 'first') {
                    return $items;
                }

                return [
                    FlowItems::item(json: ['n' => 1], binary: [], fromItemIndex: 0),
                    FlowItems::item(json: ['n' => 2], binary: [], fromItemIndex: 0),
                    FlowItems::item(json: ['n' => 3], binary: [], fromItemIndex: 0),
                ];
            }
        };

        $result = $this->runFlow($this->linearFlow(), $fanOut);

        $this->assertCount(3, $fanOut->seenItems['second']);
        $this->assertCount(3, $result['items']);
        // Provenance survives the hop: every item still points at input item 0.
        $this->assertSame(['item' => 0], $result['items'][0]['pairedItem']);
    }

    public function testAStepReturningNothingEndsThatBranchesData(): void
    {
        $filter = new class extends RecordingDispatcher {
            public function dispatch(array $step, array $items, array $context): array
            {
                $name = (string) ($step['id'] ?? '');
                $this->dispatched[] = $name;
                $this->seenItems[$name] = $items;

                return ($name === 'first') ? [] : $items;
            }
        };

        $result = $this->runFlow($this->linearFlow(), $filter);

        $this->assertSame([], $filter->seenItems['second']);
        $this->assertSame([], $result['items']);
    }

    /**
     * The claim the whole engine choice rests on: a join must not fire until
     * every inbound branch has arrived. openconnector's order-indexed model
     * cannot express this at all.
     */
    public function testAParallelSplitRunsBothBranchesAndTheJoinWaitsForBoth(): void
    {
        $dispatcher = new RecordingDispatcher();
        $result = $this->runFlow([
            'id'    => 'parallel',
            'nodes' => [
                ['id' => 'start'],
                ['id' => 'a_pending'], ['id' => 'b_pending'],
                ['id' => 'a_done'], ['id' => 'b_done'],
                ['id' => 'done'],
            ],
            'edges' => [
                ['id' => 'fork', 'from' => 'start', 'to' => ['a_pending', 'b_pending']],
                ['id' => 'do_a', 'from' => 'a_pending', 'to' => 'a_done'],
                ['id' => 'do_b', 'from' => 'b_pending', 'to' => 'b_done'],
                ['id' => 'join', 'from' => ['a_done', 'b_done'], 'to' => 'done'],
            ],
        ], $dispatcher);

        $this->assertSame(FlowEngine::STATUS_COMPLETED, $result['status']);
        // Both branches ran, and the join ran exactly once — after both.
        $this->assertContains('do_a', $dispatcher->dispatched);
        $this->assertContains('do_b', $dispatcher->dispatched);
        $this->assertSame(1, count(array_keys($dispatcher->dispatched, 'join')));
        $this->assertSame('join', end($dispatcher->dispatched));
    }

    public function testAJoinNeverFiresWhenOnlyOneBranchCanArrive(): void
    {
        // 'b' has no inbound edge and is not a source of the taken path, so the
        // join can never be enabled. The run stops where the graph stops rather
        // than firing a half-satisfied join.
        $dispatcher = new RecordingDispatcher();
        $result = $this->runFlow([
            'id'      => 'starved-join',
            'nodes'   => [['id' => 'a'], ['id' => 'b'], ['id' => 'done']],
            'edges'   => [['id' => 'join', 'from' => ['a', 'b'], 'to' => 'done']],
            'initial' => 'a',
        ], $dispatcher);

        $this->assertSame(FlowEngine::STATUS_COMPLETED, $result['status']);
        $this->assertSame([], $dispatcher->dispatched);
    }

    public function testOnErrorStopHaltsTheRunAndRecordsTheFailure(): void
    {
        $dispatcher = new RecordingDispatcher(failOn: 'first');
        $flow = $this->linearFlow();
        $flow['edges'][0]['onError'] = FlowEngine::ON_ERROR_STOP;

        $result = $this->runFlow($flow, $dispatcher);

        $this->assertSame(FlowEngine::STATUS_STOPPED, $result['status']);
        $this->assertSame(['first'], $dispatcher->dispatched);
        $this->assertSame('failed', $result['log'][0]['status']);
        $this->assertSame('step blew up', $result['log'][0]['error']);
    }

    public function testOnErrorContinueCarriesOnPastAFailedStep(): void
    {
        $dispatcher = new RecordingDispatcher(failOn: 'first');
        $flow = $this->linearFlow();
        $flow['edges'][0]['onError'] = FlowEngine::ON_ERROR_CONTINUE;

        $result = $this->runFlow($flow, $dispatcher);

        $this->assertSame(FlowEngine::STATUS_COMPLETED, $result['status']);
        // The marking advanced despite the failure, so the next step ran.
        $this->assertSame(['first', 'second'], $dispatcher->dispatched);
    }

    public function testOnErrorDeadLetterEndsTheRunInItsOwnState(): void
    {
        $dispatcher = new RecordingDispatcher(failOn: 'first');
        $flow = $this->linearFlow();
        $flow['edges'][0]['onError'] = FlowEngine::ON_ERROR_DEAD_LETTER;

        $result = $this->runFlow($flow, $dispatcher);

        $this->assertSame(FlowEngine::STATUS_DEAD_LETTER, $result['status']);
    }

    public function testAnUnknownErrorPolicyFailsSafeByStopping(): void
    {
        // A typo in `onError` must not silently mean "continue".
        $dispatcher = new RecordingDispatcher(failOn: 'first');
        $flow = $this->linearFlow();
        $flow['edges'][0]['onError'] = 'carry-on-regardless';

        $result = $this->runFlow($flow, $dispatcher);

        $this->assertSame(FlowEngine::STATUS_STOPPED, $result['status']);
    }

    public function testTheDefaultErrorPolicyIsStop(): void
    {
        $dispatcher = new RecordingDispatcher(failOn: 'first');
        $result = $this->runFlow($this->linearFlow(), $dispatcher);

        $this->assertSame(FlowEngine::STATUS_STOPPED, $result['status']);
    }

    public function testAMalformedFlowFailsLoudlyRatherThanSilently(): void
    {
        // x-openregister-flows swallows by design; this engine must not.
        $result = $this->runFlow(['id' => 'broken', 'nodes' => [], 'edges' => []]);

        $this->assertSame(FlowEngine::STATUS_FAILED, $result['status']);
        $this->assertSame('Flow declares no nodes; nothing to run.', $result['error']);
    }

    public function testAnUnboundedLoopIsAbortedAndReportedAsAFailure(): void
    {
        // A Petri net can express a cycle, so a drawn loop can run forever.
        // Hitting the ceiling is a failure, not a silent truncation.
        $result = $this->runFlow([
            'id'    => 'loop',
            'nodes' => [['id' => 'a'], ['id' => 'b']],
            'edges' => [
                ['id' => 'there', 'from' => 'a', 'to' => 'b'],
                ['id' => 'back', 'from' => 'b', 'to' => 'a'],
            ],
        ]);

        $this->assertSame(FlowEngine::STATUS_FAILED, $result['status']);
        $this->assertStringContainsString('unbounded loop', $result['error']);
    }

    public function testAPinnedStepIsNotExecutedAndItsOutputIsUsed(): void
    {
        // Pin the first step's output. The dispatcher must never run it, the log
        // must mark it pinned, and the next step must see the pinned items.
        $dispatcher = new RecordingDispatcher();
        $pinned = [FlowItems::item(json: ['pinned' => true])];

        $result = $this->engine->run(
            $this->linearFlow(),
            new MethodMarkingStore(false, 'marking'),
            new FlowSubject(),
            $dispatcher,
            ['pins' => ['first' => $pinned]]
        );

        $this->assertSame(FlowEngine::STATUS_COMPLETED, $result['status']);
        // Only 'second' ran — 'first' was served from the pin.
        $this->assertSame(['second'], $dispatcher->dispatched);
        $this->assertSame('pinned', $result['log'][0]['status']);
        // The next step saw the pinned output, not a re-execution.
        $this->assertTrue($dispatcher->seenItems['second'][0]['json']['pinned']);
    }

    public function testAFlowLevelPinIsUsedWhenTheRunSuppliesNone(): void
    {
        $flow = $this->linearFlow();
        $flow['pins'] = ['first' => [FlowItems::item(json: ['source' => 'flow-pin'])]];

        $dispatcher = new RecordingDispatcher();
        $result = $this->runFlow($flow, $dispatcher);

        $this->assertSame(['second'], $dispatcher->dispatched);
        $this->assertSame('flow-pin', $dispatcher->seenItems['second'][0]['json']['source']);
    }

    public function testARunPinOverridesAFlowPin(): void
    {
        $flow = $this->linearFlow();
        $flow['pins'] = ['first' => [FlowItems::item(json: ['source' => 'flow-pin'])]];

        $dispatcher = new RecordingDispatcher();
        $result = $this->engine->run(
            $flow,
            new MethodMarkingStore(false, 'marking'),
            new FlowSubject(),
            $dispatcher,
            ['pins' => ['first' => [FlowItems::item(json: ['source' => 'run-pin'])]]]
        );

        // The run's pin wins over the flow's own.
        $this->assertSame('run-pin', $dispatcher->seenItems['second'][0]['json']['source']);
    }

    public function testAPinnedStepThatWouldHaveFailedStillSucceeds(): void
    {
        // The whole point of a pin: skip the step that blows up (or hits a real
        // API) and carry on with its stored output.
        $dispatcher = new RecordingDispatcher(failOn: 'first');
        $result = $this->engine->run(
            $this->linearFlow(),
            new MethodMarkingStore(false, 'marking'),
            new FlowSubject(),
            $dispatcher,
            ['pins' => ['first' => [FlowItems::item(json: ['ok' => true])]]]
        );

        $this->assertSame(FlowEngine::STATUS_COMPLETED, $result['status']);
    }

    public function testPerItemRoutingSendsEachItemDownItsTaggedBranch(): void
    {
        // A router edge start -> [high, low], then a step off each. The router
        // tags n>5 for 'high', the rest for 'low'. Each branch must see only its
        // own items — this is what per-item routing means.
        $router = new class extends RecordingDispatcher {
            public function dispatch(array $step, array $items, array $context): array
            {
                $name = (string) ($step['id'] ?? '');
                $this->dispatched[] = $name;
                $this->seenItems[$name] = $items;

                if ($name !== 'route') {
                    return $items;
                }

                $out = [];
                foreach ($items as $i => $item) {
                    $n = (int) ($item['json']['n'] ?? 0);
                    $out[] = FlowItems::item(
                        json: $item['json'],
                        binary: [],
                        fromItemIndex: $i,
                        output: ($n > 5 ? 'high' : 'low')
                    );
                }

                return $out;
            }
        };

        $flow = [
            'id'    => 'route',
            'nodes' => [['id' => 'start'], ['id' => 'high'], ['id' => 'low'], ['id' => 'hEnd'], ['id' => 'lEnd']],
            'edges' => [
                ['id' => 'route', 'from' => 'start', 'to' => ['high', 'low']],
                ['id' => 'doHigh', 'from' => 'high', 'to' => 'hEnd'],
                ['id' => 'doLow', 'from' => 'low', 'to' => 'lEnd'],
            ],
        ];

        $seed = [
            FlowItems::item(json: ['n' => 1]),
            FlowItems::item(json: ['n' => 7]),
            FlowItems::item(json: ['n' => 3]),
        ];

        $result = $this->engine->run(
            $flow,
            new MethodMarkingStore(false, 'marking'),
            new FlowSubject(),
            $router,
            [],
            $seed
        );

        $this->assertSame(FlowEngine::STATUS_COMPLETED, $result['status']);
        // High branch saw only n=7; low branch saw n=1 and n=3.
        $this->assertSame([7], array_map(static fn (array $i): int => $i['json']['n'], $router->seenItems['doHigh']));
        $this->assertSame([1, 3], array_map(static fn (array $i): int => $i['json']['n'], $router->seenItems['doLow']));
        // The routing tag does not linger on the items the branch step sees.
        $this->assertArrayNotHasKey('output', $router->seenItems['doHigh'][0]);
    }

    public function testAnUntaggedSplitStillBroadcastsToEveryBranch(): void
    {
        // The no-regression guarantee: a fork whose items carry no output tag
        // delivers every item to every branch, exactly as before per-item routing.
        $dispatcher = new RecordingDispatcher();
        $result = $this->runFlow([
            'id'    => 'fork',
            'nodes' => [['id' => 'start'], ['id' => 'a'], ['id' => 'b'], ['id' => 'aEnd'], ['id' => 'bEnd']],
            'edges' => [
                ['id' => 'fork', 'from' => 'start', 'to' => ['a', 'b']],
                ['id' => 'doA', 'from' => 'a', 'to' => 'aEnd'],
                ['id' => 'doB', 'from' => 'b', 'to' => 'bEnd'],
            ],
        ], $dispatcher);

        $this->assertSame(FlowEngine::STATUS_COMPLETED, $result['status']);
        // Both branches saw the (single, untagged) item.
        $this->assertCount(1, $dispatcher->seenItems['doA']);
        $this->assertCount(1, $dispatcher->seenItems['doB']);
    }

    public function testRunFromHereStartsAtTheChosenNodeAndSkipsWhatIsBefore(): void
    {
        // A three-step line start -> middle -> end. Starting at 'middle' must
        // run only the 'second' step (middle -> end); 'first' never runs.
        $dispatcher = new RecordingDispatcher();
        $seed = [FlowItems::item(json: ['from' => 'run-from-here'])];

        $result = $this->engine->run(
            $this->linearFlow(),
            new MethodMarkingStore(false, 'marking'),
            new FlowSubject(),
            $dispatcher,
            [],
            $seed,
            'middle'
        );

        $this->assertSame(FlowEngine::STATUS_COMPLETED, $result['status']);
        $this->assertSame(['second'], $dispatcher->dispatched);
        // The chosen start's step saw the supplied seed, not a subject item.
        $this->assertSame('run-from-here', $dispatcher->seenItems['second'][0]['json']['from']);
    }

    public function testRunFromAnUnknownNodeFailsLoudly(): void
    {
        $result = $this->engine->run(
            $this->linearFlow(),
            new MethodMarkingStore(false, 'marking'),
            new FlowSubject(),
            new RecordingDispatcher(),
            [],
            null,
            'no-such-node'
        );

        $this->assertSame(FlowEngine::STATUS_FAILED, $result['status']);
    }

    public function testAnEmptyStartAtIsIgnoredAndTheFlowRunsFromItsStart(): void
    {
        $dispatcher = new RecordingDispatcher();
        $result = $this->engine->run(
            $this->linearFlow(),
            new MethodMarkingStore(false, 'marking'),
            new FlowSubject(),
            $dispatcher,
            [],
            null,
            ''
        );

        $this->assertSame(FlowEngine::STATUS_COMPLETED, $result['status']);
        $this->assertSame(['first', 'second'], $dispatcher->dispatched);
    }

    public function testAStepCarriesItsOwnEdgeConfigToTheDispatcher(): void
    {
        $flow = $this->linearFlow();
        $flow['edges'][0]['type'] = 'email';
        $flow['edges'][0]['configRef'] = 'abc-123';

        $seen = null;
        $dispatcher = new class($seen) implements FlowStepDispatcher {
            public array $steps = [];

            public function __construct(&$seen)
            {
            }

            public function dispatch(array $step, array $items, array $context): array
            {
                $this->steps[] = $step;
                return $items;
            }
        };

        $this->engine->run($flow, new MethodMarkingStore(false, 'marking'), new FlowSubject(), $dispatcher);

        $this->assertSame('email', $dispatcher->steps[0]['type']);
        $this->assertSame('abc-123', $dispatcher->steps[0]['configRef']);
    }
}
