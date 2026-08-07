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

/**
 * A subject whose marking is a plain property.
 */
class FlowSubject
{

    public $marking = [];
}//end class

/**
 * Records what ran, and can be told to fail on a given step.
 */
class RecordingDispatcher implements FlowStepDispatcher
{

    public array $dispatched = [];

    public function __construct(protected readonly ?string $failOn=null)
    {
    }//end __construct()

    /**
     * Items this dispatcher was handed, per step id.
     */
    public array $seenItems = [];

    public function dispatch(array $step, array $items, array $context): array
    {
        $name = (string) ($step['id'] ?? '');
        $this->dispatched[]     = $name;
        $this->seenItems[$name] = $items;

        if ($this->failOn !== null && $name === $this->failOn) {
            throw new RuntimeException('step blew up');
        }

        // One item out per item in, tagged with the step that produced it, so a
        // test can assert both the threading and the per-item pairing.
        $out = [];
        foreach ($items as $index => $item) {
            $json          = (array) ($item['json'] ?? []);
            $json['ranBy'] = $name;
            $out[]         = FlowItems::item(json: $json, binary: [], fromItemIndex: $index);
        }

        return $out;
    }//end dispatch()
}//end class

class FlowEngineTest extends TestCase
{

    private FlowEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new FlowEngine(
            new FlowDefinitionBuilder(),
            $this->createMock(LoggerInterface::class)
        );
    }//end setUp()

    /**
     * Run a flow against a fresh subject.
     *
     * Named runFlow(), not run(): PHPUnit's TestCase::run() is final.
     */
    private function runFlow(array $flow, ?FlowStepDispatcher $dispatcher=null): array
    {
        $dispatcher ??= new RecordingDispatcher();
        return $this->engine->run(
            $flow,
            new MethodMarkingStore(false, 'marking'),
            new FlowSubject(),
            $dispatcher
        );
    }//end runFlow()

    /**
     * A node is the ACTION and an edge is SEQUENCE (or-flow-action-nodes).
     *
     * These fixtures used to be the inverse — nodes were bare places and the
     * step rode on the edge — so converting them is the graph dual: the two
     * STEPS (`first`, `second`) become the two nodes, and the place they met at
     * becomes the edge between them. The step count and order are unchanged,
     * which is why every assertion below still reads the same.
     */
    private function linearFlow(): array
    {
        return [
            'id'    => 'linear',
            'nodes' => [
                ['id' => 'first', 'type' => 'test.step'],
                ['id' => 'second', 'type' => 'test.step'],
            ],
            'edges' => [['id' => 'first-second', 'from' => 'first', 'to' => 'second']],
        ];
    }//end linearFlow()

    public function testALinearFlowRunsEveryStepInOrderAndCompletes(): void
    {
        $dispatcher = new RecordingDispatcher();
        $result     = $this->runFlow($this->linearFlow(), $dispatcher);

        $this->assertSame(FlowEngine::STATUS_COMPLETED, $result['status']);
        $this->assertSame(['first', 'second'], $dispatcher->dispatched);
    }//end testALinearFlowRunsEveryStepInOrderAndCompletes()

    public function testEachStepIsTracedInTheRunLog(): void
    {
        $result = $this->runFlow($this->linearFlow());

        // The stable fields, asserted exactly. `durationMs` is deliberately not
        // among them — it is wall-clock and asserting a value would make this
        // test fail on a slow machine rather than on a real regression.
        $stable = array_map(
            static fn (array $e): array => [
                'transition' => $e['transition'],
                'status'     => $e['status'],
                'itemsIn'    => $e['itemsIn'],
                'itemsOut'   => $e['itemsOut'],
            ],
            $result['log']
        );

        $this->assertSame(
            [
                ['transition' => 'first', 'status' => 'completed', 'itemsIn' => 1, 'itemsOut' => 1],
                ['transition' => 'second', 'status' => 'completed', 'itemsIn' => 1, 'itemsOut' => 1],
            ],
            $stable
        );

        // Every entry carries the node TYPE and a duration, because those are
        // what the step rows are built from — a trace without them produces
        // history that cannot answer "which node type fails".
        foreach ($result['log'] as $entry) {
            $this->assertArrayHasKey('type', $entry);
            $this->assertArrayHasKey('durationMs', $entry);
            $this->assertIsInt($entry['durationMs']);
        }
    }//end testEachStepIsTracedInTheRunLog()

    public function testItemsAreThreadedFromEachStepIntoTheNext(): void
    {
        $dispatcher = new RecordingDispatcher();
        $result     = $this->runFlow($this->linearFlow(), $dispatcher);

        // The second step sees what the first produced, not the run's seed.
        $this->assertSame('first', $dispatcher->seenItems['second'][0]['json']['ranBy']);
        $this->assertSame('second', $result['items'][0]['json']['ranBy']);
    }//end testItemsAreThreadedFromEachStepIntoTheNext()

    public function testARunSeedsExactlyOneItemFromTheSubject(): void
    {
        $dispatcher = new RecordingDispatcher();
        $this->runFlow($this->linearFlow(), $dispatcher);

        $this->assertCount(1, $dispatcher->seenItems['first']);
        $this->assertArrayHasKey('json', $dispatcher->seenItems['first'][0]);
        $this->assertArrayHasKey('binary', $dispatcher->seenItems['first'][0]);
        $this->assertArrayHasKey('pairedItem', $dispatcher->seenItems['first'][0]);
    }//end testARunSeedsExactlyOneItemFromTheSubject()

    public function testAStepThatFansOutIsFollowedByOneCallPerItem(): void
    {
        // A step returning three items must hand all three to the next step:
        // this is the property the whole item model exists for.
        $fanOut = new class extends RecordingDispatcher {
            public function dispatch(array $step, array $items, array $context): array
            {
                $name = (string) ($step['id'] ?? '');
                $this->dispatched[]     = $name;
                $this->seenItems[$name] = $items;

                if ($name !== 'first') {
                    return $items;
                }

                return [
                    FlowItems::item(json: ['n' => 1], binary: [], fromItemIndex: 0),
                    FlowItems::item(json: ['n' => 2], binary: [], fromItemIndex: 0),
                    FlowItems::item(json: ['n' => 3], binary: [], fromItemIndex: 0),
                ];
            }//end dispatch()
        };

        $result = $this->runFlow($this->linearFlow(), $fanOut);

        $this->assertCount(3, $fanOut->seenItems['second']);
        $this->assertCount(3, $result['items']);
        // Provenance survives the hop: every item still points at input item 0.
        $this->assertSame(['item' => 0], $result['items'][0]['pairedItem']);
    }//end testAStepThatFansOutIsFollowedByOneCallPerItem()

    public function testAStepReturningNothingEndsThatBranchesData(): void
    {
        $filter = new class extends RecordingDispatcher {
            public function dispatch(array $step, array $items, array $context): array
            {
                $name = (string) ($step['id'] ?? '');
                $this->dispatched[]     = $name;
                $this->seenItems[$name] = $items;

                return ($name === 'first') ? [] : $items;
            }//end dispatch()
        };

        $result = $this->runFlow($this->linearFlow(), $filter);

        $this->assertSame([], $filter->seenItems['second']);
        $this->assertSame([], $result['items']);
    }//end testAStepReturningNothingEndsThatBranchesData()

    /**
     * The claim the whole engine choice rests on: a join must not fire until
     * every inbound branch has arrived. openconnector's order-indexed model
     * cannot express this at all.
     */
    public function testAParallelSplitRunsBothBranchesAndTheJoinWaitsForBoth(): void
    {
        $dispatcher = new RecordingDispatcher();
        $result     = $this->runFlow(
                [
                    'id'    => 'parallel',
                    'nodes' => [
                        ['id' => 'fork', 'type' => 'test.step'],
                        ['id' => 'do_a', 'type' => 'test.step'],
                        ['id' => 'do_b', 'type' => 'test.step'],
                        // `join: true` is what makes this a SYNCHRONISING join.
                        // Converging edges alone are a merge — the node fires
                        // after whichever predecessor arrives first — because
                        // that is the behaviour real flows depend on.
                        ['id' => 'join', 'type' => 'test.step', 'join' => true],
                    ],
                    'edges' => [
                        ['id' => 'fork-a', 'from' => 'fork', 'to' => 'do_a'],
                        ['id' => 'fork-b', 'from' => 'fork', 'to' => 'do_b'],
                        ['id' => 'a-join', 'from' => 'do_a', 'to' => 'join'],
                        ['id' => 'b-join', 'from' => 'do_b', 'to' => 'join'],
                    ],
                ],
                $dispatcher
                );

        $this->assertSame(FlowEngine::STATUS_COMPLETED, $result['status']);
        // Both branches ran, and the join ran exactly once — after both.
        $this->assertContains('do_a', $dispatcher->dispatched);
        $this->assertContains('do_b', $dispatcher->dispatched);
        $this->assertSame(1, count(array_keys($dispatcher->dispatched, 'join')));
        $this->assertSame('join', end($dispatcher->dispatched));
    }//end testAParallelSplitRunsBothBranchesAndTheJoinWaitsForBoth()

    public function testAJoinNeverFiresWhenOnlyOneBranchCanArrive(): void
    {
        // The run starts at 'a', so 'b' never fires and the join's second input
        // never receives a token. The run stops where the flow stops rather than
        // firing a half-satisfied join.
        $dispatcher = new RecordingDispatcher();
        $result     = $this->runFlow(
                [
                    'id'      => 'starved-join',
                    'nodes'   => [
                        ['id' => 'a', 'type' => 'test.step'],
                        ['id' => 'b', 'type' => 'test.step'],
                        ['id' => 'done', 'type' => 'test.step', 'join' => true],
                    ],
                    'edges'   => [
                        ['id' => 'a-done', 'from' => 'a', 'to' => 'done'],
                        ['id' => 'b-done', 'from' => 'b', 'to' => 'done'],
                    ],
                    'initial' => 'a',
                ],
                $dispatcher
                );

        $this->assertSame(FlowEngine::STATUS_COMPLETED, $result['status']);

        // 'a' IS an action now and does run — under the previous model it was a
        // bare place, which is why this used to assert that nothing dispatched
        // at all. What the test is really about is the JOIN, and that must not
        // fire on one token.
        $this->assertSame(['a'], $dispatcher->dispatched);
        $this->assertNotContains('done', $dispatcher->dispatched, 'A starved join must never fire.');
    }//end testAJoinNeverFiresWhenOnlyOneBranchCanArrive()

    public function testOnErrorStopHaltsTheRunAndRecordsTheFailure(): void
    {
        $dispatcher = new RecordingDispatcher(failOn: 'first');
        $flow       = $this->linearFlow();
        $flow['nodes'][0]['onError'] =FlowEngine::ON_ERROR_STOP;

        $result = $this->runFlow($flow, $dispatcher);

        $this->assertSame(FlowEngine::STATUS_STOPPED, $result['status']);
        $this->assertSame(['first'], $dispatcher->dispatched);
        $this->assertSame('failed', $result['log'][0]['status']);
        $this->assertSame('step blew up', $result['log'][0]['error']);
    }//end testOnErrorStopHaltsTheRunAndRecordsTheFailure()

    public function testOnErrorContinueCarriesOnPastAFailedStep(): void
    {
        $dispatcher = new RecordingDispatcher(failOn: 'first');
        $flow       = $this->linearFlow();
        $flow['nodes'][0]['onError'] =FlowEngine::ON_ERROR_CONTINUE;

        $result = $this->runFlow($flow, $dispatcher);

        $this->assertSame(FlowEngine::STATUS_COMPLETED, $result['status']);
        // The marking advanced despite the failure, so the next step ran.
        $this->assertSame(['first', 'second'], $dispatcher->dispatched);
    }//end testOnErrorContinueCarriesOnPastAFailedStep()

    public function testOnErrorDeadLetterEndsTheRunInItsOwnState(): void
    {
        $dispatcher = new RecordingDispatcher(failOn: 'first');
        $flow       = $this->linearFlow();
        $flow['nodes'][0]['onError'] =FlowEngine::ON_ERROR_DEAD_LETTER;

        $result = $this->runFlow($flow, $dispatcher);

        $this->assertSame(FlowEngine::STATUS_DEAD_LETTER, $result['status']);
    }//end testOnErrorDeadLetterEndsTheRunInItsOwnState()

    public function testAnUnknownErrorPolicyFailsSafeByStopping(): void
    {
        // A typo in `onError` must not silently mean "continue".
        $dispatcher = new RecordingDispatcher(failOn: 'first');
        $flow       = $this->linearFlow();
        $flow['nodes'][0]['onError'] ='carry-on-regardless';

        $result = $this->runFlow($flow, $dispatcher);

        $this->assertSame(FlowEngine::STATUS_STOPPED, $result['status']);
    }//end testAnUnknownErrorPolicyFailsSafeByStopping()

    public function testTheDefaultErrorPolicyIsStop(): void
    {
        $dispatcher = new RecordingDispatcher(failOn: 'first');
        $result     = $this->runFlow($this->linearFlow(), $dispatcher);

        $this->assertSame(FlowEngine::STATUS_STOPPED, $result['status']);
    }//end testTheDefaultErrorPolicyIsStop()

    public function testAMalformedFlowFailsLoudlyRatherThanSilently(): void
    {
        // x-openregister-flows swallows by design; this engine must not.
        $result = $this->runFlow(['id' => 'broken', 'nodes' => [], 'edges' => []]);

        $this->assertSame(FlowEngine::STATUS_FAILED, $result['status']);
        $this->assertSame('Flow declares no nodes; nothing to run.', $result['error']);
    }//end testAMalformedFlowFailsLoudlyRatherThanSilently()

    public function testAnUnboundedLoopIsAbortedAndReportedAsAFailure(): void
    {
        // A Petri net can express a cycle, so a drawn loop can run forever.
        // Hitting the ceiling is a failure, not a silent truncation.
        $result = $this->runFlow(
                [
                    'id'    => 'loop',
                    'nodes' => [
                        ['id' => 'a', 'type' => 'test.step'],
                        ['id' => 'b', 'type' => 'test.step'],
                    ],
                    'edges' => [
                        ['id' => 'there', 'from' => 'a', 'to' => 'b'],
                        ['id' => 'back', 'from' => 'b', 'to' => 'a'],
                    ],
                ]
                );

        $this->assertSame(FlowEngine::STATUS_FAILED, $result['status']);
        $this->assertStringContainsString('unbounded loop', $result['error']);
    }//end testAnUnboundedLoopIsAbortedAndReportedAsAFailure()

    public function testAPinnedStepIsNotExecutedAndItsOutputIsUsed(): void
    {
        // Pin the first step's output. The dispatcher must never run it, the log
        // must mark it pinned, and the next step must see the pinned items.
        $dispatcher = new RecordingDispatcher();
        $pinned     = [FlowItems::item(json: ['pinned' => true])];

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
    }//end testAPinnedStepIsNotExecutedAndItsOutputIsUsed()

    public function testAFlowLevelPinIsUsedWhenTheRunSuppliesNone(): void
    {
        $flow         = $this->linearFlow();
        $flow['pins'] = ['first' => [FlowItems::item(json: ['source' => 'flow-pin'])]];

        $dispatcher = new RecordingDispatcher();
        $result     = $this->runFlow($flow, $dispatcher);

        $this->assertSame(['second'], $dispatcher->dispatched);
        $this->assertSame('flow-pin', $dispatcher->seenItems['second'][0]['json']['source']);
    }//end testAFlowLevelPinIsUsedWhenTheRunSuppliesNone()

    public function testARunPinOverridesAFlowPin(): void
    {
        $flow         = $this->linearFlow();
        $flow['pins'] = ['first' => [FlowItems::item(json: ['source' => 'flow-pin'])]];

        $dispatcher = new RecordingDispatcher();
        $result     = $this->engine->run(
            $flow,
            new MethodMarkingStore(false, 'marking'),
            new FlowSubject(),
            $dispatcher,
            ['pins' => ['first' => [FlowItems::item(json: ['source' => 'run-pin'])]]]
        );

        // The run's pin wins over the flow's own.
        $this->assertSame('run-pin', $dispatcher->seenItems['second'][0]['json']['source']);
    }//end testARunPinOverridesAFlowPin()

    public function testAPinnedStepThatWouldHaveFailedStillSucceeds(): void
    {
        // The whole point of a pin: skip the step that blows up (or hits a real
        // API) and carry on with its stored output.
        $dispatcher = new RecordingDispatcher(failOn: 'first');
        $result     = $this->engine->run(
            $this->linearFlow(),
            new MethodMarkingStore(false, 'marking'),
            new FlowSubject(),
            $dispatcher,
            ['pins' => ['first' => [FlowItems::item(json: ['ok' => true])]]]
        );

        $this->assertSame(FlowEngine::STATUS_COMPLETED, $result['status']);
    }//end testAPinnedStepThatWouldHaveFailedStillSucceeds()

    public function testPerItemRoutingSendsEachItemDownItsTaggedBranch(): void
    {
        // A router edge start -> [high, low], then a step off each. The router
        // tags n>5 for 'high', the rest for 'low'. Each branch must see only its
        // own items — this is what per-item routing means.
        $router = new class extends RecordingDispatcher {
            public function dispatch(array $step, array $items, array $context): array
            {
                $name = (string) ($step['id'] ?? '');
                $this->dispatched[]     = $name;
                $this->seenItems[$name] = $items;

                if ($name !== 'route') {
                    return $items;
                }

                $out = [];
                foreach ($items as $i => $item) {
                    $n     = (int) ($item['json']['n'] ?? 0);
                    $out[] = FlowItems::item(
                        json: $item['json'],
                        binary: [],
                        fromItemIndex: $i,
                        output: ($n > 5 ? 'high' : 'low')
                    );
                }

                return $out;
            }//end dispatch()
        };

        // The router tags each item with the NODE it should go to, and a node's
        // input place is named after the node — which is exactly why the
        // lowering must not prefix place names. Prefix them and every tagged
        // item matches nothing and vanishes into an empty branch, silently.
        $flow = [
            'id'    => 'route',
            'nodes' => [
                ['id' => 'route', 'type' => 'test.route'],
                ['id' => 'high', 'type' => 'test.step'],
                ['id' => 'low', 'type' => 'test.step'],
            ],
            'edges' => [
                ['id' => 'route-high', 'from' => 'route', 'to' => 'high'],
                ['id' => 'route-low', 'from' => 'route', 'to' => 'low'],
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
        $this->assertSame([7], array_map(static fn (array $i): int => $i['json']['n'], $router->seenItems['high']));
        $this->assertSame([1, 3], array_map(static fn (array $i): int => $i['json']['n'], $router->seenItems['low']));
        // The routing tag does not linger on the items the branch step sees.
        $this->assertArrayNotHasKey('output', $router->seenItems['high'][0]);
    }//end testPerItemRoutingSendsEachItemDownItsTaggedBranch()

    public function testAnUntaggedSplitStillBroadcastsToEveryBranch(): void
    {
        // The no-regression guarantee: a fork whose items carry no output tag
        // delivers every item to every branch, exactly as before per-item routing.
        $dispatcher = new RecordingDispatcher();
        $result     = $this->runFlow(
                [
                    'id'    => 'fork',
                    'nodes' => [
                        ['id' => 'fork', 'type' => 'test.step'],
                        ['id' => 'doA', 'type' => 'test.step'],
                        ['id' => 'doB', 'type' => 'test.step'],
                    ],
                    'edges' => [
                        ['id' => 'fork-a', 'from' => 'fork', 'to' => 'doA'],
                        ['id' => 'fork-b', 'from' => 'fork', 'to' => 'doB'],
                    ],
                ],
                $dispatcher
                );

        $this->assertSame(FlowEngine::STATUS_COMPLETED, $result['status']);
        // Both branches saw the (single, untagged) item.
        $this->assertCount(1, $dispatcher->seenItems['doA']);
        $this->assertCount(1, $dispatcher->seenItems['doB']);
    }//end testAnUntaggedSplitStillBroadcastsToEveryBranch()

    public function testRunFromHereStartsAtTheChosenNodeAndSkipsWhatIsBefore(): void
    {
        // A two-action line first -> second. Starting at 'second' must run only
        // that action; 'first' never runs. `startAt` names a NODE, which under
        // the previous model happened to be a place.
        $dispatcher = new RecordingDispatcher();
        $seed       = [FlowItems::item(json: ['from' => 'run-from-here'])];

        $result = $this->engine->run(
            $this->linearFlow(),
            new MethodMarkingStore(false, 'marking'),
            new FlowSubject(),
            $dispatcher,
            [],
            $seed,
            'second'
        );

        $this->assertSame(FlowEngine::STATUS_COMPLETED, $result['status']);
        $this->assertSame(['second'], $dispatcher->dispatched);
        // The chosen start's step saw the supplied seed, not a subject item.
        $this->assertSame('run-from-here', $dispatcher->seenItems['second'][0]['json']['from']);
    }//end testRunFromHereStartsAtTheChosenNodeAndSkipsWhatIsBefore()

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
    }//end testRunFromAnUnknownNodeFailsLoudly()

    public function testAnEmptyStartAtIsIgnoredAndTheFlowRunsFromItsStart(): void
    {
        $dispatcher = new RecordingDispatcher();
        $result     = $this->engine->run(
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
    }//end testAnEmptyStartAtIsIgnoredAndTheFlowRunsFromItsStart()

    public function testAStepCarriesItsOwnNodeConfigToTheDispatcher(): void
    {
        $flow = $this->linearFlow();
        $flow['nodes'][0]['type']      = 'email';
        $flow['nodes'][0]['configRef'] = 'abc-123';

        $seen       = null;
        $dispatcher = new class($seen) implements FlowStepDispatcher {

            public array $steps = [];

            public function __construct(&$seen)
            {
            }//end __construct()

            public function dispatch(array $step, array $items, array $context): array
            {
                $this->steps[] = $step;
                return $items;
            }//end dispatch()
        };

        $this->engine->run($flow, new MethodMarkingStore(false, 'marking'), new FlowSubject(), $dispatcher);

        $this->assertSame('email', $dispatcher->steps[0]['type']);
        $this->assertSame('abc-123', $dispatcher->steps[0]['configRef']);
    }//end testAStepCarriesItsOwnNodeConfigToTheDispatcher()
}//end class
