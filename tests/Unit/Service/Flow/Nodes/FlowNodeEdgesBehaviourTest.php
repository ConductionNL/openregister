<?php

/**
 * Edge cases of three flow steps: a switch step passes its items on
 * untouched, a transition step refuses an item it cannot tie to an object
 * without moving anything, and a sub-flow step refuses a config without a
 * flow or with a non-boolean fan-out, and passes items on when run without one.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation;
use OCA\OpenRegister\Service\Flow\Nodes\SubFlowNode;
use OCA\OpenRegister\Service\Flow\Nodes\SwitchNode;
use OCA\OpenRegister\Service\Flow\Nodes\TransitionNode;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

/**
 * SwitchNode, TransitionNode and SubFlowNode edges.
 */
class FlowNodeEdgesBehaviourTest extends TestCase {

	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		return $l10n;
	}//end l10n()

	private function subFlow(FlowRunServiceInterface $runs): SubFlowNode {
		return new SubFlowNode(new \OCA\OpenRegister\Service\Flow\FlowExecution($this->createMock(FlowLocator::class), $runs, new \Psr\Log\NullLogger()), new \OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation($this->l10n(), $this->createMock(IURLGenerator::class)));
	}//end subFlow()

	public function testASwitchStepPassesItsItemsOnUntouched(): void {
		$items = [['json' => ['a' => 1]], ['json' => ['a' => 2]]];

		$this->assertSame($items, (new SwitchNode($this->l10n(), $this->createMock(IURLGenerator::class)))->execute($items, ['cases' => []], []));
	}//end testASwitchStepPassesItsItemsOnUntouched()

	public function testATransitionOfAnItemWithoutAnObjectIsRefusedBeforeAnythingMoves(): void {
		$engine = $this->createMock(TransitionEngine::class);
		$engine->expects($this->never())->method('transition');
		$node = new TransitionNode($engine, new FlowNodePresentation($this->l10n(), $this->createMock(IURLGenerator::class)));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The transition step could not tell which object to move.');

		$node->execute([['json' => ['title' => 'no id']]], ['action' => 'close', 'object' => '  '], []);
	}//end testATransitionOfAnItemWithoutAnObjectIsRefusedBeforeAnythingMoves()

	public function testASubFlowWithoutAFlowIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('A sub-flow step needs a flow to run.');

		$this->subFlow($this->createMock(FlowRunServiceInterface::class))->validateConfig(['flowId' => '  ']);
	}//end testASubFlowWithoutAFlowIsRefused()

	public function testASubFlowWithANonBooleanFanOutIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('The "fanOut" field must be true or false.');

		$this->subFlow($this->createMock(FlowRunServiceInterface::class))->validateConfig(['flow' => 'child', 'fanOut' => 'yes']);
	}//end testASubFlowWithANonBooleanFanOutIsRefused()

	public function testASubFlowRunWithoutAFlowPassesItsItemsOnAndStartsNothing(): void {
		$runs = $this->createMock(FlowRunServiceInterface::class);
		$runs->expects($this->never())->method($this->anything());
		$items = [['json' => ['a' => 1]]];

		$this->assertSame($items, $this->subFlow($runs)->execute($items, [], []));
	}//end testASubFlowRunWithoutAFlowPassesItsItemsOnAndStartsNothing()
}//end class
