<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowDefinitionBuilder;
use OCA\OpenRegister\Service\Flow\FlowEngine;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowStop;
use OCA\OpenRegister\Service\Flow\FlowStepDispatcher;
use OCA\OpenRegister\Service\Flow\Nodes\StopNode;
use OCA\OpenRegister\Service\Flow\Nodes\SwitchNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;

/** Subject carrying the marking on a property. */
class LogicSubject
{
    public array $marking = [];
}

/** Records which nodes ran and passes items through, tagging each hop. */
class TrackingDispatcher implements FlowStepDispatcher
{
    public array $ran = [];

    public function dispatch(array $step, array $items, array $context): array
    {
        $type = (string) ($step['type'] ?? '');
        if ($type !== '') {
            $this->ran[] = $type;
        }

        // A stop step ends the run, like the real StopNode.
        if ($type === 'stop') {
            throw new FlowStop(reason: (string) ($step['config']['message'] ?? 'stopped'), isError: (($step['config']['error'] ?? false) === true));
        }

        return $items;
    }
}

class FlowLogicTest extends TestCase
{
    private FlowEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new FlowEngine(new FlowDefinitionBuilder(), $this->createMock(LoggerInterface::class));
    }

    private function walk(array $flow, array $items, TrackingDispatcher $dispatcher): array
    {
        return $this->engine->run(
            flow: $flow,
            store: new MethodMarkingStore(false, 'marking'),
            subject: new LogicSubject(),
            dispatcher: $dispatcher,
            context: [],
            items: $items
        );
    }

    /**
     * A switch node with two conditioned edges routes to the matching one.
     */
    public function testASwitchTakesTheBranchWhoseConditionHolds(): void
    {
        // The branches are declared on the NODE as named exits, and each edge
        // says which exit it leaves from. That is what gives a node more than
        // one exit point — and what lets an editor draw a port per branch,
        // since the branches exist before any line is drawn.
        $flow = [
            'id' => 'switch',
            'nodes' => [
                [
                    'id'    => 's',
                    'type'  => 'switch',
                    // `low` is the ELSE, not a second condition. The two would
                    // be exhaustive to a reader, but the engine cannot prove
                    // that — and a token that matches nothing has nowhere to go
                    // and stops the run silently. So every branching node
                    // declares an unconditioned exit.
                    'exits' => [
                        ['id' => 'high', 'condition' => ['>' => [['var' => 'json.n'], 10]]],
                        ['id' => 'low'],
                    ],
                ],
                ['id' => 'hi', 'type' => 'high'],
                ['id' => 'lo', 'type' => 'low'],
            ],
            'edges' => [
                ['id' => 'toHigh', 'from' => 's', 'fromExit' => 'high', 'to' => 'hi'],
                ['id' => 'toLow', 'from' => 's', 'fromExit' => 'low', 'to' => 'lo'],
            ],
        ];

        // The switch node itself runs — it is an action now, not a bare place —
        // and then exactly one branch does.
        $d = new TrackingDispatcher();
        $this->walk($flow, [FlowItems::item(json: ['n' => 42])], $d);
        $this->assertSame(['switch', 'high'], $d->ran);

        $d = new TrackingDispatcher();
        $this->walk($flow, [FlowItems::item(json: ['n' => 3])], $d);
        $this->assertSame(['switch', 'low'], $d->ran);
    }

    /**
     * An unconditioned edge is the default: taken only when no case matched.
     */
    public function testAnUnconditionedEdgeIsTheDefault(): void
    {
        // An exit that declares no condition is the default/else.
        $flow = [
            'id' => 'default',
            'nodes' => [
                [
                    'id'    => 's',
                    'type'  => 'switch',
                    'exits' => [
                        ['id' => 'hit', 'condition' => ['==' => [['var' => 'json.kind'], 'special']]],
                        ['id' => 'otherwise'],
                    ],
                ],
                ['id' => 'match', 'type' => 'matched'],
                ['id' => 'else', 'type' => 'fell-through'],
            ],
            'edges' => [
                ['id' => 'toMatch', 'from' => 's', 'fromExit' => 'hit', 'to' => 'match'],
                ['id' => 'toElse', 'from' => 's', 'fromExit' => 'otherwise', 'to' => 'else'],
            ],
        ];

        $d = new TrackingDispatcher();
        $this->walk($flow, [FlowItems::item(json: ['kind' => 'ordinary'])], $d);
        $this->assertSame(['switch', 'fell-through'], $d->ran);

        $d = new TrackingDispatcher();
        $this->walk($flow, [FlowItems::item(json: ['kind' => 'special'])], $d);
        $this->assertSame(['switch', 'matched'], $d->ran);
    }

    /**
     * A matching case beats the default regardless of declaration order.
     */
    public function testAMatchingCaseBeatsTheDefault(): void
    {
        $flow = [
            'id' => 'order',
            'nodes' => [
                [
                    'id'    => 's',
                    'type'  => 'switch',
                    // Else declared FIRST; the conditioned match must still win.
                    'exits' => [
                        ['id' => 'otherwise'],
                        ['id' => 'hit', 'condition' => ['==' => [['var' => 'json.go'], true]]],
                    ],
                ],
                ['id' => 'a', 'type' => 'default'],
                ['id' => 'b', 'type' => 'matched'],
            ],
            'edges' => [
                ['id' => 'toDefault', 'from' => 's', 'fromExit' => 'otherwise', 'to' => 'a'],
                ['id' => 'toMatch', 'from' => 's', 'fromExit' => 'hit', 'to' => 'b'],
            ],
        ];

        $d = new TrackingDispatcher();
        $this->walk($flow, [FlowItems::item(json: ['go' => true])], $d);
        $this->assertSame(['switch', 'matched'], $d->ran);
    }

    /**
     * A switch with no else is refused, because its token could be stranded.
     *
     * This used to assert that such a flow "ends the run cleanly" with nothing
     * dispatched — a run that reports COMPLETED having done no work, which is
     * exactly what a finished flow reports. The two were indistinguishable, so
     * a scheduled flow could stop doing its job and nothing would say so.
     *
     * A token is unique and exclusive, so it must always have somewhere to go:
     * a node that conditions its exits must declare an else.
     */
    public function testASwitchWithNoElseIsRefusedRatherThanSilentlyStranding(): void
    {
        $flow = [
            'id' => 'deadend',
            'nodes' => [
                [
                    'id'    => 's',
                    'type'  => 'switch',
                    'exits' => [['id' => 'gated', 'condition' => ['==' => [['var' => 'json.x'], 'impossible']]]],
                ],
                ['id' => 'never', 'type' => 'never'],
            ],
            'edges' => [['id' => 'gated', 'from' => 's', 'fromExit' => 'gated', 'to' => 'never']],
        ];

        $d      = new TrackingDispatcher();
        $result = $this->walk($flow, [FlowItems::item(json: ['x' => 'something-else'])], $d);

        $this->assertSame(FlowEngine::STATUS_FAILED, $result['status']);
        $this->assertStringContainsString('declares no else', $result['error']);
        $this->assertSame([], $d->ran, 'A refused flow must not run any step.');
    }

    /**
     * Positive control for the refusal: the same flow builds once it has an else.
     */
    public function testTheSameSwitchRunsOnceItDeclaresAnElse(): void
    {
        $flow = [
            'id' => 'deadend',
            'nodes' => [
                [
                    'id'    => 's',
                    'type'  => 'switch',
                    'exits' => [
                        ['id' => 'gated', 'condition' => ['==' => [['var' => 'json.x'], 'impossible']]],
                        ['id' => 'otherwise'],
                    ],
                ],
                ['id' => 'never', 'type' => 'never'],
                ['id' => 'fallback', 'type' => 'fallback'],
            ],
            'edges' => [
                ['id' => 'gated', 'from' => 's', 'fromExit' => 'gated', 'to' => 'never'],
                ['id' => 'else', 'from' => 's', 'fromExit' => 'otherwise', 'to' => 'fallback'],
            ],
        ];

        $d      = new TrackingDispatcher();
        $result = $this->walk($flow, [FlowItems::item(json: ['x' => 'something-else'])], $d);

        $this->assertSame(FlowEngine::STATUS_COMPLETED, $result['status']);
        $this->assertSame(['switch', 'fallback'], $d->ran);
    }

    /**
     * A Stop step ends the run as `stopped`, with its message in the log.
     */
    public function testAStopStepEndsTheRunCleanly(): void
    {
        $flow = [
            'id' => 'stop',
            'nodes' => [
                ['id' => 'stop', 'type' => 'stop', 'config' => ['message' => 'guard failed']],
                ['id' => 'past', 'type' => 'never-reached'],
            ],
            'edges' => [['id' => 'onwards', 'from' => 'stop', 'to' => 'past']],
        ];

        $d = new TrackingDispatcher();
        $result = $this->walk($flow, [FlowItems::item(json: ['a' => 1])], $d);

        $this->assertSame(FlowEngine::STATUS_STOPPED, $result['status']);
        $this->assertSame(['stop'], $d->ran);
        $this->assertSame('stopped', $result['log'][0]['status']);
        $this->assertSame('guard failed', $result['log'][0]['reason']);
    }

    /**
     * An error stop ends the run as `failed`, carrying the message as the error.
     */
    public function testAnErrorStopFailsTheRun(): void
    {
        $flow = [
            'id' => 'errstop',
            'nodes' => [
                ['id' => 'stop', 'type' => 'stop', 'config' => ['error' => true, 'message' => 'invariant broken']],
            ],
            'edges' => [],
        ];

        $result = $this->walk($flow, [FlowItems::item(json: ['a' => 1])], new TrackingDispatcher());

        $this->assertSame(FlowEngine::STATUS_FAILED, $result['status']);
        $this->assertSame('invariant broken', $result['error']);
    }
}

class SwitchAndStopNodeTest extends TestCase
{
    private function l10n(): IL10N
    {
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnArgument(0);
        return $l;
    }

    public function testSwitchPassesItemsThroughUnchanged(): void
    {
        $node = new SwitchNode($this->l10n(), $this->createMock(IURLGenerator::class));
        $items = [FlowItems::item(json: ['a' => 1]), FlowItems::item(json: ['a' => 2])];

        $this->assertSame($items, $node->execute($items, [], []));
        $this->assertTrue($node->isAvailableForScope(IManager::SCOPE_USER));
    }

    public function testStopThrowsFlowStop(): void
    {
        $node = new StopNode($this->l10n(), $this->createMock(IURLGenerator::class));

        $this->expectException(FlowStop::class);
        $node->execute([], ['message' => 'halt'], []);
    }

    public function testStopCarriesTheErrorFlag(): void
    {
        $node = new StopNode($this->l10n(), $this->createMock(IURLGenerator::class));

        try {
            $node->execute([], ['error' => true, 'message' => 'bad'], []);
            $this->fail('expected FlowStop');
        } catch (FlowStop $e) {
            $this->assertTrue($e->isError());
            $this->assertSame('bad', $e->getMessage());
        }
    }
}
