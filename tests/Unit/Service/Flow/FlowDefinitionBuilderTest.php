<?php

/**
 * Unit tests for FlowDefinitionBuilder — the flow document -> Petri net lowering.
 *
 * A node is an ACTION carrying `type`/`config`; an edge is SEQUENCE. The Petri
 * net is what the engine executes, not what an author writes
 * (or-flow-action-nodes).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace Unit\Service\Flow;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Flow\FlowDefinitionBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\Workflow;

class FlowDefinitionBuilderTest extends TestCase
{
    private FlowDefinitionBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new FlowDefinitionBuilder();
    }

    /**
     * A node carrying a step type, for brevity in the fixtures below.
     */
    private function node(string $id, array $extra=[]): array
    {
        return array_merge(['id' => $id, 'type' => 'openregister.set-fields'], $extra);
    }

    /**
     * The transition lowered from a given node.
     */
    private function transitionFor(Definition $definition, string $nodeId)
    {
        foreach ($definition->getTransitions() as $transition) {
            if ($transition->getName() === $nodeId) {
                return $transition;
            }
        }

        $this->fail(sprintf('No transition was lowered for node "%s".', $nodeId));
    }

    public function testEachNodeBecomesOneTransitionCarryingItsOwnStep(): void
    {
        $definition = $this->builder->build([
            'nodes' => [$this->node('a'), $this->node('b')],
            'edges' => [['id' => 'go', 'from' => 'a', 'to' => 'b']],
        ]);

        // Two actions, two transitions — named for the nodes, not the edge.
        $this->assertCount(2, $definition->getTransitions());
        $this->assertSame(['a'], $this->transitionFor($definition, 'a')->getFroms());
        $this->assertSame(['b'], $this->transitionFor($definition, 'a')->getTos());
    }

    public function testAPlaceIsNamedAfterItsNodeSoRoutingAndMarkingsStayReadable(): void
    {
        // Load-bearing, not cosmetic: FlowEngine matches an item's per-item
        // routing tag against the output PLACE name, and a routing step tags
        // items with the node it routes to. A prefixed place name matches
        // nothing and silently drops every routed item.
        $definition = $this->builder->build([
            'nodes' => [$this->node('a'), $this->node('b')],
            'edges' => [['id' => 'go', 'from' => 'a', 'to' => 'b']],
        ]);

        $this->assertContains('a', array_keys($definition->getPlaces()));
        $this->assertContains('b', array_keys($definition->getPlaces()));
    }

    public function testANodeWithSeveralOutgoingEdgesIsAParallelSplit(): void
    {
        $definition = $this->builder->build([
            'nodes' => [$this->node('start'), $this->node('a'), $this->node('b')],
            'edges' => [
                ['id' => 'l', 'from' => 'start', 'to' => 'a'],
                ['id' => 'r', 'from' => 'start', 'to' => 'b'],
            ],
        ]);

        $this->assertSame(['a', 'b'], $this->transitionFor($definition, 'start')->getTos());
    }

    public function testAnEdgeFanningOutToSeveralNodesIsAlsoASplit(): void
    {
        $definition = $this->builder->build([
            'nodes' => [$this->node('start'), $this->node('a'), $this->node('b')],
            'edges' => [['id' => 'both', 'from' => 'start', 'to' => ['a', 'b']]],
        ]);

        $this->assertSame(['a', 'b'], $this->transitionFor($definition, 'start')->getTos());
    }

    /**
     * THE regression that matters most.
     *
     * Converging edges default to a MERGE — the node runs when any one
     * predecessor finishes. Lowering them to a join instead would require all of
     * them, and the live Hydra sequencer (whose exit is reached from several
     * mutually exclusive paths) would deadlock on every run while still
     * producing a perfectly valid definition. Silent, and only visible in
     * production.
     */
    public function testConvergingEdgesAreAMergeSoTheNodeFiresAfterOnePredecessor(): void
    {
        $flow = [
            'nodes' => [$this->node('x'), $this->node('y'), $this->node('done')],
            'edges' => [
                ['id' => 'xd', 'from' => 'x', 'to' => 'done'],
                ['id' => 'yd', 'from' => 'y', 'to' => 'done'],
            ],
        ];

        $definition = $this->builder->build($flow);

        // One shared input place, so either predecessor enables it.
        $this->assertSame(['done'], $this->transitionFor($definition, 'done')->getFroms());

        // And prove it against the real engine, not just the shape.
        $workflow = new Workflow($definition, new MethodMarkingStore(false, 'marking'));
        $subject  = new class {
            public array $marking = ['x' => 1];
        };

        $this->assertTrue(
            $workflow->can($subject, 'x'),
            'The flow should be able to start at x.'
        );

        $workflow->apply($subject, 'x');
        $this->assertTrue(
            $workflow->can($subject, 'done'),
            'A merge must fire after ONE predecessor; requiring both would deadlock the flow.'
        );
    }

    public function testADeclaredJoinWaitsForEveryIncomingEdge(): void
    {
        $definition = $this->builder->build([
            'nodes' => [$this->node('x'), $this->node('y'), $this->node('done', ['join' => true])],
            'edges' => [
                ['id' => 'xd', 'from' => 'x', 'to' => 'done'],
                ['id' => 'yd', 'from' => 'y', 'to' => 'done'],
            ],
        ]);

        // One input place per incoming edge — that is what makes it synchronise.
        $this->assertSame(['done#xd', 'done#yd'], $this->transitionFor($definition, 'done')->getFroms());

        $workflow = new Workflow($definition, new MethodMarkingStore(false, 'marking'));
        $subject  = new class {
            public array $marking = ['x' => 1, 'y' => 1];
        };

        $workflow->apply($subject, 'x');
        $this->assertFalse(
            $workflow->can($subject, 'done'),
            'A join must NOT fire on one token.'
        );

        $workflow->apply($subject, 'y');
        $this->assertTrue(
            $workflow->can($subject, 'done'),
            'A join must fire once every input holds a token.'
        );
    }

    public function testASinkNodeGetsATerminalPlaceSoItCanStillFire(): void
    {
        $definition = $this->builder->build([
            'nodes' => [$this->node('a'), $this->node('b')],
            'edges' => [['id' => 'go', 'from' => 'a', 'to' => 'b']],
        ]);

        $this->assertSame(['end:b'], $this->transitionFor($definition, 'b')->getTos());
    }

    public function testInitialPlacesAreInferredAsTheFlowSources(): void
    {
        $definition = $this->builder->build([
            'nodes' => [$this->node('a'), $this->node('b'), $this->node('c')],
            'edges' => [
                ['id' => 'ab', 'from' => 'a', 'to' => 'b'],
                ['id' => 'bc', 'from' => 'b', 'to' => 'c'],
            ],
        ]);

        $this->assertSame(['a'], $definition->getInitialPlaces());
    }

    public function testAnExplicitInitialNamesANodeAndOverridesInference(): void
    {
        $definition = $this->builder->build([
            'nodes'   => [$this->node('a'), $this->node('b')],
            'edges'   => [['id' => 'ab', 'from' => 'a', 'to' => 'b']],
            'initial' => 'b',
        ]);

        $this->assertSame(['b'], $definition->getInitialPlaces());
    }

    public function testAFullyCyclicFlowStartsOnItsFirstNodeRatherThanRefusingToBuild(): void
    {
        $definition = $this->builder->build([
            'nodes' => [$this->node('a'), $this->node('b')],
            'edges' => [
                ['id' => 'ab', 'from' => 'a', 'to' => 'b'],
                ['id' => 'ba', 'from' => 'b', 'to' => 'a'],
            ],
        ]);

        $this->assertSame(['a'], $definition->getInitialPlaces());
    }

    // ---------------------------------------------------------------------
    // Refusals. Each names the offending element; each has a positive control
    // proving the same document builds once corrected.
    // ---------------------------------------------------------------------

    public function testAnEdgeCarryingAStepIsRefusedAsPreInversionRatherThanReinterpreted(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/"scope".*pre-inversion|pre-inversion.*"scope"/s');

        $this->builder->build([
            'nodes' => [['id' => 'a'], ['id' => 'b']],
            'edges' => [['id' => 'scope', 'from' => 'a', 'to' => 'b', 'type' => 'openregister.set-fields']],
        ]);
    }

    public function testTheSameFlowBuildsOnceTheStepMovesOntoTheNode(): void
    {
        // Positive control for the refusal above: the shape is the only
        // difference, so a green refusal test cannot be a false alarm.
        $definition = $this->builder->build([
            'nodes' => [$this->node('a'), $this->node('b')],
            'edges' => [['id' => 'scope', 'from' => 'a', 'to' => 'b']],
        ]);

        $this->assertCount(2, $definition->getTransitions());
    }

    public function testANodeWithoutAStepTypeIsRefusedByName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/gap/');

        $this->builder->build([
            'nodes' => [$this->node('a'), ['id' => 'gap']],
            'edges' => [['id' => 'go', 'from' => 'a', 'to' => 'gap']],
        ]);
    }

    public function testAnEdgeToAnUnknownNodeIsRejectedByNameRatherThanFailingLater(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/ghost.*nowhere|nowhere.*ghost/s');

        $this->builder->build([
            'nodes' => [$this->node('a')],
            'edges' => [['id' => 'ghost', 'from' => 'a', 'to' => 'nowhere']],
        ]);
    }

    public function testADuplicateNodeIdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/more than once/');

        $this->builder->build([
            'nodes' => [$this->node('a'), $this->node('a')],
            'edges' => [],
        ]);
    }

    public function testANodeWithoutAnIdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->builder->build(['nodes' => [['type' => 'openregister.set-fields']], 'edges' => []]);
    }

    public function testAFlowWithoutNodesIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no nodes/');

        $this->builder->build(['nodes' => [], 'edges' => []]);
    }

    public function testAnEdgeMissingAnEndpointIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->builder->build([
            'nodes' => [$this->node('a'), $this->node('b')],
            'edges' => [['id' => 'half', 'from' => 'a']],
        ]);
    }

    public function testAnInitialNamingAnUnknownNodeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/nowhere/');

        $this->builder->build([
            'nodes'   => [$this->node('a')],
            'edges'   => [],
            'initial' => 'nowhere',
        ]);
    }

    public function testASingleNodeFlowIsRunnable(): void
    {
        // The smallest legitimate flow: one action, no edges. It must still be
        // a source and still have somewhere to put its token.
        $definition = $this->builder->build(['nodes' => [$this->node('only')], 'edges' => []]);

        $this->assertSame(['only'], $definition->getInitialPlaces());
        $this->assertSame(['only'], $this->transitionFor($definition, 'only')->getFroms());
        $this->assertSame(['end:only'], $this->transitionFor($definition, 'only')->getTos());
    }
}
