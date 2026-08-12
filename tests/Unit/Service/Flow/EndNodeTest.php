<?php

/**
 * An end node only ends the branch that was actually taken.
 *
 * These tests exist because the distinction is invisible in the graph. After a
 * route, `FlowEngine::advanceItems()` marks EVERY place on the transition's
 * `to` list and distributes items to them by output tag — so the branch the
 * router did not choose is marked and holds zero items, and its steps still
 * fire. Item-driven nodes shrug that off; the end node did not, and ended the
 * run on a guard that had not tripped.
 *
 * NAMING. The node is `EndNode` and the role is `end`; `FlowStop` keeps its
 * name because it is a different thing — the control-flow EXCEPTION the engine
 * throws to stop a run, not a node role. The three-names-for-one-idea problem
 * that 4eac3a3 and 7ba3c21 set out to fix was about the NODE (id `stop`, class
 * `StopNode`, interface `terminal`), and renaming the exception with it would
 * re-merge two ideas that are deliberately separate.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowStop;
use OCA\OpenRegister\Service\Flow\Nodes\EndNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\EndNode
 */
final class EndNodeTest extends TestCase
{

    /**
     * The node under test.
     *
     * @var EndNode
     */
    private EndNode $node;


    /**
     * Build the node with stub collaborators.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $l10n = $this->createMock(originalClassName: IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $this->node = new EndNode($l10n, $this->createMock(originalClassName: IURLGenerator::class));

    }//end setUp()


    /**
     * An end node that received items ends the run, as an error when configured so.
     *
     * The guard test below is only meaningful next to this one: a node that
     * stopped ending anything at all would pass that test and be useless.
     *
     * @return void
     */
    public function testAnEndWithItemsEndsTheRun(): void
    {
        $this->expectException(FlowStop::class);
        $this->expectExceptionMessage('the tip moved');

        $this->node->execute([['json' => ['a' => 1]]], ['error' => true, 'message' => 'the tip moved'], []);

    }//end testAnEndWithItemsEndsTheRun()


    /**
     * An end node on a branch the router did NOT choose passes through.
     *
     * Measured on hydra's commit-by-API flow before this: every step through
     * `move-ref` completed and the branch ref genuinely moved on GitHub, while
     * the run reported `failed` with "the branch tip moved while the commit was
     * being built" — the opposite of what happened, and precisely the failure
     * the rail exists to report. A caller reading the run status would roll back
     * a commit that was correct.
     *
     * @return void
     */
    public function testAnEndOnAnUntakenBranchDoesNotEndTheRun(): void
    {
        $out = $this->node->execute([], ['error' => true, 'message' => 'the tip moved'], []);

        $this->assertSame([], $out);

    }//end testAnEndOnAnUntakenBranchDoesNotEndTheRun()


    /**
     * The same holds for a clean (non-error) end.
     *
     * A clean end on an untaken branch is quieter but no less wrong: it ends
     * the run early, so every step after the router never runs.
     *
     * @return void
     */
    public function testACleanEndOnAnUntakenBranchAlsoPassesThrough(): void
    {
        $out = $this->node->execute([], ['error' => false, 'message' => 'nothing to do'], []);

        $this->assertSame([], $out);

    }//end testACleanEndOnAnUntakenBranchAlsoPassesThrough()
}//end class
