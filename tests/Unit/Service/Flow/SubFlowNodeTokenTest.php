<?php
/**
 * Tests for how a sub-flow seeds and returns the flow token.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowToken;
use OCA\OpenRegister\Service\Flow\Nodes\SubFlowNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\SubFlowNode
 */
class SubFlowNodeTokenTest extends TestCase
{

    /**
     * Resolves flows.
     *
     * @var FlowLocator
     */
    private FlowLocator $resolvers;

    /**
     * Queues and executes sub-runs.
     *
     * @var FlowRunService
     */
    private FlowRunService $runs;

    /**
     * The node under test.
     *
     * @var SubFlowNode
     */
    private SubFlowNode $node;

    /**
     * Build the node over mocked collaborators.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnArgument(0);

        $this->resolvers = $this->createMock(FlowLocator::class);
        $this->runs      = $this->createMock(FlowRunService::class);

        $this->node = new SubFlowNode(
            $this->resolvers,
            $this->runs,
            $l,
            $this->createMock(IURLGenerator::class)
        );

    }//end setUp()

    /**
     * Build a run in a given status, optionally carrying a stored token.
     *
     * @param string $status      The run status.
     * @param array  $tokenValues The token values the run holds.
     *
     * @return FlowRun The run.
     */
    private function runWithToken(string $status, array $tokenValues=[]): FlowRun
    {
        $run = new FlowRun();
        $run->setStatus($status);
        $run->setItems([]);
        $run->setContext([FlowToken::CONTEXT_KEY => $tokenValues]);
        return $run;

    }//end runWithToken()

    /**
     * The child is seeded with the parent's values.
     *
     * @return void
     */
    public function testTheChildIsSeededWithTheParentsValues(): void
    {
        $this->resolvers->method('resolveFlow')->willReturn(['id' => 'child', 'edges' => []]);

        $seenChildContext = null;
        $this->runs->method('queue')->willReturnCallback(
            function (...$args) use (&$seenChildContext) {
                $seenChildContext = ($args[3] ?? ($args['context'] ?? null));
                return $this->runWithToken(FlowRun::STATUS_QUEUED);
            }
        );
        $this->runs->method('execute')->willReturn($this->runWithToken(FlowRun::STATUS_COMPLETED));

        $parentToken = new FlowToken(['resolvedRef' => 'source-7']);

        $this->node->execute(
            [],
            ['flow' => 'child'],
            [FlowToken::CONTEXT_KEY => $parentToken]
        );

        $this->assertIsArray($seenChildContext);
        $this->assertSame(
            ['resolvedRef' => 'source-7'],
            $seenChildContext[FlowToken::CONTEXT_KEY],
            'the child is seeded with the parent values, as plain storable values'
        );

    }//end testTheChildIsSeededWithTheParentsValues()

    /**
     * A fire-and-forget child cannot reach into its parent.
     *
     * The child is given values, not the parent's instance — otherwise a child
     * that runs later could write into a parent that has already moved on.
     *
     * @return void
     */
    public function testAFireAndForgetChildCannotMutateTheParent(): void
    {
        $this->resolvers->method('resolveFlow')->willReturn(['id' => 'child']);

        $seenChildContext = null;
        $this->runs->method('queue')->willReturnCallback(
            function (...$args) use (&$seenChildContext) {
                $seenChildContext = ($args[3] ?? ($args['context'] ?? null));
                return $this->runWithToken(FlowRun::STATUS_QUEUED);
            }
        );
        $this->runs->expects($this->never())->method('execute');

        $parentToken = new FlowToken(['mine' => 'parent']);

        $this->node->execute(
            [],
            ['flow' => 'child', 'wait' => false],
            [FlowToken::CONTEXT_KEY => $parentToken]
        );

        // What the child received is a value bag, not the parent's object.
        $this->assertIsArray($seenChildContext[FlowToken::CONTEXT_KEY]);
        $this->assertNotInstanceOf(FlowToken::class, $seenChildContext[FlowToken::CONTEXT_KEY]);
        $this->assertSame('parent', $parentToken->get('mine'), 'the parent is untouched');

    }//end testAFireAndForgetChildCannotMutateTheParent()

    /**
     * A waited-on child hands its values back to the parent.
     *
     * @return void
     */
    public function testAWaitedChildReturnsItsValuesToTheParent(): void
    {
        $this->resolvers->method('resolveFlow')->willReturn(['id' => 'child', 'edges' => []]);
        $this->runs->method('queue')->willReturn($this->runWithToken(FlowRun::STATUS_QUEUED));
        $this->runs->method('execute')->willReturn(
            $this->runWithToken(FlowRun::STATUS_COMPLETED, ['resolvedByChild' => 'yes'])
        );

        $parentToken = new FlowToken(['keep' => 'mine']);

        $this->node->execute([], ['flow' => 'child'], [FlowToken::CONTEXT_KEY => $parentToken]);

        $this->assertSame('yes', $parentToken->get('resolvedByChild'), 'the child value reaches the parent');
        $this->assertSame('mine', $parentToken->get('keep'), 'the parent keeps what the child did not touch');

    }//end testAWaitedChildReturnsItsValuesToTheParent()

    /**
     * On a conflicting key the child's value is the one that survives.
     *
     * @return void
     */
    public function testTheChildWinsOnAConflictingKey(): void
    {
        $this->resolvers->method('resolveFlow')->willReturn(['id' => 'child', 'edges' => []]);
        $this->runs->method('queue')->willReturn($this->runWithToken(FlowRun::STATUS_QUEUED));
        $this->runs->method('execute')->willReturn(
            $this->runWithToken(FlowRun::STATUS_COMPLETED, ['shared' => 'child'])
        );

        $parentToken = new FlowToken(['shared' => 'parent']);

        $this->node->execute([], ['flow' => 'child'], [FlowToken::CONTEXT_KEY => $parentToken]);

        $this->assertSame('child', $parentToken->get('shared'));

    }//end testTheChildWinsOnAConflictingKey()

    /**
     * A parent that never held a token still runs a sub-flow.
     *
     * @return void
     */
    public function testAParentWithoutATokenStillRunsASubFlow(): void
    {
        $this->resolvers->method('resolveFlow')->willReturn(['id' => 'child', 'edges' => []]);
        $this->runs->method('queue')->willReturn($this->runWithToken(FlowRun::STATUS_QUEUED));
        $this->runs->method('execute')->willReturn($this->runWithToken(FlowRun::STATUS_COMPLETED));

        $items = $this->node->execute([], ['flow' => 'child'], []);

        $this->assertIsArray($items);

    }//end testAParentWithoutATokenStillRunsASubFlow()
}//end class
