<?php

/**
 * The dead-end connectivity check.
 *
 * A node with no outgoing edge that is not terminal produces a run which stops
 * where it arrives and is recorded COMPLETED. Nothing fails, so nothing is
 * logged, and the author sees a green run that did not do the work. These tests
 * pin the warning that makes that visible, and — as much as anything — that it
 * can be ABSENT, because a check which fires on every document is worth as
 * little as one which never fires.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowDeadEnd;
use OCA\OpenRegister\Service\Flow\FlowNodePreflight;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the dead-end warning and the refusal message built from it.
 */
class FlowDeadEndTest extends TestCase
{
    /**
     * Build a preflight whose registry answers `isTerminal` from a fixed list.
     *
     * @param array<int, string> $terminal Types that end a path deliberately.
     *
     * @return FlowNodePreflight
     */
    private function preflight(array $terminal=[]): FlowNodePreflight
    {
        $registry = $this->createMock(FlowNodeRegistry::class);
        $registry->method('get')->willReturn($this->createMock(IFlowNode::class));
        $registry->method('isTerminal')->willReturnCallback(
            static fn (string $type): bool => in_array($type, $terminal, true)
        );

        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isEnabledForUser')->willReturn(true);

        return new FlowNodePreflight($registry, $appManager, $this->createMock(LoggerInterface::class));

    }//end preflight()

    /**
     * Only the dead-end warnings, so an unrelated finding cannot pass a count.
     *
     * @param array $report The preflight report.
     *
     * @return array<int, string> The offending node ids.
     */
    private function deadEnds(array $report): array
    {
        $ids = [];
        foreach ($report['warnings'] as $warning) {
            if (($warning['reason'] ?? '') === FlowNodePreflight::REASON_DEAD_END) {
                $ids[] = $warning['step'];
            }
        }

        return $ids;

    }//end deadEnds()

    /**
     * A node whose token has nowhere to go is reported, by name.
     *
     * @return void
     */
    public function testSinkNodeIsReported(): void
    {
        $report = $this->preflight()->inspect(
            flow: [
                'nodes' => [
                    ['id' => 'a', 'type' => 'openregister.set-fields'],
                    ['id' => 'b', 'type' => 'openregister.set-fields'],
                ],
                'edges' => [['id' => 'e1', 'from' => 'a', 'to' => 'b']],
            ]
        );

        $this->assertSame(['b'], $this->deadEnds($report));
        $this->assertSame([], $report['blocking'], 'A dead end warns; it never blocks a save.');

    }//end testSinkNodeIsReported()

    /**
     * The positive control: the SAME graph, wired, reports nothing.
     *
     * Without this the test above proves only that the method returns a
     * non-empty list — which a check hardcoded to complain would also do.
     *
     * @return void
     */
    public function testWiredGraphIsSilent(): void
    {
        $report = $this->preflight(terminal: ['openregister.stop'])->inspect(
            flow: [
                'nodes' => [
                    ['id' => 'a', 'type' => 'openregister.set-fields'],
                    ['id' => 'b', 'type' => 'openregister.stop'],
                ],
                'edges' => [['id' => 'e1', 'from' => 'a', 'to' => 'b']],
            ]
        );

        $this->assertSame([], $this->deadEnds($report));

    }//end testWiredGraphIsSilent()

    /**
     * A registered terminal TYPE ends a path without needing the flag.
     *
     * @return void
     */
    public function testRegisteredTerminalTypeIsNotADeadEnd(): void
    {
        $report = $this->preflight(terminal: ['openregister.stop'])->inspect(
            flow: [
                'nodes' => [['id' => 'only', 'type' => 'openregister.stop']],
                'edges' => [],
            ]
        );

        $this->assertSame([], $this->deadEnds($report));

    }//end testRegisteredTerminalTypeIsNotADeadEnd()

    /**
     * `exit: true` ends a path without the type being terminal.
     *
     * This is what a migrated flow needs: a sink whose step is an ordinary
     * action, which was a legitimate end of a path under the old reading.
     *
     * @return void
     */
    public function testExitFlagIsHonouredForAnOrdinaryType(): void
    {
        $report = $this->preflight()->inspect(
            flow: [
                'nodes' => [['id' => 'only', 'type' => 'openregister.set-fields', 'exit' => true]],
                'edges' => [],
            ]
        );

        $this->assertSame([], $this->deadEnds($report));

    }//end testExitFlagIsHonouredForAnOrdinaryType()

    /**
     * The two escapes are OR-ed, never AND-ed.
     *
     * A terminal type with no flag, and a flag with no terminal type, each
     * suffice on their own — proven by the two tests above. Here the inverse:
     * an ordinary type with no flag is NOT excused by the presence of a
     * terminal node elsewhere in the document.
     *
     * @return void
     */
    public function testATerminalNodeElsewhereDoesNotExcuseASink(): void
    {
        $report = $this->preflight(terminal: ['openregister.stop'])->inspect(
            flow: [
                'nodes' => [
                    ['id' => 'a', 'type' => 'openregister.set-fields'],
                    ['id' => 'ends', 'type' => 'openregister.stop'],
                    ['id' => 'forgotten', 'type' => 'openregister.set-fields'],
                ],
                'edges' => [['id' => 'e1', 'from' => 'a', 'to' => 'ends']],
            ]
        );

        $this->assertSame(['forgotten'], $this->deadEnds($report));

    }//end testATerminalNodeElsewhereDoesNotExcuseASink()

    /**
     * A typeless node is left to the builder, which refuses it by name.
     *
     * Two findings on one node for one defect is how a warning list becomes
     * noise, so this check stays out of the builder's way.
     *
     * @return void
     */
    public function testTypelessNodeIsNotDoubleReported(): void
    {
        $report = $this->preflight()->inspect(
            flow: [
                'nodes' => [['id' => 'a', 'type' => 'openregister.set-fields'], ['id' => 'b']],
                'edges' => [['id' => 'e1', 'from' => 'a', 'to' => 'b']],
            ]
        );

        $this->assertSame([], $this->deadEnds($report));

    }//end testTypelessNodeIsNotDoubleReported()

    /**
     * A node with an outgoing edge is never a dead end, however many arrive.
     *
     * @return void
     */
    public function testConvergingNodeWithAnExitIsFine(): void
    {
        $report = $this->preflight(terminal: ['openregister.stop'])->inspect(
            flow: [
                'nodes' => [
                    ['id' => 'a', 'type' => 'openregister.set-fields'],
                    ['id' => 'b', 'type' => 'openregister.set-fields'],
                    ['id' => 'join', 'type' => 'openregister.set-fields'],
                    ['id' => 'end', 'type' => 'openregister.stop'],
                ],
                'edges' => [
                    ['id' => 'e1', 'from' => 'a', 'to' => 'join'],
                    ['id' => 'e2', 'from' => 'b', 'to' => 'join'],
                    ['id' => 'e3', 'from' => 'join', 'to' => 'end'],
                ],
            ]
        );

        $this->assertSame([], $this->deadEnds($report));

    }//end testConvergingNodeWithAnExitIsFine()

    /**
     * The refusal names every offending node, so the author can act on it.
     *
     * @return void
     */
    public function testRefusalMessageNamesTheNodes(): void
    {
        $one = new FlowDeadEnd(nodeIds: ['forgotten']);
        $this->assertStringContainsString('"forgotten"', $one->getMessage());
        $this->assertStringContainsString('node "forgotten" has', $one->getMessage());
        $this->assertSame(['forgotten'], $one->getNodeIds());

        $many = new FlowDeadEnd(nodeIds: ['a', 'b']);
        $this->assertStringContainsString('"a", "b"', $many->getMessage());
        $this->assertStringContainsString('have', $many->getMessage());

    }//end testRefusalMessageNamesTheNodes()
}//end class
