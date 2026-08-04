<?php

/**
 * Unit tests for FlowDefinitionBuilder — the flow document -> Petri net translator.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace Unit\Service\Flow;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Flow\FlowDefinitionBuilder;
use PHPUnit\Framework\TestCase;

class FlowDefinitionBuilderTest extends TestCase
{
    private FlowDefinitionBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new FlowDefinitionBuilder();
    }

    public function testNodesBecomePlacesAndEdgesBecomeTransitions(): void
    {
        $definition = $this->builder->build([
            'nodes' => [['id' => 'a'], ['id' => 'b']],
            'edges' => [['id' => 'go', 'from' => 'a', 'to' => 'b']],
        ]);

        // Definition::getPlaces() is keyed by place name, not a list.
        $this->assertSame(['a', 'b'], array_keys($definition->getPlaces()));
        $this->assertCount(1, $definition->getTransitions());
        $this->assertSame('go', $definition->getTransitions()[0]->getName());
    }

    public function testAnEdgeMayUseSourceTargetAsWellAsFromTo(): void
    {
        // The canvas emits {source, target}; stored documents use {from, to}.
        // Accepting both keeps CnGraphCanvas's payload directly runnable.
        $definition = $this->builder->build([
            'nodes' => [['id' => 'a'], ['id' => 'b']],
            'edges' => [['id' => 'go', 'source' => 'a', 'target' => 'b']],
        ]);

        $this->assertSame(['a'], $definition->getTransitions()[0]->getFroms());
        $this->assertSame(['b'], $definition->getTransitions()[0]->getTos());
    }

    public function testAnEdgeToSeveralNodesIsAParallelSplit(): void
    {
        $definition = $this->builder->build([
            'nodes' => [['id' => 'start'], ['id' => 'a'], ['id' => 'b']],
            'edges' => [['id' => 'fork', 'from' => 'start', 'to' => ['a', 'b']]],
        ]);

        $this->assertSame(['a', 'b'], $definition->getTransitions()[0]->getTos());
    }

    public function testAnEdgeFromSeveralNodesIsASynchronisingJoin(): void
    {
        $definition = $this->builder->build([
            'nodes' => [['id' => 'a'], ['id' => 'b'], ['id' => 'done']],
            'edges' => [['id' => 'join', 'from' => ['a', 'b'], 'to' => 'done']],
        ]);

        $this->assertSame(['a', 'b'], $definition->getTransitions()[0]->getFroms());
    }

    public function testInitialPlacesAreInferredAsTheGraphSources(): void
    {
        $definition = $this->builder->build([
            'nodes' => [['id' => 'a'], ['id' => 'b'], ['id' => 'c']],
            'edges' => [
                ['id' => 'e1', 'from' => 'a', 'to' => 'b'],
                ['id' => 'e2', 'from' => 'b', 'to' => 'c'],
            ],
        ]);

        // Only 'a' is pointed at by nothing.
        $this->assertSame(['a'], $definition->getInitialPlaces());
    }

    public function testAnExplicitInitialOverridesInference(): void
    {
        $definition = $this->builder->build([
            'nodes'   => [['id' => 'a'], ['id' => 'b']],
            'edges'   => [['id' => 'e1', 'from' => 'a', 'to' => 'b']],
            'initial' => 'b',
        ]);

        $this->assertSame(['b'], $definition->getInitialPlaces());
    }

    public function testAFullyCyclicGraphStartsOnItsFirstNodeRatherThanRefusingToBuild(): void
    {
        // Every node is targeted, so there is no source. A loop is a legitimate
        // thing to draw; declaration order is the only signal we have.
        $definition = $this->builder->build([
            'nodes' => [['id' => 'a'], ['id' => 'b']],
            'edges' => [
                ['id' => 'e1', 'from' => 'a', 'to' => 'b'],
                ['id' => 'e2', 'from' => 'b', 'to' => 'a'],
            ],
        ]);

        $this->assertSame(['a'], $definition->getInitialPlaces());
    }

    public function testAnEdgeToAnUnknownNodeIsRejectedByNameRatherThanFailingLater(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Flow edge "ghost" references unknown node "nowhere".');

        $this->builder->build([
            'nodes' => [['id' => 'a']],
            'edges' => [['id' => 'ghost', 'from' => 'a', 'to' => 'nowhere']],
        ]);
    }

    public function testADuplicateNodeIdIsRejected(): void
    {
        // Two nodes collapsing into one place would run, but would not be the
        // graph the user drew.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Flow declares node id "a" more than once.');

        $this->builder->build([
            'nodes' => [['id' => 'a'], ['id' => 'a']],
            'edges' => [],
        ]);
    }

    public function testANodeCarryingStepConfigIsRejected(): void
    {
        // The step is the EDGE. A `type` on a node is never read, so accepting
        // this yields a graph where every transition is a pass-through and the
        // run reports COMPLETED having done nothing — silently. Three graphs in
        // the fleet were authored this way and none of them failed anywhere
        // (or#2226).
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Flow node "a" carries "type"/"config", which the engine never reads');

        $this->builder->build([
            'nodes' => [['id' => 'a', 'type' => 'openregister.stop'], ['id' => 'b']],
            'edges' => [['id' => 'go', 'from' => 'a', 'to' => 'b']],
        ]);
    }

    public function testANodeCarryingOnlyConfigIsAlsoRejected(): void
    {
        // `config` without `type` is the same authoring mistake half-made, and
        // it is just as invisible at run time.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Flow node "a" carries "type"/"config"');

        $this->builder->build([
            'nodes' => [['id' => 'a', 'config' => ['error' => false]], ['id' => 'b']],
            'edges' => [['id' => 'go', 'from' => 'a', 'to' => 'b']],
        ]);
    }

    public function testANodeMayCarryPresentationalKeys(): void
    {
        // The rejection is about EXECUTABLE config only. A canvas legitimately
        // stores position, label and styling on a node, and refusing those
        // would make every drawn graph unbuildable.
        $definition = $this->builder->build([
            'nodes' => [
                ['id' => 'a', 'position' => ['x' => 0, 'y' => 0], 'label' => 'Start', 'colour' => '#21468B'],
                ['id' => 'b', 'position' => ['x' => 100, 'y' => 0]],
            ],
            'edges' => [['id' => 'go', 'from' => 'a', 'to' => 'b', 'type' => 'openregister.stop']],
        ]);

        $this->assertNotNull($definition);
    }

    public function testANodeWithoutAnIdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Flow node at index 1 has no id.');

        $this->builder->build(['nodes' => [['id' => 'a'], ['label' => 'oops']], 'edges' => []]);
    }

    public function testAFlowWithoutNodesIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Flow declares no nodes; nothing to run.');

        $this->builder->build(['nodes' => [], 'edges' => []]);
    }

    public function testAnEdgeMissingAnEndpointIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Flow edge at index 0 must declare both "from" and "to".');

        $this->builder->build(['nodes' => [['id' => 'a']], 'edges' => [['id' => 'half', 'from' => 'a']]]);
    }

    public function testEdgesSharingANameAreNotMerged(): void
    {
        // Two "approve" edges from different nodes are two transitions, as drawn.
        // Merging them would invent a join the user never asked for.
        $definition = $this->builder->build([
            'nodes' => [['id' => 'a'], ['id' => 'b'], ['id' => 'done']],
            'edges' => [
                ['id' => 'e1', 'name' => 'approve', 'from' => 'a', 'to' => 'done'],
                ['id' => 'e2', 'name' => 'approve', 'from' => 'b', 'to' => 'done'],
            ],
        ]);

        $this->assertCount(2, $definition->getTransitions());
    }
}
