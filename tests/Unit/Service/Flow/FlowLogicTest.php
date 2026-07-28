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
        $flow = [
            'id' => 'switch',
            'nodes' => [['id' => 's'], ['id' => 'hi'], ['id' => 'lo']],
            'edges' => [
                ['id' => 'toHigh', 'from' => 's', 'to' => 'hi', 'type' => 'high',
                 'condition' => ['>' => [['var' => 'json.n'], 10]]],
                ['id' => 'toLow', 'from' => 's', 'to' => 'lo', 'type' => 'low',
                 'condition' => ['<=' => [['var' => 'json.n'], 10]]],
            ],
        ];

        $d = new TrackingDispatcher();
        $this->walk($flow, [FlowItems::item(json: ['n' => 42])], $d);
        $this->assertSame(['high'], $d->ran);

        $d = new TrackingDispatcher();
        $this->walk($flow, [FlowItems::item(json: ['n' => 3])], $d);
        $this->assertSame(['low'], $d->ran);
    }

    /**
     * An unconditioned edge is the default: taken only when no case matched.
     */
    public function testAnUnconditionedEdgeIsTheDefault(): void
    {
        $flow = [
            'id' => 'default',
            'nodes' => [['id' => 's'], ['id' => 'match'], ['id' => 'else']],
            'edges' => [
                ['id' => 'toMatch', 'from' => 's', 'to' => 'match', 'type' => 'matched',
                 'condition' => ['==' => [['var' => 'json.kind'], 'special']]],
                ['id' => 'toElse', 'from' => 's', 'to' => 'else', 'type' => 'fell-through'],
            ],
        ];

        $d = new TrackingDispatcher();
        $this->walk($flow, [FlowItems::item(json: ['kind' => 'ordinary'])], $d);
        $this->assertSame(['fell-through'], $d->ran);

        $d = new TrackingDispatcher();
        $this->walk($flow, [FlowItems::item(json: ['kind' => 'special'])], $d);
        $this->assertSame(['matched'], $d->ran);
    }

    /**
     * A matching case beats the default regardless of declaration order.
     */
    public function testAMatchingCaseBeatsTheDefault(): void
    {
        $flow = [
            'id' => 'order',
            'nodes' => [['id' => 's'], ['id' => 'a'], ['id' => 'b']],
            'edges' => [
                // Default declared FIRST; the conditioned match must still win.
                ['id' => 'toDefault', 'from' => 's', 'to' => 'a', 'type' => 'default'],
                ['id' => 'toMatch', 'from' => 's', 'to' => 'b', 'type' => 'matched',
                 'condition' => ['==' => [['var' => 'json.go'], true]]],
            ],
        ];

        $d = new TrackingDispatcher();
        $this->walk($flow, [FlowItems::item(json: ['go' => true])], $d);
        $this->assertSame(['matched'], $d->ran);
    }

    /**
     * A switch with no matching case and no default ends the run cleanly,
     * rather than spinning on un-fireable transitions until the ceiling.
     */
    public function testNoMatchingCaseAndNoDefaultEndsTheRun(): void
    {
        $flow = [
            'id' => 'deadend',
            'nodes' => [['id' => 's'], ['id' => 'never']],
            'edges' => [
                ['id' => 'gated', 'from' => 's', 'to' => 'never', 'type' => 'never',
                 'condition' => ['==' => [['var' => 'json.x'], 'impossible']]],
            ],
        ];

        $d = new TrackingDispatcher();
        $result = $this->walk($flow, [FlowItems::item(json: ['x' => 'something-else'])], $d);

        $this->assertSame(FlowEngine::STATUS_COMPLETED, $result['status']);
        $this->assertSame([], $d->ran);
    }

    /**
     * A Stop step ends the run as `stopped`, with its message in the log.
     */
    public function testAStopStepEndsTheRunCleanly(): void
    {
        $flow = [
            'id' => 'stop',
            'nodes' => [['id' => 'a'], ['id' => 'b'], ['id' => 'c']],
            'edges' => [
                ['id' => 'toStop', 'from' => 'a', 'to' => 'b', 'type' => 'stop', 'config' => ['message' => 'guard failed']],
                ['id' => 'past', 'from' => 'b', 'to' => 'c', 'type' => 'never-reached'],
            ],
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
            'nodes' => [['id' => 'a'], ['id' => 'b']],
            'edges' => [
                ['id' => 'toStop', 'from' => 'a', 'to' => 'b', 'type' => 'stop',
                 'config' => ['error' => true, 'message' => 'invariant broken']],
            ],
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
